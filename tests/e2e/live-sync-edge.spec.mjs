import { test, expect, devices } from '@playwright/test';
import { login, appPath } from './helpers/auth.mjs';
import { sql, deleteReservations, insertReservation, dateFromToday } from './helpers/db.mjs';

/**
 * 自動更新の細かい挙動の確認。
 *
 *   1. スマホでお知らせがちゃんと読めるか（横にはみ出さないか）
 *   2. 放置したら問い合わせを止め、触ったら再開するか
 *   3. 他部屋ロックが外れたセルを実際にクリックできるか
 */

const USER = 34; // e2e_admin
const ROOM = 1;
const ROOM_B = 2;
const SYNC_MS = 1000;

async function withFastSync(page, extra = {}) {
    await page.addInitScript((cfg) => {
        window.__RESERVATION_SYNC_INTERVAL_MS = cfg.interval;
        window.__RESERVATION_SYNC_ALLOW_FAST = true;
        if (cfg.idle) window.__RESERVATION_SYNC_IDLE_MS = cfg.idle;
    }, { interval: SYNC_MS, ...extra });
}

async function waitForBaseline(page) {
    await page.waitForFunction(
        () => !!window.ReservationLiveSync?.hasBaseline?.(),
        null,
        { timeout: 20000 }
    );
}

/** 別タブから予約を切り替える */
async function toggleFromOtherPage(ctx, { date, meal, value, room = ROOM }) {
    const actor = await ctx.newPage();
    await actor.goto(appPath('/TReservationInfo/'));
    const token = await actor.locator('meta[name="csrfToken"]').first().getAttribute('content');
    const res = await actor.request.post(appPath(`/TReservationInfo/toggle/${room}`), {
        headers: { 'X-CSRF-Token': token ?? '', Accept: 'application/json' },
        data: { date, meal, value, userId: USER },
        failOnStatusCode: false,
    });
    const body = await res.text();
    await actor.close();
    return { status: res.status(), body };
}

// ───────────────────────────────────────────
// 1. スマホでのお知らせ
// ───────────────────────────────────────────

test('スマホ: お知らせが読める位置に出て横にはみ出さない', async ({ browser }) => {
    const date = dateFromToday(20);
    deleteReservations(date);

    const ctx = await browser.newContext({ ...devices['iPhone 13'] });
    const page = await ctx.newPage();

    await withFastSync(page);
    await login(page);
    await page.goto(appPath('/TReservationInfo/meal-count-grid?mode=room&room_id=' + ROOM));
    await page.waitForFunction(() => typeof window.mcgHasUnsavedChanges === 'function', null, { timeout: 20000 });
    await waitForBaseline(page);

    // 入力途中にしてお知らせを出す（スマホで一番起きやすい流れ）
    await page.evaluate(() => {
        const td = [...document.querySelectorAll('.mcg-grid td.mcg-toggleable')][0];
        if (!td) throw new Error('操作できるセルが見つからない');
        td.click();
    });
    await expect.poll(() => page.evaluate(() => window.mcgHasUnsavedChanges()), { timeout: 5000 }).toBe(true);

    await toggleFromOtherPage(ctx, { date, meal: 2, value: 1 });

    const notice = page.locator('#reservation-live-notice');
    await expect(notice, 'スマホでお知らせが出ない').toBeVisible({ timeout: 15000 });

    // 横スクロールしないと読めない状態になっていないこと
    const overflow = await page.evaluate(() => {
        const el = document.getElementById('reservation-live-notice');
        const r = el.getBoundingClientRect();
        return {
            right: Math.round(r.right),
            viewport: window.innerWidth,
            bodyScrollable: document.documentElement.scrollWidth > window.innerWidth + 1,
        };
    });
    expect(overflow.right, `お知らせが画面幅(${overflow.viewport})を超えている`).toBeLessThanOrEqual(overflow.viewport + 1);
    expect(overflow.bodyScrollable, 'ページ全体が横スクロールするようになっている').toBe(false);

    // 「最新を表示する」が押せる大きさであること（誤タップ防止の観点）
    const box = await page.locator('#reservation-live-reload').boundingBox();
    expect(box?.height ?? 0, 'ボタンが小さすぎてスマホで押しにくい').toBeGreaterThanOrEqual(28);

    await ctx.close();
    deleteReservations(date);
});

