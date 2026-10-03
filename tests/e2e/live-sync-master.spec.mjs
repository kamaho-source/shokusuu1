import { test, expect } from '@playwright/test';
import { login, appPath } from './helpers/auth.mjs';
import { sql } from './helpers/db.mjs';

/**
 * 利用者・部屋の構成が変わったとき、開いている食数一括管理に伝わるか。
 *
 * グリッドの行は利用者、列は部屋から作られる。入居者を追加しても版数が
 * 進まないと、他の職員の画面にはいつまでも行が増えない。
 * 行が増えるときはセルの差し替えでは追いつかないため、お知らせを出して
 * 再読み込みを利用者に委ねる、という設計になっていることを確かめる。
 */

const ROOM = 1;
const SYNC_MS = 1000;
const LOGIN_ID = 'e2e_live_master';

async function withFastSync(page) {
    await page.addInitScript((ms) => {
        window.__RESERVATION_SYNC_INTERVAL_MS = ms;
        window.__RESERVATION_SYNC_ALLOW_FAST = true;
    }, SYNC_MS);
}

async function waitForBaseline(page) {
    await page.waitForFunction(
        () => !!window.ReservationLiveSync?.hasBaseline?.(),
        null,
        { timeout: 20000 }
    );
}

/** 検証用に作った利用者を消す */
function cleanupUser() {
    sql(`DELETE FROM m_user_group WHERE i_id_user IN `
      + `(SELECT i_id_user FROM m_user_info WHERE c_login_account = '${LOGIN_ID}')`);
    sql(`DELETE FROM m_user_info WHERE c_login_account = '${LOGIN_ID}'`);
}

test.beforeEach(() => cleanupUser());
test.afterEach(() => cleanupUser());

test('利用者を追加すると、開いている食数一括管理に伝わる', async ({ browser }) => {
    const ctx     = await browser.newContext();
    const watcher = await ctx.newPage();
    const actor   = await ctx.newPage();

    // 見ている側: 食数一括管理を開いたまま待つ
    await withFastSync(watcher);
    await login(watcher);
    await watcher.goto(appPath(`/TReservationInfo/meal-count-grid?mode=room&room_id=${ROOM}`));
    await watcher.waitForFunction(() => typeof window.mcgRefreshFromServer === 'function', null, { timeout: 20000 });
    await waitForBaseline(watcher);

    const rowsBefore = await watcher.locator('.mcg-grid tr[data-user-id]').count();
    expect(rowsBefore, 'グリッドに行が無い').toBeGreaterThan(0);

    // 操作する側: 実際の登録画面から利用者を追加する
    await actor.goto(appPath('/MUserInfo/add'));
    await actor.fill('[name="c_login_account"]', LOGIN_ID);
    await actor.fill('#inputPassword', 'E2e-' + Math.random().toString(36).slice(2, 10) + '-Aa1');
    await actor.fill('[name="c_user_name"]', 'E2E 検証用');
    await actor.fill('#birthDate', '2016-04-01');
    await actor.selectOption('[name="i_user_gender"]', { index: 1 });
    await actor.selectOption('[name="age_group"]', '2');
    await actor.selectOption('[name="age"]', '10');
    await actor.selectOption('[name="role"]', '1'); // 児童（職員IDが不要）
    await actor.check(`#MUserGroup-${ROOM}-i_id_room`);

    // 必須項目が揃うまで登録ボタンは disabled のままなので、有効化を待つ
    const submit = actor.locator('#submit-button');
    await expect(submit, '必須項目を埋めても登録ボタンが有効にならない').toBeEnabled({ timeout: 15000 });
    await submit.click();

    // 登録できたことをDBで確かめる（画面の遷移先に依存しない）
    await expect
        .poll(() => sql(`SELECT i_id_user FROM m_user_info WHERE c_login_account = '${LOGIN_ID}'`).length,
              { timeout: 20000, message: '利用者を登録できていない' })
        .toBe(1);

    // 見ている側に伝わること。行が増えるため、セルの差し替えではなく
    // 「構成が変わった」お知らせが出る。
    const notice = watcher.locator('#reservation-live-notice');
    await expect(notice, '利用者の追加が見ている画面に伝わらない').toBeVisible({ timeout: 20000 });
    await expect(notice).toContainText('構成が変わりました');

    // 勝手に作り直されていないこと（行数はそのまま＝再読み込みしていない）
    expect(await watcher.locator('.mcg-grid tr[data-user-id]').count()).toBe(rowsBefore);

    // お知らせの「最新を表示する」で行が増えること
    await watcher.click('#reservation-live-reload');
    await watcher.waitForLoadState('load');
    await expect
        .poll(() => watcher.locator('.mcg-grid tr[data-user-id]').count(), { timeout: 20000 })
        .toBe(rowsBefore + 1);

    await ctx.close();
});
