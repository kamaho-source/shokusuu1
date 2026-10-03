import { test, expect } from '@playwright/test';
import { login, appPath } from './helpers/auth.mjs';
import { deleteReservations, dateFromToday } from './helpers/db.mjs';

/**
 * 直前編集・承認画面でも他の人の変更を知らせるか。
 *
 * どちらも「対象を選んでから実行する」画面なので、勝手に作り直さず
 * お知らせだけを出す方針。
 */

const USER = 34; // e2e_admin
const ROOM = 1;
const SYNC_MS = 1000;

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

// 直前編集は「部屋/日付」が要る。今日+5日は直前編集ウィンドウ内。
const CE_DATE = dateFromToday(5);

const SCREENS = [
    { name: '直前編集',         path: `/TReservationInfo/change-edit/${ROOM}/${CE_DATE}` },
    { name: '承認(管理者)',     path: '/Approval/adminIndex' },
    { name: '承認(ブロック長)', path: '/Approval/blockLeaderIndex' },
];

for (const screen of SCREENS) {
    test(`${screen.name}: 他の人の変更をお知らせする`, async ({ browser }) => {
        const date = dateFromToday(20);
        deleteReservations(date);

        const ctx     = await browser.newContext();
        const watcher = await ctx.newPage();
        const actor   = await ctx.newPage();

        await withFastSync(watcher);
        await login(watcher);
        const res = await watcher.goto(appPath(screen.path));
        expect(res?.status(), `${screen.name} を開けない`).toBeLessThan(400);

        await watcher.waitForFunction(() => !!window.ReservationLiveSync, null, { timeout: 20000 });
        await waitForBaseline(watcher);

        // 別の画面から予約する
        await actor.goto(appPath('/TReservationInfo/'));
        const token = await actor.locator('meta[name="csrfToken"]').first().getAttribute('content');
        const post = await actor.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 1, value: 1, userId: USER },
            failOnStatusCode: false,
        });
        expect(post.status(), `予約の書き込みに失敗: ${await post.text()}`).toBe(200);

        await expect(
            watcher.locator('#reservation-live-notice'),
            `${screen.name} でお知らせが出ない`
        ).toBeVisible({ timeout: 15000 });

        deleteReservations(date);
        await ctx.close();
    });
}
