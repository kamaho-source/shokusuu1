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

    test('最初は朝食を選んだ状態。選んだ食事が登録済みの日は🔒で選べない', () => {
        setup();
        expect(document.querySelector('[data-meal="1"]').getAttribute('aria-pressed')).toBe('true');
        expect(submitBtn().disabled).toBe(true);
        expect(cell('2026-10-13').classList.contains('lock')).toBe(true);
        click(cell('2026-10-13'));
        expect(window.CybozuMode.state.selected[1]['2026-10-13']).toBeUndefined();
        click(cell('2026-10-14'));
        expect(submitBtn().disabled).toBe(false);
        expect(submitBtn().textContent).toContain('1件');
    });

    test('食事を切り替えても、他の食事で選んだ日は残り、合計の件数で登録できる', () => {
        setup();
        click(cell('2026-10-20'));
        click(cell('2026-10-21'));
        meal(3);
        expect(cell('2026-10-20').classList.contains('sel')).toBe(false); // 夕食ではまだ選んでいない
        expect(cell('2026-10-20').querySelector('.cybozu-picks').textContent).toBe('朝');
        click(cell('2026-10-20'));
        expect(cell('2026-10-20').querySelector('.cybozu-picks').textContent).toBe('朝夜');
        expect(window.CybozuMode.state.selected).toEqual({ 1: { '2026-10-20': 1, '2026-10-21': 1 }, 2: {}, 3: { '2026-10-20': 1 } });
        expect(submitBtn().textContent).toContain('3件');
        expect(document.getElementById('cybozuSummary').textContent).toContain('朝食 2日・🌙 夕食 1日');
        expect(document.querySelector('[data-meal="1"]').textContent).toContain('2日');
        // チップの×は、その食事の分だけ外す
        click(document.querySelector('[data-remove="2026-10-20"][data-remove-meal="1"]'));
        expect(window.CybozuMode.state.selected[1]).toEqual({ '2026-10-21': 1 });
        expect(window.CybozuMode.state.selected[3]).toEqual({ '2026-10-20': 1 });
    });

    test('昼食は、弁当が登録済みの日（別の部屋）も選べない', () => {
        setup();
        meal(2);
        expect(cell('2026-10-15').classList.contains('lock')).toBe(true);
        click(cell('2026-10-15'));
        expect(window.CybozuMode.state.selected[2]).toEqual({});
    });

    test('過去日は選べない。部屋を切り替えると選択中の日を付け替える', () => {
        setup();
        meal(3);
        click(cell('2026-10-09'));
        expect(window.CybozuMode.state.selected[3]['2026-10-09']).toBeUndefined();

        click(cell('2026-10-20'));
        click(cell('2026-10-21'));
        room(2);
        click(cell('2026-10-21'));
        expect(window.CybozuMode.state.selected[3]).toEqual({ '2026-10-20': 1, '2026-10-21': 2 });
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
        meal(3);
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
        expect(Object.keys(window.CybozuMode.state.selected[3]).length).toBe(20);
        expect(window.pageToast).toHaveBeenCalledWith('選べる日付は、1つの食事につき最大20日です。', 'warning');
    });

    test('月は今月から2か月先まで。前の月には戻れない', () => {
        setup();
        expect(document.getElementById('cybozuPrev').disabled).toBe(true);
        click(document.getElementById('cybozuNext'));
        click(document.getElementById('cybozuNext'));
        expect(document.getElementById('cybozuMonth').textContent).toBe('2026年12月');
        expect(document.getElementById('cybozuNext').disabled).toBe(true);
    });

    test('登録: 日付と部屋ごとに、複数の食事をまとめて順番に送る。再読込せず、🔒と背景のカレンダーに反映する', async () => {
        setup();
        window.__applyReservationToCalendar = jest.fn();
        window.__reservationCalendar = { refetchEvents: jest.fn() };
        mockFetch((b) => ({ status: 200, json: { ok: true, data: { registered: b.date === '2026-10-21' ? [] : b.meals, skipped: b.date === '2026-10-21' ? b.meals : [] } } }));
        click(cell('2026-10-20'));      // 朝食・ナザレ
        meal(3);
        click(cell('2026-10-20'));      // 夕食・ナザレ
        room(2);
        click(cell('2026-10-21'));      // 夕食・ガリラヤ

        const pending = window.CybozuMode.submit();
        await Promise.resolve();
        expect(submitBtn().textContent).toContain('登録中…（1/2）');
        await pending;
        // 背景カレンダーの再取得は最後の1回だけ
        expect(window.__reservationCalendar.refetchEvents).toHaveBeenCalledTimes(1);

        expect(global.fetch.mock.calls.map((c) => JSON.parse(c[1].body))).toEqual([
            { date: '2026-10-20', roomId: 1, meals: [1, 3], userId: 9, noSwap: true },
            { date: '2026-10-21', roomId: 2, meals: [3], userId: 9, noSwap: true },
        ]);
        expect(window.pageToast).toHaveBeenLastCalledWith('登録 2件（朝食 10/20（火）／夕食 10/20（火））。スキップ 1件（夕食 10/21（水）：登録済み、または昼食とお弁当が重なる日）', 'warning');
        expect(window.__applyReservationToCalendar.mock.calls).toEqual([['2026-10-20', 1, 1, true], ['2026-10-20', 3, 1, true]]);
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
        expect(window.CybozuMode.state.selected).toEqual({ 1: {}, 2: {}, 3: {} });
        expect(submitBtn().textContent).toContain('0件');
        // スキップした日は、もう一度選べないよう🔒にする
        expect(cell('2026-10-21').classList.contains('lock')).toBe(true);
        // 結果はトーストが消えても読めるよう、モーダル内にも残す
        expect(document.getElementById('cybozuResult').hidden).toBe(false);
        expect(document.getElementById('cybozuResult').textContent).toContain('登録 2件');
        delete window.__applyReservationToCalendar;
        delete window.__reservationCalendar;
    });

    test('登録: 失敗した分だけを選択に残し、登録できた分は🔒にする', async () => {
        setup();
        mockFetch((b) => (b.date === '2026-10-21'
            ? { status: 409, json: { ok: false, message: '他の操作と競合しました。' } }
            : { status: 200, json: { ok: true, data: { registered: b.meals, skipped: [] } } }));
        click(cell('2026-10-20'));
        click(cell('2026-10-21'));
        meal(3);
        click(cell('2026-10-21'));

        await window.CybozuMode.submit();

        expect(window.CybozuMode.state.selected).toEqual({ 1: { '2026-10-21': 1 }, 2: {}, 3: { '2026-10-21': 1 } });
        meal(1);
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('失敗 2件（朝食 10/21（水）／夕食 10/21（水））：他の操作と競合しました。'), 'danger');
        expect(submitBtn().textContent).toContain('2件');
    });

    test('通信に失敗したときは定型文を出す', async () => {
        setup();
        global.fetch = jest.fn(() => Promise.reject(new Error('offline')));
        click(cell('2026-10-20'));

        await window.CybozuMode.submit();

        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('通信に失敗しました'), 'danger');
    });

    test('部屋名に HTML を含んでも、タグとして解釈しない', () => {
        setup({ pickerRooms: [{ id: 1, name: '<img src=x onerror=alert(1)>' }, { id: 2, name: 'B' }] });
        expect(document.querySelector('#cybozuRooms img')).toBeNull();
    });

    test('登録: 途中の食事だけ失敗したとき（data.failed）、先に登録できた食事は登録済みとして扱う', async () => {
        setup();
        mockFetch(() => ({ status: 200, json: { ok: true, data: { registered: [1], skipped: [], failed: [{ meal: 3, message: '承認済みの予約は変更できません。' }] } } }));
        click(cell('2026-10-20'));
        meal(3);
        click(cell('2026-10-20'));

        await window.CybozuMode.submit();

        expect(window.CybozuMode.state.selected).toEqual({ 1: {}, 2: {}, 3: { '2026-10-20': 1 } });
        expect(window.pageToast).toHaveBeenLastCalledWith('登録 1件（朝食 10/20（火））。失敗 1件（夕食 10/20（火））：承認済みの予約は変更できません。', 'danger');
        meal(1);
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
    });

    test('409 でも競合モーダルを出す fetch を通さず、理由をそのまま出す', async () => {
        setup();
        global.fetch = jest.fn(() => Promise.reject(new Error('conflict modal wrapper')));
        window.__fetchWithoutConflictModal = jest.fn(() => Promise.resolve({ status: 409, text: () => Promise.resolve(JSON.stringify({ ok: false, message: '他の操作と競合しました。' })) }));
        click(cell('2026-10-20'));

        await window.CybozuMode.submit();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('他の操作と競合しました。'), 'danger');
        delete window.__fetchWithoutConflictModal;
    });

    test('背景への反映で例外が出ても、登録できた日の🔒と選択の解除は続ける（二重に送らない）', async () => {
        setup();
        mockFetch((b) => ({ status: 200, json: { ok: true, data: { registered: b.meals, skipped: [] } } }));
        window.__applyReservationToCalendar = () => { throw new Error('boom'); };
        click(cell('2026-10-20'));
        click(cell('2026-10-21'));

        await window.CybozuMode.submit();

        expect(window.CybozuMode.state.selected[1]).toEqual({});
        expect(cell('2026-10-21').classList.contains('lock')).toBe(true);
        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('登録 2件'), 'success');
        delete window.__applyReservationToCalendar;
    });

    test('登録の途中で想定外の例外が出ても「登録中…」のまま固まらない', async () => {
        setup();
        mockFetch((b) => ({ status: 200, json: { ok: true, data: { registered: b.meals, skipped: [] } } }));
        window.__reservationCalendar = { refetchEvents: () => { throw new Error('boom'); } };
        click(cell('2026-10-20'));

        await window.CybozuMode.submit();

        expect(window.CybozuMode.state.busy).toBe(false);
        expect(window.CybozuMode.state.selected[1]).toEqual({});
        expect(window.pageToast).toHaveBeenLastCalledWith(expect.stringContaining('エラーが発生しました'), 'danger');
        delete window.__reservationCalendar;
    });

    test('開き直すと、スキップの🔒と前回の結果欄を外し、次の登録の開始時にも結果欄を隠す', async () => {
        setup();
        mockFetch(() => ({ status: 200, json: { ok: true, data: { registered: [], skipped: [1] } } }));
        click(cell('2026-10-20'));
        await window.CybozuMode.submit();
        expect(cell('2026-10-20').classList.contains('lock')).toBe(true);
        expect(document.getElementById('cybozuResult').hidden).toBe(false);

        document.getElementById('cybozuModal').dispatchEvent(new Event('shown.bs.modal'));
        expect(cell('2026-10-20').classList.contains('lock')).toBe(false);
        expect(document.getElementById('cybozuResult').hidden).toBe(true);
        click(cell('2026-10-20'));
        const pending = window.CybozuMode.submit();
        expect(document.getElementById('cybozuResult').hidden).toBe(true);
        await pending;
    });

    test('月の外の日（灰色）は選べない', () => {
        setup();
        click(document.getElementById('cybozuNext')); // 2026年11月。末尾に 12/1〜12/5 が灰色で並ぶ
        const outside = Array.from(document.querySelectorAll('#cybozuCalendar tbody td')).filter((td) => !td.dataset.date);
        expect(outside.map((td) => td.querySelector('.cybozu-num').textContent)).toEqual(['1', '2', '3', '4', '5']);
        click(outside[4]);
        expect(window.CybozuMode.state.selected[1]).toEqual({});
    });

    test('開き直したときに描き直し、閉じている間に背景で登録された日も🔒にする', () => {
        setup();
        expect(cell('2026-10-22').classList.contains('lock')).toBe(false);
        window.__TRESP.myDetails['2026-10-22'] = { breakfast: 1, breakfastRoom: 1 };
        document.getElementById('cybozuModal').dispatchEvent(new Event('shown.bs.modal'));
        expect(cell('2026-10-22').classList.contains('lock')).toBe(true);
    });

    test('キーボードで日付を選んでも、フォーカスが同じ日付に残る', () => {
        setup();
        cell('2026-10-20').focus();
        cell('2026-10-20').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(window.CybozuMode.state.selected[1]).toEqual({ '2026-10-20': 1 });
        expect(document.activeElement).toBe(cell('2026-10-20'));
        expect(cell('2026-10-20').getAttribute('aria-pressed')).toBe('true');
    });

    test('児童は子どもUIと同じく、今日から1か月後の前日までしか選べない', () => {
        setup({ isChild: true });
        click(document.getElementById('cybozuNext'));
        click(cell('2026-11-09'));
        click(cell('2026-11-10')); // 10/10 の1か月後の前日＝11/9 まで
        expect(window.CybozuMode.state.selected[1]).toEqual({ '2026-11-09': 1 });
        expect(cell('2026-11-10').classList.contains('past')).toBe(true);
        expect(document.getElementById('cybozuNext').disabled).toBe(true); // 11月より先には進めない
    });

    test('読み上げ用の名前に、予約状況と他の食事の選択を入れる', () => {
        setup();
        click(cell('2026-10-20'));
        meal(3);
        expect(cell('2026-10-20').getAttribute('aria-label')).toBe('10月20日 夕食。予約 朝× 昼× 夜× 弁×。選択中の食事 朝食');
    });
});
