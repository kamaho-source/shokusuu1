'use strict';

const path = require('path');
const fs = require('fs');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../webroot/js/bulk_add_form.js'),
    'utf8'
);

const DATES = ['2026-10-05', '2026-10-06'];

function buildDom() {
    document.body.innerHTML = `
        <div class="excel-header">
            <input type="search">
            <button id="copy-day-btn"></button>
            <select id="room-select"><option value="">-</option><option value="7">A</option></select>
            <div id="room-select-help"></div>
            <select id="user-filter-select"><option value="all">all</option></select>
            <button id="save-btn"></button>
            <span id="dirty-badge"></span>
        </div>
        <div class="tab-day">
            ${DATES.map((d, i) => `<button class="btn ${i === 0 ? 'active' : ''}" data-date="${d}" data-disabled="0">${d}</button>`).join('')}
        </div>
        <strong id="active-date-label"></strong>
        <span id="count-morning"></span><span id="count-noon"></span>
        <span id="count-night"></span><span id="count-bento"></span>
        <input type="checkbox" class="bulk-toggle" data-type="1">
        <input type="checkbox" class="bulk-toggle" data-type="2">
        <input type="checkbox" class="bulk-toggle" data-type="3">
        <input type="checkbox" class="bulk-toggle" data-type="4">
        <form id="reservation-form">
            <input type="hidden" id="i_id_room">
            <div id="selection-inputs"></div>
            <table><tbody id="user-rows"></tbody></table>
        </form>
        <button id="pager-prev"></button><span id="pager-info"></span><button id="pager-next"></button>
        <div class="week-tabs"><a href="/w1">1</a></div>
    `;
}

function payload() {
    return {
        users: [
            { id: 11, name: '職員A', i_user_level: 0, is_staff: true },
            { id: 12, name: '職員B', i_user_level: 0, is_staff: true },
        ],
        reservations: {},
        other_room_reservations: {},
        total: 2,
        page: 1,
        limit: 100,
        reservation_snapshot: '2026-10-01 00:00:00',
    };
}

async function boot() {
    buildDom();
    window.__BASE_PATH = '';
    window.__SELECTED_DATE = DATES[0];
    window.__ROOM_ID = '7';
    window.__BASE_WEEK = DATES[0];
    window.__LOGIN_USER_ID = 1;
    window.__LOGIN_USER_LEVEL = 0;
    window.__IS_ADMIN = true;
    window.__IS_BLOCK_LEADER = false;
    window.__LOGIN_ROOM_IDS = [7];

    global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve(payload()) }));

    // スクリプトは DOMContentLoaded 内で初期化する。イベントを dispatch すると
    // 前テストで読み込んだインスタンスも再初期化されてしまうため、
    // 登録されたハンドラだけを横取りして今回の1本だけ実行する。
    let init = null;
    const origAdd = document.addEventListener.bind(document);
    document.addEventListener = (type, fn, ...rest) => {
        if (type === 'DOMContentLoaded') {
            init = fn;
            return;
        }
        origAdd(type, fn, ...rest);
    };
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', SRC)(global.window, global.document);
    document.addEventListener = origAdd;
    init();
    await flush();
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const cell = (uid, type) =>
    document.querySelector(`.meal-toggle[data-uid="${uid}"][data-type="${type}"]`);

const statusOf = (uid) =>
    cell(uid, 1).closest('tr').lastElementChild.textContent.trim();

async function check(uid, type, checked) {
    const cb = cell(uid, type);
    cb.checked = checked;
    cb.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
}

describe('bulk_add_form.js', () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
        jest.resetAllMocks();
    });

    test('チェックしても行の DOM は作り直されない（同一 input 要素が生き続ける）', async () => {
        await boot();
        const before = cell(11, 1);
        await check(11, 1, true);
        expect(cell(11, 1)).toBe(before);
        expect(cell(11, 1).checked).toBe(true);
    });

    test('STATUS セルが選択状態に追従する（昼↔弁当が排他なので FULL MEAL にはならない）', async () => {
        await boot();
        for (const t of [1, 2, 3]) {
            await check(11, t, true);
        }
        expect(statusOf(11)).toBe('');
        expect(statusOf(12)).toBe('');

        await check(11, 3, false);
        expect(statusOf(11)).toBe('');
    });

    test('昼(2)を選ぶと弁当(4)のチェックが外れる', async () => {
        await boot();
        await check(11, 4, true);
        expect(cell(11, 4).checked).toBe(true);

        await check(11, 2, true);
        expect(cell(11, 4).checked).toBe(false);
        expect(cell(11, 2).checked).toBe(true);
    });

    test('曜日タブを往復しても取得済みの日は再取得しない', async () => {
        await boot();
        expect(fetch).toHaveBeenCalledTimes(1);

        const [day1, day2] = Array.from(document.querySelectorAll('.tab-day .btn'));
        day2.click();
        await flush();
        expect(fetch).toHaveBeenCalledTimes(2); // 未取得の日は取りに行く

        day1.click();
        await flush();
        day2.click();
        await flush();
        expect(fetch).toHaveBeenCalledTimes(2); // 取得済みならキャッシュから描画
    });

    test('曜日ごとに選択状態が独立している', async () => {
        await boot();
        await check(11, 1, true);

        const [day1, day2] = Array.from(document.querySelectorAll('.tab-day .btn'));
        day2.click();
        await flush();
        expect(cell(11, 1).checked).toBe(false);

        day1.click();
        await flush();
        expect(cell(11, 1).checked).toBe(true);
    });
});
