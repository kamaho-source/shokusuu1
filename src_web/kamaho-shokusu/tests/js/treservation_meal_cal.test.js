'use strict';

const path = require('path');
const fs   = require('fs');

// ----------------------------------------------------------------
// ヘルパー: jsdom 上でスクリプトを実行し window グローバルを汚染しない
// ----------------------------------------------------------------
function loadScript() {
    const src = fs.readFileSync(
        path.resolve(__dirname, '../../webroot/js/pages/treservation_meal_cal.js'),
        'utf8'
    );
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', src)(global, global.document);
}

// ----------------------------------------------------------------
// DOM セットアップヘルパー
// ----------------------------------------------------------------
function setupModalDom() {
    document.body.innerHTML = `
        <div id="mealCalUserModal"></div>
        <span id="mealCalModalDateLabel"></span>
        <div id="mealCalModalLoading" class="d-none"></div>
        <div id="mealCalModalContent" class="d-none"></div>
    `;
}

describe('treservation_meal_cal.js', () => {
    beforeEach(() => {
        // bootstrap モック
        global.window.bootstrap = {
            Modal: {
                getOrCreateInstance: jest.fn(() => ({ show: jest.fn() })),
            },
        };
        global.window.GET_USERS_BY_ROOM_TPL = '/api/rooms/__RID__/users';
        setupModalDom();
        loadScript();
    });

    afterEach(() => {
        jest.resetAllMocks();
        delete global.window.openMealCalUserModal;
        delete global.window.GET_USERS_BY_ROOM_TPL;
        delete global.window.bootstrap;
    });

    // ----------------------------------------------------------------
    // openMealCalUserModal: roomId なし
    // ----------------------------------------------------------------
    describe('openMealCalUserModal – roomId なし', () => {
        test('日付ラベルが設定される', () => {
            window.openMealCalUserModal('2026-06-18', '');
            expect(document.getElementById('mealCalModalDateLabel').textContent)
                .toBe('2026-06-18 の食数詳細');
        });

        test('コンテンツにフィルタ案内メッセージが表示される', () => {
            window.openMealCalUserModal('2026-06-18', '');
            const content = document.getElementById('mealCalModalContent');
            expect(content.classList.contains('d-none')).toBe(false);
            expect(content.innerHTML).toContain('部屋フィルタを選択すると');
        });

        test('ローディングが非表示になる', () => {
            window.openMealCalUserModal('2026-06-18', null);
            expect(document.getElementById('mealCalModalLoading').classList.contains('d-none')).toBe(true);
        });
    });

    // ----------------------------------------------------------------
    // openMealCalUserModal: fetch 成功
    // ----------------------------------------------------------------
    describe('openMealCalUserModal – fetch 成功', () => {
        function mockFetch(usersByRoom) {
            global.fetch = jest.fn(() =>
                Promise.resolve({
                    json: () => Promise.resolve({ ok: true, data: { usersByRoom } }),
                })
            );
        }

        test('fetch が正しい URL で呼ばれる', async () => {
            mockFetch([]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/rooms/10/users?date=2026-06-18',
                expect.objectContaining({ headers: { Accept: 'application/json' } })
            );
        });

        test('朝食ユーザーがチップとして表示される', async () => {
            mockFetch([{ name: '田中 太郎', morning: true, noon: false, night: false, bento: false }]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            const html = document.getElementById('mealCalModalContent').innerHTML;
            expect(html).toContain('田中 太郎');
            expect(html).toContain('朝食');
        });

        test('昼食・夕食・弁当のラベルがすべて出力される', async () => {
            mockFetch([
                { name: 'A', morning: false, noon: true,  night: false, bento: false },
                { name: 'B', morning: false, noon: false, night: true,  bento: false },
                { name: 'C', morning: false, noon: false, night: false, bento: true  },
            ]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            const html = document.getElementById('mealCalModalContent').innerHTML;
            expect(html).toContain('昼食');
            expect(html).toContain('夕食');
            expect(html).toContain('弁当');
        });

        test('ユーザーが 0 名の食事種別は「なし」と表示される', async () => {
            mockFetch([]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            const html = document.getElementById('mealCalModalContent').innerHTML;
            expect(html).toContain('なし');
        });

        // escHtml の動作を buildModalContent 経由で検証する
        test('ユーザー名の HTML 特殊文字がエスケープされる', async () => {
            mockFetch([{ name: '<script>alert(1)</script>', morning: true, noon: false, night: false, bento: false }]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            const html = document.getElementById('mealCalModalContent').innerHTML;
            expect(html).not.toContain('<script>');
            expect(html).toContain('&lt;script&gt;');
        });

        test('fetch 完了後にローディングが非表示になる', async () => {
            mockFetch([]);
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            expect(document.getElementById('mealCalModalLoading').classList.contains('d-none')).toBe(true);
        });
    });

    // ----------------------------------------------------------------
    // openMealCalUserModal: fetch 失敗
    // ----------------------------------------------------------------
    describe('openMealCalUserModal – fetch 失敗', () => {
        test('エラーメッセージが表示される', async () => {
            global.fetch = jest.fn(() => Promise.reject(new Error('network error')));
            window.openMealCalUserModal('2026-06-18', '10');
            await new Promise(resolve => setTimeout(resolve, 0));
            const content = document.getElementById('mealCalModalContent');
            expect(content.classList.contains('d-none')).toBe(false);
            expect(content.innerHTML).toContain('失敗しました');
        });
    });

    // ----------------------------------------------------------------
    // getUsersByRoomUrl: テンプレートが未設定の場合
    // ----------------------------------------------------------------
    describe('openMealCalUserModal – GET_USERS_BY_ROOM_TPL 未設定', () => {
        test('fetch が呼ばれない', () => {
            global.fetch = jest.fn();
            delete global.window.GET_USERS_BY_ROOM_TPL;
            // テンプレートなしでロードし直す
            loadScript();
            window.openMealCalUserModal('2026-06-18', '10');
            expect(global.fetch).not.toHaveBeenCalled();
        });
    });
});
