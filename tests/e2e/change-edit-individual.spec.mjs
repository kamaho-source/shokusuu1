import { test, expect } from '@playwright/test';
import { login, openIndividualTab, mealCheckbox, captureDialogs } from './helpers/auth.mjs';
import { reservationRow, deleteReservations, insertReservation, dateFromToday } from './helpers/db.mjs';

// e2e_admin
const USER = 34;
const ROOM = 1;
const MEAL = 1;

test.describe('直前編集 個人タブ（H / A / B）', () => {
  let date;

  test.beforeEach(() => {
    date = dateFromToday(4); // 直前編集ウィンドウ内
    deleteReservations(date);
  });

  test.afterEach(() => { deleteReservations(date); });

  test('個人タブの操作がサーバーへ送信され保存される（H）', async ({ page }) => {
    const dialogs = await captureDialogs(page);
    await login(page);
    await openIndividualTab(page, { room: ROOM, date, meal: MEAL });

    const posts = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().includes('change-edit')) posts.push(r.postData() || '');
    });

    await mealCheckbox(page, { meal: MEAL, room: ROOM }).check();
    await page.click('#ce-save-btn');
    await page.waitForTimeout(1500);

    expect(dialogs, `アラートが出た: ${dialogs.join(' / ')}`).toHaveLength(0);
    expect(posts.join(''), 'meals 形式で送信されていない').toContain('"meals"');

    const row = reservationRow({ userId: USER, date, meal: MEAL, room: ROOM });
    expect(row, '予約が作成されていない').not.toBeNull();
    expect(row.chg, 'i_change_flag が 1 になっていない').toBe(1);
    expect(row.eat, '直前期間の新規行は eat_flag=0 であるべき').toBe(0);
  });

  test('直前追加した予約を取り消せる（A）', async ({ page }) => {
    // トグル相当: eat_flag=0 / i_change_flag=1
    insertReservation({ userId: USER, date, meal: MEAL, room: ROOM, eat: 0, chg: 1 });

    const dialogs = await captureDialogs(page);
    await login(page);
    await openIndividualTab(page, { room: ROOM, date, meal: MEAL });

    await expect(mealCheckbox(page, { meal: MEAL, room: ROOM }), 'プリチェックされていない').toBeChecked();
    await mealCheckbox(page, { meal: MEAL, room: ROOM }).uncheck();
    await page.click('#ce-save-btn');
    await page.waitForTimeout(1500);

    expect(dialogs, `アラートが出た: ${dialogs.join(' / ')}`).toHaveLength(0);
    const row = reservationRow({ userId: USER, date, meal: MEAL, room: ROOM });
    expect(row.chg, '取り消しが反映されていない').toBe(0);
  });

  test('直前キャンセル後の再予約が復活し、発注済みの eat_flag は保持される（B）', async ({ page }) => {
    // 直前キャンセル済み: eat_flag=1（発注済み） / i_change_flag=0
    insertReservation({ userId: USER, date, meal: MEAL, room: ROOM, eat: 1, chg: 0 });

    const dialogs = await captureDialogs(page);
    await login(page);
    await openIndividualTab(page, { room: ROOM, date, meal: MEAL });

    await expect(mealCheckbox(page, { meal: MEAL, room: ROOM })).not.toBeChecked();
    await mealCheckbox(page, { meal: MEAL, room: ROOM }).check();
    await page.click('#ce-save-btn');
    await page.waitForTimeout(1500);

    expect(dialogs, `アラートが出た: ${dialogs.join(' / ')}`).toHaveLength(0);
    const row = reservationRow({ userId: USER, date, meal: MEAL, room: ROOM });
    expect(row.chg, '再予約が反映されていない').toBe(1);
    expect(row.eat, '発注済みの eat_flag を書き換えてはいけない').toBe(1);
  });
});
