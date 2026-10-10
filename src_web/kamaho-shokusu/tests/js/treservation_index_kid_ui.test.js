'use strict';

const path = require('path');
const fs = require('fs');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../webroot/js/pages/treservation_index.js'),
    'utf8'
);

const TODAY = '2026-10-10';
const LATER = '2026-10-30';

function buildDom() {
    document.body.innerHTML = `
        <meta name="csrfToken" content="test-csrf-token">
        <div class="mode-bar">
            <span id="kidModeBadge" class="badge text-bg-light">モード：自動判定</span>
            <select id="kidModeSelect" class="form-select form-select-sm">
                <option value="auto" selected>自動（日付に応じて判定）</option>
                <option value="late">直前（常に同意モーダル）</option>
                <option value="normal">通常（即時トグル）</option>
            </select>
        </div>
        <div class="card kid-card" id="card-${TODAY}" data-date="${TODAY}" data-is-last-minute="1"></div>
        <div class="card kid-card" id="card-${LATER}" data-date="${LATER}" data-is-last-minute="0"></div>
    `;
}

/**
 * document.addEventListener('DOMContentLoaded', ...) 内で初期化する実装のため、
 * ハンドラだけを横取りして呼び出す（他ファイルのテストと同じパターン）。
 */
function loadAndInit() {
    let init = null;
    const origAdd = document.addEventListener.bind(document);
    document.addEventListener = (type, fn, ...rest) => {
        // 本体のハンドラ内でさらにDOMContentLoadedを登録している箇所があるため、
        // 最初の1件（本体の初期化ハンドラ）だけを横取りする。
        if (type === 'DOMContentLoaded' && !init) {
            init = fn;
            return;
        }
        origAdd(type, fn, ...rest);
    };
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', SRC)(global.window, global.document);
    document.addEventListener = origAdd;
    init();
}

/**
 * スタッフが「子どもUIプレビュー」（?uimode=kid）で開いたケースを再現する。
 * 実際の権限(isChild)は false だが、kid_section.php はこのとき useKidUI=true として
 * 描画されるため isKidUI は true になる。
 */
function setStaffPreviewTresp() {
    window.__TRESP = {
        isChild: false,
        isKidUI: true,
        todayJs: TODAY,
        todayState: { lunch: false, bento: false },
        myDetails: {},
        currentRoom: '1',
        toggleBase: '/TReservationInfo/toggle/__ROOM__',
        userId: 99,
    };
}

describe('treservation_index.js / 子どもUI(kidMode)のスタッフプレビュー', () => {
    beforeEach(() => {
        buildDom();
        setStaffPreviewTresp();
        global.fetch = jest.fn();
    });

    afterEach(() => {
        jest.resetAllMocks();
        delete window.__TRESP;
    });

    test('isChild=falseでも、モード選択は強制的に"通常"へロックされない', () => {
        loadAndInit();

        const select = document.getElementById('kidModeSelect');
        expect(select.disabled).toBe(false);
        expect(select.value).toBe('auto');
    });

    test('isChild=falseでも、今日（直前）のカードが初期表示で見える', () => {
        loadAndInit();

        const todayCard = document.getElementById(`card-${TODAY}`);
        expect(todayCard.style.display).not.toBe('none');
    });

    test('isChild=falseでも、「直前」モードを選ぶと直前カードのみ表示される', () => {
        loadAndInit();

        const select = document.getElementById('kidModeSelect');
        select.value = 'late';
        select.dispatchEvent(new window.Event('change', { bubbles: true }));

        const todayCard = document.getElementById(`card-${TODAY}`);
        const laterCard = document.getElementById(`card-${LATER}`);
        expect(todayCard.style.display).not.toBe('none');
        expect(laterCard.style.display).toBe('none');
    });
});
