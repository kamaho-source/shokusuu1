import { test, expect } from '@playwright/test';
import { login, appPath } from './helpers/auth.mjs';
import { sql, reservationRow, deleteReservations, insertReservation, dateFromToday } from './helpers/db.mjs';

/**
 * 他の人が予約を変更したとき、画面を再読み込みせずに反映されるか。
 *
 * 版数(reservation_version)を短い間隔で問い合わせ、変わったときだけ
 * 本体を取りに行く仕組みを検証する。
 * 既定の間隔は20秒なので、テストでは addInitScript で 1 秒に詰める。
 */

const USER = 34; // e2e_admin
const ROOM = 1;
const SYNC_MS = 1000;

/** 版数の問い合わせ間隔を詰める（本番既定の20秒だとテストが成立しないため） */
async function withFastSync(page) {
    // 本番では prefork のワーカー占有を避けるため 6 秒未満を受け付けない。
    // テストでは待ち時間が現実的でなくなるため、明示的に解除する。
    await page.addInitScript((ms) => {
        window.__RESERVATION_SYNC_INTERVAL_MS = ms;
        window.__RESERVATION_SYNC_ALLOW_FAST = true;
    }, SYNC_MS);
}

/** 現在の版数をサーバーから取る */
async function currentVersion(page) {
    const res = await page.request.get(appPath('/TReservationInfo/sync-version'), {
        headers: { Accept: 'application/json' },
        failOnStatusCode: false,
    });
    if (!res.ok()) return null;
    const j = await res.json();
    return j?.version ?? j?.data?.version ?? null;
}

test.describe('他の人の予約を再読み込みなしで反映する', () => {
    let date;

    test.beforeEach(() => {
        date = dateFromToday(20); // 通常予約期間
        deleteReservations(date);
    });
    test.afterEach(() => { if (date) deleteReservations(date); });

    test('版数エンドポイントはログイン済みなら整数を返す', async ({ page }) => {
        await login(page);
        const v = await currentVersion(page);
        expect(typeof v, '版数が整数で返らない').toBe('number');
        expect(v).toBeGreaterThan(0);
    });

    test('予約を書き込むと版数が進む', async ({ page }) => {
        await login(page);
        const before = await currentVersion(page);

        insertReservation({ userId: USER, date, meal: 1, room: ROOM, eat: 1, chg: 1 });
        // DB直書きでは版数は進まないので、アプリ経由の書き込みで確認する
        const token = await page.locator('meta[name="csrfToken"]').first().getAttribute('content');
        await page.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 3, value: 1, userId: USER },
            failOnStatusCode: false,
        });

        const after = await currentVersion(page);
        expect(after, '書き込んでも版数が進んでいない').toBeGreaterThan(before);
    });

    test('カレンダー: 他の人の予約が自動で反映される', async ({ browser }) => {
        const ctx = await browser.newContext();
        const watcher = await ctx.newPage();   // 開きっぱなしの画面
        const actor   = await ctx.newPage();   // 予約する側

        await withFastSync(watcher);
        await login(watcher);
        await watcher.goto(appPath('/TReservationInfo/'));
        await watcher.waitForFunction(() => !!window.__reservationCalendar, null, { timeout: 20000 });

        // 監視側が自動更新したかどうかを記録する
        await watcher.evaluate(() => {
            window.__refetchCount = 0;
            const cal = window.__reservationCalendar;
            const orig = cal.refetchEvents.bind(cal);
            cal.refetchEvents = function () { window.__refetchCount++; return orig(); };
        });

        // 別の画面から予約する
        await actor.goto(appPath('/TReservationInfo/'));
        const token = await actor.locator('meta[name="csrfToken"]').first().getAttribute('content');
        const res = await actor.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 1, value: 1, userId: USER },
            failOnStatusCode: false,
        });
        expect(res.status(), '予約の書き込みに失敗').toBe(200);

        // 監視側が自分で気づいて取り直すのを待つ（再読み込みはしない）
        await expect
            .poll(() => watcher.evaluate(() => window.__refetchCount), { timeout: 15000 })
            .toBeGreaterThan(0);

        // ページ遷移していない＝再読み込みせずに反映している
        expect(watcher.url()).toContain('/TReservationInfo');

        await ctx.close();
    });

    test('食数一括管理: 入力中は自動更新せずお知らせを出す', async ({ browser }) => {
        const ctx = await browser.newContext();
        const watcher = await ctx.newPage();
        const actor   = await ctx.newPage();

        await withFastSync(watcher);
        await login(watcher);
        await watcher.goto(appPath('/TReservationInfo/meal-count-grid'));
        await watcher.waitForFunction(() => typeof window.mcgHasUnsavedChanges === 'function', null, { timeout: 20000 });

        // 入力途中の状態をつくる（チェックを1つ付けて未登録のまま）
        // 左の固定列が重なってクリックが届かないため、要素へ直接送る
        await watcher.evaluate(() => {
            const td = [...document.querySelectorAll('.mcg-grid td.cell-meal')]
                .find(c => !c.classList.contains('is-past')
                        && !c.classList.contains('mcg-cell-conflict')
                        && !c.classList.contains('mcg-cell-excl'));
            if (!td) throw new Error('操作できる食数セルが見つからない');
            td.click();
        });
        await expect
            .poll(() => watcher.evaluate(() => window.mcgHasUnsavedChanges()), { timeout: 5000 })
            .toBe(true);

        // 別の画面から予約する
        await actor.goto(appPath('/TReservationInfo/'));
        const token = await actor.locator('meta[name="csrfToken"]').first().getAttribute('content');
        await actor.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 2, value: 1, userId: USER },
            failOnStatusCode: false,
        });

        // 勝手に再読み込みせず、お知らせだけ出ること
        await expect(
            watcher.locator('#reservation-live-notice'),
            '他の人の更新を知らせるお知らせが出ない'
        ).toBeVisible({ timeout: 15000 });

        expect(
            await watcher.evaluate(() => window.mcgHasUnsavedChanges()),
            '入力中の内容が消えている'
        ).toBe(true);

        await ctx.close();
    });
});
