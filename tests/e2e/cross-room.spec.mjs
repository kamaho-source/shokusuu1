import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.mjs';
import { sql, reservationRow, deleteReservations, insertReservation, dateFromToday } from './helpers/db.mjs';

const USER = 34;

/** D: 同一人物・同一日・同一食事を別部屋で二重に有効化できないこと。 */
test.describe('別部屋の二重計上（D）', () => {
  let date;
  let otherRoom;

  test.beforeAll(() => {
    // e2e_admin が所属する部屋を2つ拾う
    const rooms = sql(`SELECT i_id_room FROM m_user_group WHERE i_id_user=${USER} AND active_flag=0 ORDER BY i_id_room`);
    test.skip(rooms.length < 2, 'この環境では対象ユーザーが複数部屋に所属していないためスキップ');
    otherRoom = { first: Number(rooms[0][0]), second: Number(rooms[1][0]) };
  });

  test.beforeEach(() => { date = dateFromToday(20); deleteReservations(date); });
  test.afterEach(() => { deleteReservations(date); });

  test('別部屋に有効な予約があるとトグルAPIが拒否する', async ({ page }) => {
    await login(page);
    insertReservation({ userId: USER, date, meal: 1, room: otherRoom.first, eat: 1, chg: 1 });

    const res = await page.evaluate(async ({ room, date }) => {
      const token = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content');
      const r = await fetch(`/kamaho-shokusu/TReservationInfo/toggle/${room}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ date, meal: 1, value: 1 }),
      });
      return { status: r.status, body: await r.text() };
    }, { room: otherRoom.second, date });

    expect(res.status, `拒否されていない: ${res.body}`).toBe(409);
    expect(reservationRow({ userId: USER, date, meal: 1, room: otherRoom.second }), '別部屋に行が作られた').toBeNull();
  });
});