// ───────────────────────────────────────────
// 2. 放置での停止と復帰
// ───────────────────────────────────────────

test('放置すると問い合わせを止め、触ると再開する', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    // 放置判定を 2 秒に詰める
    await withFastSync(page, { idle: 2000 });
    await login(page);

    let polls = 0;
    page.on('request', (req) => {
        if (req.url().includes('/TReservationInfo/sync-version')) polls++;
    });

    await page.goto(appPath('/TReservationInfo/'));
    await page.waitForFunction(() => !!window.ReservationLiveSync, null, { timeout: 20000 });
    await waitForBaseline(page);

    // 操作せずに放置する
    await page.waitForTimeout(3500);
    const afterIdleStart = polls;
    await page.waitForTimeout(3000);

    expect(
        polls - afterIdleStart,
        `放置中も問い合わせ続けている（${polls - afterIdleStart} 回）`
    ).toBe(0);

    // 触ったら再開すること
    await page.mouse.click(5, 5);
    await expect
        .poll(() => polls - afterIdleStart, { timeout: 10000, message: '操作しても再開しない' })
        .toBeGreaterThan(0);

    await ctx.close();
});

// ───────────────────────────────────────────
// 3. 他部屋ロックの解除
// ───────────────────────────────────────────

test('他部屋ロックが外れたセルを操作できる', async ({ browser }) => {
    const date = dateFromToday(20);
    deleteReservations(date);

    // 検証用に部屋Bへも所属させる
    sql(`DELETE FROM m_user_group WHERE i_id_user = ${USER} AND i_id_room = ${ROOM_B}`);
    sql(`INSERT INTO m_user_group (tenant_id, facility_id, i_id_user, i_id_room, active_flag, dt_create, c_create_user)
         VALUES (1, 1, ${USER}, ${ROOM_B}, 0, NOW(), 'e2e')`);

    // 部屋Aに有効な予約を置く → 部屋Bの同じ食事はロックされる
    insertReservation({ userId: USER, date, meal: 1, room: ROOM, eat: 1, chg: 1 });

    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    await withFastSync(page);
    await login(page);
    await page.goto(appPath('/TReservationInfo/meal-count-grid?mode=all'));
    await page.waitForFunction(() => typeof window.mcgRefreshFromServer === 'function', null, { timeout: 20000 });
    await waitForBaseline(page);

    const cellB = page.locator(
        `.mcg-grid td[data-user-id="${USER}"][data-room-id="${ROOM_B}"][data-date="${date}"][data-meal="1"]`
    );
    await expect(cellB, '部屋Bのセルが画面に無い').toHaveCount(1);
    await expect(cellB, '部屋Bのセルがロックされていない').toHaveClass(/mcg-cell-conflict/);

    // 部屋Aの予約を外す → 部屋Bのロックが外れるはず
    const res = await toggleFromOtherPage(ctx, { date, meal: 1, value: 0, room: ROOM });
    expect(res.status, `部屋Aの解除に失敗: ${res.body}`).toBe(200);

    await expect(cellB, 'ロックが外れない').not.toHaveClass(/mcg-cell-conflict/, { timeout: 15000 });
    await expect(cellB, '操作できる状態に戻らない').toHaveClass(/mcg-toggleable/);

    // 実際にクリックして未登録の変更になること
    await cellB.evaluate((el) => el.click());
    await expect
        .poll(() => page.evaluate(() => window.mcgHasUnsavedChanges()),
              { timeout: 5000, message: 'ロック解除後のセルをクリックしても反応しない' })
        .toBe(true);

    await ctx.close();
    sql(`DELETE FROM m_user_group WHERE i_id_user = ${USER} AND i_id_room = ${ROOM_B}`);
    deleteReservations(date);
});
