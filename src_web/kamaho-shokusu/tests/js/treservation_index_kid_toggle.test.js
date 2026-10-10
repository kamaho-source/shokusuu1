'use strict';

const path = require('path');
const fs = require('fs');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../webroot/js/pages/treservation_index.js'),
    'utf8'
);

const DATE = '2026-10-30';

function buildDom() {
    document.body.innerHTML = `
        <meta name="csrfToken" content="test-csrf-token">
        <select id="kidModeSelect">
            <option value="auto" selected>auto</option>
            <option value="late">late</option>
            <option value="normal">normal</option>
        </select>
        <span id="kidModeBadge"></span>
        <div class="card kid-card" id="card-${DATE}" data-date="${DATE}" data-is-last-minute="0">
            <a href="javascript:void(0)" class="btn kid-meal-btn"
               data-date="${DATE}" data-meal="1" data-meal-key="breakfast"
               data-has-lunch="0" data-has-bento="0" data-is-last-minute="0" data-is-mine="0"
               data-meal-class="btn-success" data-neutral-class="btn-outline-secondary"></a>
        </div>
    `;
}

/**
 * document.addEventListener('DOMContentLoaded', ...) 内で初期化する実装のため、
 * 最初の1件（本体の初期化ハンドラ）だけを横取りして呼び出す。
 */
function loadAndInit() {
    let init = null;
    const origAdd = document.addEventListener.bind(document);
    document.addEventListener = (type, fn, ...rest) => {
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

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('treservation_index.js / 子どもUIのトグル(callToggle)', () => {
    let fetchMock;

    beforeEach(() => {
        buildDom();
        window.__TRESP = {
            isChild: true,
            isKidUI: true,
            todayJs: '2026-10-10',
            todayState: { lunch: false, bento: false },
            myDetails: {},
            currentRoom: '1',
            toggleBase: '/TReservationInfo/toggle/__ROOM__',
            userId: 4,
        };
        fetchMock = jest.fn(() =>
            Promise.resolve({
                headers: { get: () => 'application/json' },
                status: 200,
                json: () => Promise.resolve({ ok: true, data: { value: true, details: {} } }),
            })
        );
        global.fetch = fetchMock;
        window.fetch = fetchMock;
    });

    afterEach(() => {
        jest.resetAllMocks();
        delete window.__TRESP;
    });

    test('「通常」(直前以外)の予約ボタンをクリックすると、リクエストボディに自分のuserIdが含まれる', async () => {
        loadAndInit();

        const btn = document.querySelector(`.kid-meal-btn[data-date="${DATE}"][data-meal="1"]`);
        btn.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        await flush();
        await flush();

        // window.fetchはスクリプト内部で競合検知用にラップされるため、
        // グローバル参照ではなく自分で保持したモック自体を検証する。
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/TReservationInfo/toggle/1');

        const body = JSON.parse(options.body);
        expect(body).toMatchObject({
            date: DATE,
            meal: 1,
            value: 1,
            userId: 4,
        });
    });
});
