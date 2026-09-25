import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.mjs';
import { sql, reservationRow, deleteReservations, insertReservation, dateFromToday } from './helpers/db.mjs';

/**
 * D: 同一人物・同一日・同一食事を別部屋で二重に有効化できないこと。
 *
 * 主キーに部屋が含まれるため DB では重複を防げず、集計側も行数を数えていたため
 * 複数部屋に所属する利用者が 1人2食として計上されていた。
 */

/**
 * トグル API を呼ぶ。
 *
 * page.evaluate の fetch はページ側(treservation_index.js)が window.fetch を
 * ラップしていて非2xxを例外に変えるため、レスポンスを素で見るには
 * Playwright の request コンテキストを使う（ブラウザのCookieを共有する）。
 */
async function toggle(page, { room, date, meal, value, userId }) {
    // csrfToken の meta は head と body の両方に出力されるため first() で取る
    const token = await page.locator('meta[name="csrfToken"]').first().getAttribute('content');
    const res = await page.request.post(`/kamaho-shokusu/TReservationInfo/toggle/${room}`, {
        headers: { 'X-CSRF-Token': token ?? '', 'Accept': 'application/json' },
        data: { date, meal, value, userId },
        failOnStatusCode: false,
    });
    return { status: res.status(), body: await res.text() };
}

test.describe('別部屋の二重計上（D）', () => {
    let date;
    let target = null;

    test.beforeAll(() => {
        // 実データから「複数部屋に所属している利用者」を1人選ぶ
        const rows = sql(`
            SELECT g.i_id_user, GROUP_CONCAT(g.i_id_room ORDER BY g.i_id_room) AS rooms
            FROM m_user_group g
            JOIN m_user_info u ON u.i_id_user = g.i_id_user
            WHERE g.active_flag = 0 AND u.i_del_flag = 0
            GROUP BY g.i_id_user
            HAVING COUNT(DISTINCT g.i_id_room) > 1
            ORDER BY g.i_id_user
            LIMIT 1
        `);
        if (rows.length === 0) return;
        const [userId, rooms] = rows[0];
        const roomIds = rooms.split(',').map(Number);
        target = { userId: Number(userId), roomA: roomIds[0], roomB: roomIds[1] };
    });

    test.beforeEach(() => {
        test.skip(target === null, '複数部屋に所属する利用者がいないためスキップ');
        date = dateFromToday(20); // 通常予約期間
        deleteReservations(date);
    });

    test.afterEach(() => { if (date) deleteReservations(date); });

    test('別部屋に有効な予約があるとトグルAPIが拒否する', async ({ page }) => {
        await login(page);

        // 部屋Aで朝食を予約済みにする
        insertReservation({ userId: target.userId, date, meal: 1, room: target.roomA, eat: 1, chg: 1 });

        // 部屋Bの同じ朝食をトグルでONにしようとする
        const res = await toggle(page, { room: target.roomB, date, meal: 1, value: 1, userId: target.userId });

        expect(res.status, `拒否されていない: ${res.body}`).toBe(409);
        expect(res.body).toContain('既に予約されています');

        // 部屋Bに行が作られていないこと
        expect(
            reservationRow({ userId: target.userId, date, meal: 1, room: target.roomB }),
            '別部屋に行が作られた'
        ).toBeNull();
    });

    test('部屋Aの予約を取り消せば部屋Bで予約できる', async ({ page }) => {
        await login(page);

        // 部屋Aは「予約なし」の行（取り消し済み）
        insertReservation({ userId: target.userId, date, meal: 1, room: target.roomA, eat: 0, chg: 0 });

        const res = await toggle(page, { room: target.roomB, date, meal: 1, value: 1, userId: target.userId });

        expect(res.status, `拒否されている: ${res.body}`).toBe(200);
        expect(
            reservationRow({ userId: target.userId, date, meal: 1, room: target.roomB })?.eat,
            '部屋Bに予約が作られていない'
        ).toBe(1);
    });
});
