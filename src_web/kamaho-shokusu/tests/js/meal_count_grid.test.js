'use strict';

const path = require('path');
const fs = require('fs');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../webroot/js/pages/meal_count_grid.js'),
    'utf8'
);

// スクリプトは関数をトップレベル宣言するだけなので、テストから触れるよう window に露出させる
const EXPORTS = `
;window.__MCG = {
    mcgBuildCellIndex, mcgGetSiblingCells, mcgInitConflicts,
    mcgSyncConflicts, mcgSyncLunchBento, mcgInitLunchBentoExcl,
    mcgUpdateDailyTotal, mcgApplyHolidays, MEAL
};`;

const DATE = '2026-10-05';

/**
 * rooms: [{roomId, userId, reserved: {meal: bool}}] の1日ぶんのグリッドを作る
 */
function buildGrid(rows) {
    const meals = [1, 2, 3, 4];
    const body = rows.map((r) => `
        <tr data-user-id="${r.userId}" data-room-id="${r.roomId}">
            ${meals.map((m) => `<td class="cell-meal mcg-toggleable"
                data-user-id="${r.userId}" data-room-id="${r.roomId}"
                data-date="${DATE}" data-meal="${m}"
                data-reserved="${r.reserved && r.reserved[m] ? '1' : '0'}"></td>`).join('')}
        </tr>`).join('');

    document.body.innerHTML = `
        <table class="mcg-grid">
            <thead><tr>${meals.map((m) => `<th data-date="${DATE}">x</th>`).join('')}</tr></thead>
            <tbody>
                ${body}
                <tr class="row-daily-total">
                    ${meals.map((m) => `<td data-date="${DATE}" data-meal="${m}"></td>`).join('')}
                </tr>
            </tbody>
        </table>`;
}

function load() {
    window.MCG_CONFIG = { basePath: '', rooms: { 1: 'ナザレの部屋', 2: 'シオンの部屋' } };
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', SRC + EXPORTS)(global.window, global.document);
    return window.__MCG;
}

const cell = (roomId, userId, meal) =>
    document.querySelector(
        `td[data-room-id="${roomId}"][data-user-id="${userId}"][data-meal="${meal}"]`
    );

describe('meal_count_grid.js セル索引', () => {
    let M;
    afterEach(() => { delete window.__MCG; });

    test('mcgGetSiblingCells は同一(ユーザー,日付,食種)の全部屋のセルを返す', () => {
        buildGrid([
            { roomId: 1, userId: 7 },
            { roomId: 2, userId: 7 },
            { roomId: 1, userId: 8 },
        ]);
        M = load();
        M.mcgBuildCellIndex();

        const cells = M.mcgGetSiblingCells('7', DATE, 1);
        expect(cells.map((c) => c.dataset.roomId).sort()).toEqual(['1', '2']);
        expect(M.mcgGetSiblingCells('8', DATE, 1)).toHaveLength(1);
        expect(M.mcgGetSiblingCells('999', DATE, 1)).toHaveLength(0);
    });

    test('索引を作らずに引いても落ちない（空配列を返す）', () => {
        buildGrid([{ roomId: 1, userId: 7 }]);
        M = load();
        expect(M.mcgGetSiblingCells('7', DATE, 1)).toHaveLength(0);
    });

    test('mcgInitConflicts は他部屋で予約済みのセルをロックする', () => {
        buildGrid([
            { roomId: 1, userId: 7, reserved: { 1: true } },
            { roomId: 2, userId: 7 },
        ]);
        M = load();
        M.mcgBuildCellIndex();
        M.mcgInitConflicts();

        const reserved = cell(1, 7, 1);
        const other = cell(2, 7, 1);
        expect(reserved.classList.contains('mcg-cell-conflict')).toBe(false);
        expect(other.classList.contains('mcg-cell-conflict')).toBe(true);
        expect(other.dataset.conflictMsg).toBe('ナザレの部屋で予約済みのため選択できません');
    });

    test('mcgInitConflicts は予約がなければロックしない', () => {
        buildGrid([
            { roomId: 1, userId: 7 },
            { roomId: 2, userId: 7 },
        ]);
        M = load();
        M.mcgBuildCellIndex();
        M.mcgInitConflicts();

        expect(cell(1, 7, 1).classList.contains('mcg-cell-conflict')).toBe(false);
        expect(cell(2, 7, 1).classList.contains('mcg-cell-conflict')).toBe(false);
    });

    test('mcgInitLunchBentoExcl は昼が予約済みなら全部屋の弁当を排他表示にする', () => {
        buildGrid([
            { roomId: 1, userId: 7, reserved: { 2: true } },
            { roomId: 2, userId: 7 },
        ]);
        M = load();
        M.mcgBuildCellIndex();
        M.mcgInitLunchBentoExcl();

        expect(cell(1, 7, 4).classList.contains('mcg-cell-excl')).toBe(true);
        expect(cell(2, 7, 4).classList.contains('mcg-cell-excl')).toBe(true);
        expect(cell(1, 7, 4).dataset.exclMsg).toBe('お昼が登録済みです（クリックで変更可能）');
        // 昼側は排他表示にならない
        expect(cell(1, 7, 2).classList.contains('mcg-cell-excl')).toBe(false);
        // 朝・夕は無関係
        expect(cell(1, 7, 1).classList.contains('mcg-cell-excl')).toBe(false);
    });

    test('mcgUpdateDailyTotal は予約済みセル数を日計行に書く', () => {
        buildGrid([
            { roomId: 1, userId: 7, reserved: { 1: true } },
            { roomId: 1, userId: 8, reserved: { 1: true } },
            { roomId: 1, userId: 9 },
        ]);
        M = load();
        M.mcgBuildCellIndex();

        const totalCell = document.querySelector(`.row-daily-total td[data-meal="1"]`);
        M.mcgUpdateDailyTotal(DATE, 1);
        expect(totalCell.textContent).toBe('2');

        // 0件は空文字
        M.mcgUpdateDailyTotal(DATE, 3);
        expect(document.querySelector(`.row-daily-total td[data-meal="3"]`).textContent).toBe('');
    });

    test('索引の構築量はセル数に比例する（走査が二次にならない）', () => {
        const many = [];
        for (let i = 0; i < 300; i++) many.push({ roomId: 1, userId: 1000 + i });
        buildGrid(many);
        M = load();
        M.mcgBuildCellIndex();

        // 300ユーザー × 4食 = 1200セル、キーは (ユーザー,日付,食種) で1200通り
        expect(document.querySelectorAll('.mcg-toggleable')).toHaveLength(1200);
        expect(M.mcgGetSiblingCells('1299', DATE, 4)).toHaveLength(1);
    });
});
