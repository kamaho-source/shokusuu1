import { test, expect } from '@playwright/test';
import { login, appPath } from './helpers/auth.mjs';
import { sql } from './helpers/db.mjs';

/**
 * 自動更新用に足した ?format=json が、画面と同じ範囲しか返さないこと。
 *
 * 権限の判定は画面と同じ処理を通っているが、JSON の方だけ多く返して
 * いたら、見えてはいけない利用者の名前が他の職員に渡ってしまう。
 * 画面の行と JSON の行が一致することで確かめる。
 */

const ROOM = 1;
const LOGIN_ID = 'e2e_scope_staff';
const PASSWORD = 'E2e-Scope-Aa1!';

function cleanupUser() {
    sql(`DELETE FROM m_user_group WHERE i_id_user IN `
      + `(SELECT i_id_user FROM m_user_info WHERE c_login_account = '${LOGIN_ID}')`);
    sql(`DELETE FROM m_user_info WHERE c_login_account = '${LOGIN_ID}'`);
}

/** 画面に並んでいる (部屋:利用者) を拾う */
async function domRowKeys(page) {
    return page.evaluate(() =>
        [...document.querySelectorAll('.mcg-grid tr[data-user-id]')]
            .map(tr => tr.dataset.roomId + ':' + tr.dataset.userId)
            .sort()
    );
}

/** JSON が返す (部屋:利用者) を拾う */
async function jsonRowKeys(page, url) {
    const res = await page.request.get(url, {
        headers: { Accept: 'application/json' }, failOnStatusCode: false,
    });
    expect(res.status(), `JSON を取得できない: ${url}`).toBe(200);
    const body = await res.json();
    const data = body.data ?? body;
    const keys = [];
    for (const roomId of Object.keys(data.rooms ?? {})) {
        for (const u of data.rooms[roomId].users ?? []) {
            keys.push(`${roomId}:${u.id}`);
        }
    }
    return keys.sort();
}

for (const mode of ['individual', 'room', 'all']) {
    test(`管理者: ${mode} モードで画面とJSONの行が一致する`, async ({ page }) => {
        await login(page);
        const path = `/TReservationInfo/meal-count-grid?mode=${mode}&room_id=${ROOM}`;
        await page.goto(appPath(path));

        const dom  = await domRowKeys(page);
        const json = await jsonRowKeys(page, appPath(path + '&format=json'));

        expect(json, `${mode}: JSON が画面と違う行を返している`).toEqual(dom);
        expect(dom.length, `${mode}: 画面に行が無い`).toBeGreaterThan(0);
    });
}

test('非管理者: 画面とJSONの行が一致し、他部屋が混ざらない', async ({ browser }) => {
    cleanupUser();

    const ctx   = await browser.newContext();
    const admin = await ctx.newPage();

    // 検証用の職員を登録画面から作る
    await login(admin);
    await admin.goto(appPath('/MUserInfo/add'));
    await admin.fill('[name="c_login_account"]', LOGIN_ID);
    await admin.fill('#inputPassword', PASSWORD);
    await admin.fill('[name="c_user_name"]', 'E2E 範囲確認');
    await admin.fill('#birthDate', '1990-04-01');
    await admin.selectOption('[name="i_user_gender"]', { index: 1 });
    await admin.selectOption('[name="age_group"]', '7');
    await admin.selectOption('[name="age"]', '35');
    await admin.selectOption('[name="role"]', '0'); // 職員
    await admin.fill('[name="staff_id"]', '9001');
    await admin.check(`#MUserGroup-${ROOM}-i_id_room`);

    const submit = admin.locator('#submit-button');
    await expect(submit, '登録ボタンが有効にならない').toBeEnabled({ timeout: 15000 });
    await submit.click();

    await expect
        .poll(() => sql(`SELECT i_id_user FROM m_user_info WHERE c_login_account = '${LOGIN_ID}'`).length,
              { timeout: 20000, message: '検証用の職員を作れていない' })
        .toBe(1);
    await ctx.close();

    // その職員でログインして確かめる
    const ctx2 = await browser.newContext();
    const page = await ctx2.newPage();
    await login(page, { user: LOGIN_ID, pass: PASSWORD });

    for (const mode of ['individual', 'room', 'all']) {
        const path = `/TReservationInfo/meal-count-grid?mode=${mode}&room_id=${ROOM}`;
        await page.goto(appPath(path));

        const dom  = await domRowKeys(page);
        const json = await jsonRowKeys(page, appPath(path + '&format=json'));

        expect(json, `${mode}: JSON が画面より多く（または少なく）返している`).toEqual(dom);
    }

    await ctx2.close();
    cleanupUser();
});
