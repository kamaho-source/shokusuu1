'use strict';

const path = require('path');
const fs   = require('fs');

const ROOT = path.resolve(__dirname, '../..');

function loadModalHtml() {
    // テンプレート先頭の PHP ブロックを除いて、モーダルの HTML だけを使う
    const src = fs.readFileSync(path.join(ROOT, 'templates/element/TReservationInfo/cybozu_modal.php'), 'utf8');
    return src.replace(/<\?php[\s\S]*?\?>/g, '');
}

function loadScript() {
    const src = fs.readFileSync(path.join(ROOT, 'webroot/js/pages/treservation_cybozu.js'), 'utf8');
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', src)(global.window, global.document);
}

function setup(overrides = {}) {
    document.body.innerHTML = '<a id="cybozuModeButton" href="#">サイボウズモード</a>' + loadModalHtml();
    window.__TRESP = Object.assign({
        isCybozu: true,
        isAdmin: false,
        userId: 9,
        serverToday: '2026-10-10',
        directRegisterUrl: '/TReservationInfo/direct-register',
        csrfToken: 't',
        calRoomId: null,
        roomId: 1,
        pickerRooms: [{ id: 1, name: 'ナザレ' }, { id: 2, name: 'ガリラヤ' }],
        roomNames: {},
        myDetails: {
            '2026-10-13': { breakfast: 1, breakfastRoom: 1, lunch: 0, dinner: 0, bento: 0 },
            '2026-10-15': { breakfast: 0, lunch: 0, dinner: 1, dinnerRoom: 2, bento: 1, bentoRoom: 2 },
        },
    }, overrides);
    window.pageToast = jest.fn();
    window.bootstrap = { Modal: { getOrCreateInstance: () => ({ show: jest.fn(), hide: jest.fn() }) } };
    loadScript();
    window.CybozuMode.init();
    return window.CybozuMode;
}

const cell = (date) => document.querySelector(`td[data-date="${date}"]`);
const click = (el) => el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
const meal = (id) => click(document.querySelector(`[data-meal="${id}"]`));
const room = (id) => click(document.querySelector(`[data-room="${id}"]`));
const submitBtn = () => document.getElementById('cybozuSubmit');

function mockFetch(handler) {
    global.fetch = jest.fn((url, opts) => {
        const body = JSON.parse(opts.body);
        const { status, json } = handler(body);
        return Promise.resolve({ status, text: () => Promise.resolve(JSON.stringify(json)) });
    });
}

describe('treservation_cybozu.js 純粋関数', () => {
    beforeEach(() => { document.body.innerHTML = ''; window.__TRESP = {}; loadScript(); });

    test('lockReason: 選んだ食事が登録済みなら registered、昼食は弁当とも重ならない', () => {
        const { lockReason } = window.CybozuMode;
        expect(lockReason({ breakfast: 1 }, 1)).toBe('registered');
        expect(lockReason({ breakfast: 0, lunch: 0, bento: 1 }, 2)).toBe('bento');
        expect(lockReason({ bento: 1 }, 3)).toBeNull();
        expect(lockReason(undefined, 1)).toBeNull();
    });

    test('monthCells: 日曜始まりで、月の外の日は inMonth=false', () => {
        const weeks = window.CybozuMode.monthCells(2026, 9); // 2026年10月（1日は木曜）
        expect(weeks[0][0]).toEqual({ date: '2026-09-27', inMonth: false, dow: 0 });
        expect(weeks[0][4]).toEqual({ date: '2026-10-01', inMonth: true, dow: 4 });
        expect(weeks.length).toBe(5);
    });

    test('isWithin14: 今日〜14日後だけ true', () => {
        const { isWithin14 } = window.CybozuMode;
        expect(isWithin14('2026-10-10', '2026-10-10')).toBe(true);
        expect(isWithin14('2026-10-24', '2026-10-10')).toBe(true);
        expect(isWithin14('2026-10-25', '2026-10-10')).toBe(false);
        expect(isWithin14('2026-10-09', '2026-10-10')).toBe(false);
    });
});

