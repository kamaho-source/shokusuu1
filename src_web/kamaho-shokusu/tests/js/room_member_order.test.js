'use strict';

// 部屋情報の所属メンバーの並び替え（#871）: 数字は何人分でも入れておけて、［保存］でまとめて並ぶ
const row = (id, no, age = '') => `
    <tr data-user-id="${id}" data-age="${age}">
        <td class="grip"></td><td class="rh">${no}</td>
        <td><input type="hidden" name="user_ids[]" value="${id}"><input type="number" class="member-order-input" value="${no}"></td>
        <td class="chgmark"></td>
    </tr>`;

const setup = () => {
    document.body.innerHTML = `
        <form id="f">
            <div class="xl-wrap">
                <span id="member-order-dirty"></span>
                <button type="button" data-age-sort="asc">年下から</button>
                <table id="member-order-table"><tbody>
                    ${row(1, 1, 40)}${row(2, 2)}${row(3, 3, 8)}${row(4, 4, 10)}
                </tbody></table>
            </div>
            <button type="button" id="member-order-undo"></button>
        </form>`;
    jest.isolateModules(() => require('../../webroot/js/room-member-order.js'));
};
const order = () => [...document.querySelectorAll('input[name="user_ids[]"]')].map((i) => Number(i.value));
const type = (id, value) => {
    const input = document.querySelector(`tr[data-user-id="${id}"] .member-order-input`);
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
};
const submit = () => document.getElementById('f').dispatchEvent(new Event('submit', { cancelable: true }));

describe('room-member-order.js', () => {
    beforeEach(setup);

    test('数字を入れても行はすぐには動かず、未保存の人数だけ出る', () => {
        type(4, 1);
        type(3, 2);
        expect(order()).toEqual([1, 2, 3, 4]);
        expect(document.getElementById('member-order-dirty').textContent).toBe('● 未保存 2人');
    });

    test('［保存］で入れた数字の順にまとめて並ぶ（同じ数字は書き換えた人が先）', () => {
        type(4, 1);
        type(3, 2);
        submit();
        expect(order()).toEqual([4, 1, 3, 2]);
    });

    test('区分に関係なく、年齢順は全員で並ぶ（年齢なしは末尾）', () => {
        document.querySelector('[data-age-sort]').click();
        expect(order()).toEqual([3, 4, 1, 2]);
    });

    test('元に戻すで、入れた数字も並びも保存済みに戻る', () => {
        type(4, 1);
        document.querySelector('[data-age-sort]').click();
        document.getElementById('member-order-undo').click();
        expect(order()).toEqual([1, 2, 3, 4]);
        expect(document.getElementById('member-order-dirty').textContent).toBe('保存済み');
    });
});
