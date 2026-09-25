import { test, expect } from '@playwright/test';
import { login, openIndividualTab, mealCheckbox, captureDialogs } from './helpers/auth.mjs';
import { reservationRow, deleteReservations, dateFromToday } from './helpers/db.mjs';

const USER = 34;
const ROOM = 1;

/**
 * 別タブで保存済みなのに、もう一方のタブが古い画面のまま保存されるケース。
 *   ① 個人タブは差分送信 → 触っていない食事は上書きしない
 *   ② 保存側が他タブへ失効を通知 → 古いタブにバナーが出る
 */
test.describe('別タブが古いまま保存されるケース（① 差分送信 / ② 失効通知）', () => {
  let date;

  test.beforeEach(() => {
    date = dateFromToday(5);
    deleteReservations(date);
  });

  test.afterEach(() => { deleteReservations(date); });

  test('古いタブで保存しても、別タブが追加した食事は消えない（①）', async ({ browser }) => {
    const context = await browser.newContext();
    const tabA = await context.newPage();
    const tabB = await context.newPage();
    const dialogsB = await captureDialogs(tabB);

    await login(tabA);

    // 両方のタブで同じ画面を開く（このときどちらも「全部未予約」の状態）
    await openIndividualTab(tabA, { room: ROOM, date, meal: 1 });
    await openIndividualTab(tabB, { room: ROOM, date, meal: 1 });

    // タブA: 朝食(1)を追加して保存
    await mealCheckbox(tabA, { meal: 1, room: ROOM }).check();
    await tabA.click('#ce-save-btn');
    await tabA.waitForTimeout(1500);
    expect(reservationRow({ userId: USER, date, meal: 1, room: ROOM })?.chg, 'タブAの保存が効いていない').toBe(1);

    // タブB: 古い画面のまま「夕食(3)」だけを触って保存
    await mealCheckbox(tabB, { meal: 3, room: ROOM }).check();
    await tabB.click('#ce-save-btn');
    await tabB.waitForTimeout(1500);

    expect(dialogsB, `タブBでアラート: ${dialogsB.join(' / ')}`).toHaveLength(0);

    // タブBは朝食を触っていないので、タブAの追加が残っていること
    const breakfast = reservationRow({ userId: USER, date, meal: 1, room: ROOM });
    const dinner = reservationRow({ userId: USER, date, meal: 3, room: ROOM });
    expect(breakfast?.chg, '古いタブの保存で別タブの朝食が消えた').toBe(1);
    expect(dinner?.chg, 'タブBの夕食が保存されていない').toBe(1);

    await context.close();
  });

  test('別タブで保存されると古いタブに失効バナーが出る（②）', async ({ browser }) => {
    const context = await browser.newContext();
    const tabA = await context.newPage();
    const tabB = await context.newPage();

    await login(tabA);
    await openIndividualTab(tabA, { room: ROOM, date, meal: 1 });
    await openIndividualTab(tabB, { room: ROOM, date, meal: 1 });

    await expect(tabB.locator('#reservation-stale-notice'), '最初からバナーが出ている').toHaveCount(0);

    await mealCheckbox(tabA, { meal: 1, room: ROOM }).check();
    await tabA.click('#ce-save-btn');

    await expect(
      tabB.locator('#reservation-stale-notice'),
      '別タブ保存後に失効バナーが出ない'
    ).toBeVisible({ timeout: 10000 });

    await expect(tabB.locator('#reservation-stale-notice')).toContainText('他のタブで更新されました');

    await context.close();
  });

  test('保存したタブ自身にはバナーを出さない', async ({ browser }) => {
    const context = await browser.newContext();
    const tabA = await context.newPage();

    await login(tabA);
    await openIndividualTab(tabA, { room: ROOM, date, meal: 1 });
    await mealCheckbox(tabA, { meal: 1, room: ROOM }).check();
    await tabA.click('#ce-save-btn');
    await tabA.waitForTimeout(1500);

    await expect(tabA.locator('#reservation-stale-notice'), '自分のタブにバナーが出ている').toHaveCount(0);
    await context.close();
  });
});
