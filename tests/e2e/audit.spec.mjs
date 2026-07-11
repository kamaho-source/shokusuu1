// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Issue #507 全面監査の修正内容を検証する E2E テスト。
 * - セキュリティヘッダー（SecurityHeadersMiddleware）
 * - 予約画面の JS 設定（JSON_HEX フラグ付与後も動作すること）
 * - SP（375px）幅での表示崩れ
 */

async function login(page) {
    const user = process.env.E2E_USER ?? 'e2e_admin';
    const pass = process.env.E2E_PASS ?? 'E2eTest#2026';
    await page.goto('/kamaho-shokusu/MUserInfo/login');
    await page.fill('input[name="c_login_account"]', user);
    await page.fill('input[name="c_login_passwd"]', pass);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForURL(/TReservationInfo|pages|dashboard|\/kamaho-shokusu\/?$/);
}

test.describe('セキュリティヘッダー', () => {
    test('全レスポンスにセキュリティヘッダーが付与される', async ({ page }) => {
        const response = await page.goto('/kamaho-shokusu/MUserInfo/login');
        expect(response).not.toBeNull();
        const headers = response.headers();
        expect(headers['x-frame-options']).toMatch(/sameorigin/i);
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['referrer-policy']).toBe('same-origin');
    });
});

test.describe('予約画面の JS 設定（XSS 対策後の動作確認）', () => {
    test('予約カレンダー画面が JS エラーなく表示される', async ({ page }) => {
        /** @type {string[]} */
        const pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        await login(page);
        await page.goto('/kamaho-shokusu/TReservationInfo');

        // JS 設定（json_encode 出力）を利用するカレンダー/ツールバーが描画される
        await expect(page.locator('h1')).toContainText('食数予約');
        expect(pageErrors).toEqual([]);
    });
});

test.describe('SP（375px）表示', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('管理者承認画面の集計サマリが横スクロール可能で画面幅を超えない', async ({ page }) => {
        await login(page);
        await page.goto('/kamaho-shokusu/Approval/adminIndex');

        // 集計サマリが表示されている場合は table-responsive ラッパーを検証する
        // （対象期間にデータがない場合はサマリ自体が描画されない）
        if (await page.locator('.summary-table').count()) {
            const wrapper = page.locator('#summary-body .table-responsive');
            await expect(wrapper).toHaveCount(1);
        }

        // ページ全体が横スクロールを発生させていない（body が viewport 幅に収まる）
        const bodyOverflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(bodyOverflow).toBeLessThanOrEqual(0);
    });

    test('ログイン画面・ダッシュボードが 375px で横はみ出ししない', async ({ page }) => {
        await page.goto('/kamaho-shokusu/MUserInfo/login');
        let overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow).toBeLessThanOrEqual(0);

        await login(page);
        await page.goto('/kamaho-shokusu/');
        overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );
        expect(overflow).toBeLessThanOrEqual(0);
    });
});
