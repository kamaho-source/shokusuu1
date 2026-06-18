'use strict';

const path = require('path');
const fs   = require('fs');

function loadScript() {
    const src = fs.readFileSync(
        path.resolve(__dirname, '../../webroot/js/pages/treservation_lunch_bento.js'),
        'utf8'
    );
    // eslint-disable-next-line no-new-func
    new Function('window', 'document', src)(global, global.document);
}

describe('treservation_lunch_bento.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        loadScript();
    });

    afterEach(() => {
        jest.resetAllMocks();
        delete global.window.setupLunchBentoPair;
        delete global.window.applyLunchBentoExclusion;
    });

    // ----------------------------------------------------------------
    // setupLunchBentoPair
    // ----------------------------------------------------------------
    describe('setupLunchBentoPair', () => {
        function buildPair({ lunchChecked = false, bentoChecked = false } = {}) {
            document.body.innerHTML = `
                <input type="checkbox" name="reservation[lunch]" id="lunch" ${lunchChecked ? 'checked' : ''}>
                <input type="checkbox" name="reservation[bento]" id="bento" ${bentoChecked ? 'checked' : ''}>
            `;
            window.setupLunchBentoPair(
                'input[name="reservation[lunch]"]',
                'input[name="reservation[bento]"]'
            );
            return {
                lunch: document.getElementById('lunch'),
                bento: document.getElementById('bento'),
            };
        }

        test('初期状態: 両方未チェックなら disabled にならない', () => {
            const { lunch, bento } = buildPair();
            expect(lunch.disabled).toBe(false);
            expect(bento.disabled).toBe(false);
        });

        test('初期状態: 昼食チェック済みなら弁当が disabled になる', () => {
            const { bento } = buildPair({ lunchChecked: true });
            expect(bento.disabled).toBe(true);
        });

        test('初期状態: 弁当チェック済みなら昼食が disabled になる', () => {
            const { lunch } = buildPair({ bentoChecked: true });
            expect(lunch.disabled).toBe(true);
        });

        test('昼食をチェックすると弁当が unchecked かつ disabled になる', () => {
            const { lunch, bento } = buildPair({ bentoChecked: true });
            // 弁当が先にチェックされている状態から昼食をチェック
            bento.disabled = false;
            lunch.checked  = true;
            lunch.dispatchEvent(new Event('change'));
            expect(bento.checked).toBe(false);
            expect(bento.disabled).toBe(true);
        });

        test('弁当をチェックすると昼食が unchecked かつ disabled になる', () => {
            const { lunch, bento } = buildPair({ lunchChecked: true });
            lunch.disabled = false;
            bento.checked  = true;
            bento.dispatchEvent(new Event('change'));
            expect(lunch.checked).toBe(false);
            expect(lunch.disabled).toBe(true);
        });

        test('昼食のチェックを外すと弁当の disabled が解除される', () => {
            const { lunch, bento } = buildPair({ lunchChecked: true });
            expect(bento.disabled).toBe(true);
            lunch.checked = false;
            lunch.dispatchEvent(new Event('change'));
            expect(bento.disabled).toBe(false);
        });

        test('弁当のチェックを外すと昼食の disabled が解除される', () => {
            const { lunch, bento } = buildPair({ bentoChecked: true });
            expect(lunch.disabled).toBe(true);
            bento.checked = false;
            bento.dispatchEvent(new Event('change'));
            expect(lunch.disabled).toBe(false);
        });
    });

    // ----------------------------------------------------------------
    // applyLunchBentoExclusion – 個人予約フォーム (meals[2] / meals[4])
    // ----------------------------------------------------------------
    describe('applyLunchBentoExclusion – 個人予約（add フォーム）', () => {
        function buildAddForm({ lunchChecked = false, bentoChecked = false } = {}) {
            document.body.innerHTML = `
                <form id="add-form">
                    <input type="checkbox" name="meals[2][1]" id="lunch" ${lunchChecked ? 'checked' : ''}>
                    <input type="checkbox" name="meals[4][1]" id="bento" ${bentoChecked ? 'checked' : ''}>
                </form>
            `;
            const scope = document.getElementById('add-form');
            window.applyLunchBentoExclusion(scope);
            return {
                lunch: document.getElementById('lunch'),
                bento: document.getElementById('bento'),
            };
        }

        test('昼食をチェックすると弁当が unchecked になる', () => {
            const { lunch, bento } = buildAddForm({ bentoChecked: true });
            // 弁当が既にチェックされているとき昼食をチェック
            bento.checked = true;
            lunch.checked = true;
            lunch.dispatchEvent(new Event('change'));
            expect(bento.checked).toBe(false);
        });

        test('弁当をチェックすると昼食が unchecked になる', () => {
            const { lunch, bento } = buildAddForm({ lunchChecked: true });
            lunch.checked = true;
            bento.checked = true;
            bento.dispatchEvent(new Event('change'));
            expect(lunch.checked).toBe(false);
        });
    });

    // ----------------------------------------------------------------
    // applyLunchBentoExclusion – 集団予約（users テーブル行）
    // ----------------------------------------------------------------
    describe('applyLunchBentoExclusion – 集団予約（テーブル行）', () => {
        function buildGroupRow({ lunchChecked = false, bentoChecked = false } = {}) {
            document.body.innerHTML = `
                <table>
                    <tbody id="user-checkboxes">
                        <tr>
                            <td><input type="checkbox" name="users[1][2]" id="lunch" ${lunchChecked ? 'checked' : ''}></td>
                            <td><input type="checkbox" name="users[1][4]" id="bento" ${bentoChecked ? 'checked' : ''}></td>
                        </tr>
                    </tbody>
                </table>
            `;
            const scope = document.querySelector('table');
            window.applyLunchBentoExclusion(scope);
            return {
                lunch: document.getElementById('lunch'),
                bento: document.getElementById('bento'),
            };
        }

        test('両方チェック済みなら弁当が unchecked に補正される', () => {
            const { bento } = buildGroupRow({ lunchChecked: true, bentoChecked: true });
            expect(bento.checked).toBe(false);
        });

        test('昼食をチェックすると弁当が unchecked になる', () => {
            const { lunch, bento } = buildGroupRow();
            bento.checked = true;
            lunch.checked = true;
            lunch.dispatchEvent(new Event('change'));
            expect(bento.checked).toBe(false);
        });

        test('弁当をチェックすると昼食が unchecked になる', () => {
            const { lunch, bento } = buildGroupRow();
            lunch.checked = true;
            bento.checked = true;
            bento.dispatchEvent(new Event('change'));
            expect(lunch.checked).toBe(false);
        });
    });

    // ----------------------------------------------------------------
    // applyLunchBentoExclusion – 直前編集モーダル (data-reservation-type)
    // ----------------------------------------------------------------
    describe('applyLunchBentoExclusion – 直前編集モーダル', () => {
        function buildChangeEditRow({ lunchChecked = false, bentoChecked = false } = {}) {
            document.body.innerHTML = `
                <table>
                    <tbody id="ce-tbody">
                        <tr data-user-id="1">
                            <td><input class="meal-checkbox" type="checkbox" data-reservation-type="2" id="lunch" ${lunchChecked ? 'checked' : ''}></td>
                            <td><input class="meal-checkbox" type="checkbox" data-reservation-type="4" id="bento" ${bentoChecked ? 'checked' : ''}></td>
                        </tr>
                    </tbody>
                </table>
            `;
            const scope = document.querySelector('table');
            window.applyLunchBentoExclusion(scope);
            return {
                lunch: document.getElementById('lunch'),
                bento: document.getElementById('bento'),
            };
        }

        test('両方チェック済みなら弁当が unchecked に補正される', () => {
            const { bento } = buildChangeEditRow({ lunchChecked: true, bentoChecked: true });
            expect(bento.checked).toBe(false);
        });

        test('昼食をチェックすると弁当が unchecked になる', () => {
            const { lunch, bento } = buildChangeEditRow();
            bento.checked = true;
            lunch.checked = true;
            lunch.dispatchEvent(new Event('change'));
            expect(bento.checked).toBe(false);
        });

        test('弁当をチェックすると昼食が unchecked になる', () => {
            const { lunch, bento } = buildChangeEditRow();
            lunch.checked = true;
            bento.checked = true;
            bento.dispatchEvent(new Event('change'));
            expect(lunch.checked).toBe(false);
        });

        test('data-locked="1" の昼食は変更されない', () => {
            const { lunch, bento } = buildChangeEditRow({ bentoChecked: true });
            lunch.dataset.locked = '1';
            bento.checked = true;
            bento.dispatchEvent(new Event('change'));
            // locked なので昼食は変更されない（既に未チェックでも disabled されない）
            expect(lunch.checked).toBe(false); // 元々未チェック → 変わらない
        });
    });
});
