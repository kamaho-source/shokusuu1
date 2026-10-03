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
/**
 * 監視側が「現在の版数」を基準として控え終えるまで待つ。
 *
 * 基準が決まる前に書き込むと、その変更が基準に取り込まれて検知対象から外れる。
 * 実運用では画面を開いた直後のごく短い間だけ起きるが、テストでは毎回踏むので待つ。
 */
async function waitForBaseline(page) {
    await page.waitForFunction(
        () => !!window.ReservationLiveSync?.hasBaseline?.(),
        null,
        { timeout: 20000 }
    );
}

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
        expect(typeof before, '版数を取得できていない').toBe('number');

        // DB直書きでは版数は進まないので、アプリ経由の書き込みで確認する
        const token = await page.locator('meta[name="csrfToken"]').first().getAttribute('content');
        const res = await page.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 3, value: 1, userId: USER },
            failOnStatusCode: false,
        });
        // 書き込みが失敗していると版数は進まず、原因の分かりにくい比較エラーになる
        expect(res.status(), `予約の書き込みに失敗: ${await res.text()}`).toBe(200);

        const after = await currentVersion(page);
        expect(after, '書き込んでも版数が進んでいない').toBeGreaterThan(before);
    });

    test('自分の保存では再読み込みされない', async ({ page }) => {
        await withFastSync(page);
        await login(page);
        await page.goto(appPath('/TReservationInfo/'));
        await page.waitForFunction(() => !!window.ReservationLiveSync, null, { timeout: 20000 });

        // onChange（＝他の人の変更とみなした回数）を数える
        await page.evaluate(() => {
            window.__changeCount = 0;
            window.ReservationLiveSync.stop();
            window.ReservationLiveSync.start({
                onChange: function () { window.__changeCount++; },
            });
        });

        // 自分で書き込み、直後に基準を取り直す（画面側と同じ流れ）
        const token = await page.locator('meta[name="csrfToken"]').first().getAttribute('content');
        const res = await page.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 4, value: 1, userId: USER },
            failOnStatusCode: false,
        });
        expect(res.status(), `予約の書き込みに失敗: ${await res.text()}`).toBe(200);
        await page.evaluate(() => window.ReservationLiveSync.resync());

        // 確認が数回走るだけの時間を置いても、他人の変更として扱われないこと
        await page.waitForTimeout(5000);
        expect(
            await page.evaluate(() => window.__changeCount),
            '自分の保存が他の人の変更として扱われている'
        ).toBe(0);
    });

    test('カレンダー: 他の人の予約が自動で反映される', async ({ browser }) => {
        const ctx = await browser.newContext();
        const watcher = await ctx.newPage();   // 開きっぱなしの画面
        const actor   = await ctx.newPage();   // 予約する側

        await withFastSync(watcher);
        await login(watcher);
        await watcher.goto(appPath('/TReservationInfo/'));
        await watcher.waitForFunction(() => !!window.__reservationCalendar, null, { timeout: 20000 });
        await waitForBaseline(watcher);

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
        await waitForBaseline(watcher);

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

    test('食数一括管理: 入力が無ければ再読み込みせずセルだけ差し替わる', async ({ browser }) => {
        const ctx = await browser.newContext();
        const watcher = await ctx.newPage();
        const actor   = await ctx.newPage();

        await withFastSync(watcher);
        await login(watcher);
        await watcher.goto(appPath('/TReservationInfo/meal-count-grid?mode=room&room_id=' + ROOM));
        await watcher.waitForFunction(() => typeof window.mcgRefreshFromServer === 'function', null, { timeout: 20000 });
        await waitForBaseline(watcher);

        // ページが作り直されたら分かるよう目印を置く。再読み込みされれば消える。
        await watcher.evaluate(() => { window.__notReloaded = true; });

        const cell = watcher.locator(
            `.mcg-grid td[data-user-id="${USER}"][data-room-id="${ROOM}"][data-date="${date}"][data-meal="1"]`
        );
        await expect(cell, '対象セルが画面に無い').toHaveCount(1);
        await expect(cell).toHaveAttribute('data-reserved', '0');

        // 別の画面から予約する
        await actor.goto(appPath('/TReservationInfo/'));
        const token = await actor.locator('meta[name="csrfToken"]').first().getAttribute('content');
        const res = await actor.request.post(appPath(`/TReservationInfo/toggle/${ROOM}`), {
            headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
            data: { date, meal: 1, value: 1, userId: USER },
            failOnStatusCode: false,
        });
        expect(res.status(), `予約の書き込みに失敗: ${await res.text()}`).toBe(200);

        // セルの値だけが変わること
        await expect(cell, 'セルが自動で反映されない').toHaveAttribute('data-reserved', '1', { timeout: 15000 });
        await expect(cell).toHaveText('1');

        // ページごと作り直されていないこと
        expect(
            await watcher.evaluate(() => window.__notReloaded === true),
            'ページが再読み込みされている（スクロール位置が飛ぶ）'
        ).toBe(true);

        // 要再読み込みのお知らせは出ないこと
        await expect(watcher.locator('#reservation-live-notice')).toHaveCount(0);

        await ctx.close();
    });

    test('ログインが切れたら自動更新を止めて知らせる', async ({ browser }) => {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();

        await withFastSync(page);
        await login(page);
        await page.goto(appPath('/TReservationInfo/'));
        await page.waitForFunction(() => !!window.ReservationLiveSync, null, { timeout: 20000 });
        await waitForBaseline(page);

        // セッションを失わせる（昼休みを挟んで期限が切れた状況）
        await ctx.clearCookies();

        await expect(
            page.locator('#reservation-live-notice'),
            'ログイン切れを知らせていない（古い画面を見続けてしまう）'
        ).toBeVisible({ timeout: 15000 });

        await expect(page.locator('#reservation-live-notice')).toContainText('ログイン');

        // 問い合わせを止めていること
        expect(
            await page.evaluate(() => window.ReservationLiveSync.isStopped()),
            'ログイン切れ後も問い合わせ続けている'
        ).toBe(true);

        await ctx.close();
    });
});