describe('treservation_cybozu.js 画面', () => {
    afterEach(() => { delete global.fetch; jest.useRealTimers(); });

    test('食事を選ぶまで登録できない。選んだ食事が登録済みの日は🔒で選べない', () => {
        setup();
        click(cell('2026-10-14'));
        expect(submitBtn().disabled).toBe(true);
        expect(document.getElementById('cybozuSummary').textContent).toContain('食事を選んでください');

        meal(1);
        expect(cell('2026-10-13').classList.contains('lock')).toBe(true);
        click(cell('2026-10-13'));
        expect(window.CybozuMode.state.selected['2026-10-13']).toBeUndefined();
        expect(submitBtn().disabled).toBe(false);
        expect(submitBtn().textContent).toContain('1件');
    });

    test('昼食を選ぶと、弁当が登録済みの日（別の部屋）も選べない。選択中だった日は外してトーストで知らせる', () => {
        setup();
        click(cell('2026-10-15'));
        meal(2);
        expect(cell('2026-10-15').classList.contains('lock')).toBe(true);
        expect(window.CybozuMode.state.selected['2026-10-15']).toBeUndefined();
        expect(window.pageToast).toHaveBeenCalledWith(expect.stringContaining('選択から外しました'), 'warning');
    });

    test('過去日は選べない。部屋を切り替えると選択中の日を付け替え、部屋ごとの件数を出す', () => {
        setup();
        meal(3);
        click(cell('2026-10-09'));
        expect(window.CybozuMode.state.selected['2026-10-09']).toBeUndefined();

        click(cell('2026-10-20'));
        click(cell('2026-10-21'));
        room(2);
        click(cell('2026-10-21'));
        expect(window.CybozuMode.state.selected).toEqual({ '2026-10-20': 1, '2026-10-21': 2 });
        expect(document.getElementById('cybozuSummary').textContent).toContain('ナザレ 1日・ガリラヤ 1日');
    });

    test('所属部屋が1つなら、部屋の切替を出さない', () => {
        setup({ pickerRooms: [{ id: 1, name: 'ナザレ' }] });
        expect(document.getElementById('cybozuRoomRow').hidden).toBe(true);
    });

    test('所属部屋が無いときは、赤字とトーストで知らせ、登録できない', () => {
        setup({ pickerRooms: [] });
        expect(document.getElementById('cybozuSummary').textContent).toContain('部屋を選択してください');
        expect(window.pageToast).toHaveBeenCalledWith(expect.stringContaining('部屋を選択してください'), 'warning');
        expect(submitBtn().disabled).toBe(true);
    });

    test('直前期間の注意は、直前期間の日を選んだときだけ出す。管理者には出さない', () => {
        setup();
        meal(1);
        click(cell('2026-10-30'));
        expect(document.getElementById('cybozuLastMinute').hidden).toBe(true);
        click(cell('2026-10-20'));
        expect(document.getElementById('cybozuLastMinute').hidden).toBe(false);

        setup({ isAdmin: true });
        meal(1);
        click(cell('2026-10-20'));
        expect(document.getElementById('cybozuLastMinute').hidden).toBe(true);
    });

    test('最大20件まで', () => {
        setup();
        meal(3);
        const next = document.getElementById('cybozuNext');
        click(next);
        for (let d = 1; d <= 21; d++) click(cell('2026-11-' + String(d).padStart(2, '0')));
        expect(Object.keys(window.CybozuMode.state.selected).length).toBe(20);
        expect(window.pageToast).toHaveBeenCalledWith('選べる日付は最大20件です。', 'warning');
    });

    test('月は今月から2か月先まで。前の月には戻れない', () => {
        setup();
        expect(document.getElementById('cybozuPrev').disabled).toBe(true);
        click(document.getElementById('cybozuNext'));
        click(document.getElementById('cybozuNext'));
        expect(document.getElementById('cybozuMonth').textContent).toBe('2026年12月');
        expect(document.getElementById('cybozuNext').disabled).toBe(true);
    });

    test('登録: 日付ごとに、その日の部屋で順番に送る。再読込せず、モーダルを開いたまま🔒と背景のカレンダーに反映する', async () => {
        setup();
        const reload = jest.fn();
        Object.defineProperty(window, 'location', { value: { reload }, configurable: true });
        window.__applyReservationToCalendar = jest.fn();
        mockFetch((b) => ({ status: 200, json: { ok: true, data: { registered: b.date === '2026-10-21' ? [] : [1], skipped: b.date === '2026-10-21' ? [1] : [] } } }));
        meal(1);
        click(cell('2026-10-20'));
        room(2);
        click(cell('2026-10-21'));

        const pending = window.CybozuMode.submit();
        await Promise.resolve();
        expect(submitBtn().textContent).toContain('登録中…（1/2）');
        await pending;

        expect(global.fetch.mock.calls.map((c) => JSON.parse(c[1].body))).toEqual([
            { date: '2026-10-20', roomId: 1, meals: [1], userId: 9 },
            { date: '2026-10-21', roomId: 2, meals: [1], userId: 9 },
        ]);
        expect(window.pageToast).toHaveBeenLastCalledWith('朝食：登録 1件（10/20（火））。スキップ 1件（10/21（水）：登録済み、または昼食とお弁当が重なる日）', 'warning');
        expect(window.__applyReservationToCalendar).toHaveBeenCalledWith('2026-10-20', 1, 1);
        expect(window.__applyReservationToCalendar).toHaveBeenCalledTimes(1);
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
        expect(window.CybozuMode.state.selected).toEqual({});
        expect(submitBtn().textContent).toContain('0件');
        // 食事を変えれば、同じ画面のまま続けて登録できる
        meal(3);
        expect(cell('2026-10-20').classList.contains('lock')).toBe(false);
        expect(reload).not.toHaveBeenCalled();
        delete window.__applyReservationToCalendar;
    });

    test('登録: 失敗した日だけを選択に残し、登録できた日は🔒にする', async () => {
        setup();
        mockFetch((b) => (b.date === '2026-10-21'
            ? { status: 409, json: { ok: false, message: '他の操作と競合しました。' } }
            : { status: 200, json: { ok: true, data: { registered: [1], skipped: [] } } }));
        meal(1);
        click(cell('2026-10-20'));
        click(cell('2026-10-21'));

        await window.CybozuMode.submit();

        expect(window.CybozuMode.state.selected).toEqual({ '2026-10-21': 1 });
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('10/21（水） 他の操作と競合しました。'), 'danger');
        expect(submitBtn().textContent).toContain('1件');
    });

    test('通信に失敗したときは定型文を出す', async () => {
        setup();
        global.fetch = jest.fn(() => Promise.reject(new Error('offline')));
        meal(1);
        click(cell('2026-10-20'));

        await window.CybozuMode.submit();

        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('通信に失敗しました'), 'danger');
    });

    test('部屋名に HTML を含んでも、タグとして解釈しない', () => {
        setup({ pickerRooms: [{ id: 1, name: '<img src=x onerror=alert(1)>' }, { id: 2, name: 'B' }] });
        expect(document.querySelector('#cybozuRooms img')).toBeNull();
    });
});
