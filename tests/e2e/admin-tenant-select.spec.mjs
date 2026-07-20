// @ts-check
import { test, expect } from '@playwright/test';

const BASE = 'http://localhost:8091';
const SYS_ADMIN_USER = 'e2e_admin';
const SYS_ADMIN_PASS = 'E2eTest#2026';

async function loginAsSysAdmin(page) {
    await page.goto(`${BASE}/kamaho-shokusu/MUserInfo/login`);
    await page.fill('input[name="c_login_account"]', SYS_ADMIN_USER);
    await page.fill('input[name="c_login_passwd"]', SYS_ADMIN_PASS);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForURL(/TReservationInfo|pages|dashboard|\/kamaho-shokusu\/?$/, { timeout: 10000 });
}

test.describe('テナント管理画面（/admin/tenants）', () => {
    let page;

    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext();
        page = await ctx.newPage();
        await loginAsSysAdmin(page);
    });

    test.afterAll(async () => {
        await page.context().close();
    });

    test('ナビバーの管理メニューにテナント管理リンクがある', async () => {
        await page.goto(`${BASE}/kamaho-shokusu/`);
        await page.waitForLoadState('networkidle');

        // ナビバーのセレクト（旧UI）が存在しないこと
        const selectEl = page.locator('select[name="tenant_id"]');
        await expect(selectEl).toHaveCount(0);

        // 管理ドロップダウンにテナント管理リンクがある
        const adminMenu = page.locator('.navbar-nav .dropdown:has(i.bi-gear)');
        await adminMenu.locator('a[data-bs-toggle="dropdown"]').click();
        const tenantLink = page.locator('a[href*="/admin/tenants"]').first();
        await expect(tenantLink).toBeVisible();
    });

    test('/admin/tenants にアクセスするとテナント一覧が表示される', async () => {
        await page.goto(`${BASE}/kamaho-shokusu/admin/tenants`);
        await page.waitForLoadState('networkidle');

        // ページタイトル
        await expect(page.locator('h1, .h5')).toContainText('テナント管理');

        // テナントカードが複数表示される
        const cards = page.locator('.card');
        const count = await cards.count();
        expect(count).toBeGreaterThanOrEqual(2);

        // テナント1「鎌倉児童ホーム」が表示される
        await expect(page.locator('body')).toContainText('鎌倉児童ホーム');
        // テナント2「綾瀬ホーム」が表示される
        await expect(page.locator('body')).toContainText('綾瀬ホーム');
    });

    test('テナントを選択するとバナーが表示される', async () => {
        await page.goto(`${BASE}/kamaho-shokusu/admin/tenants`);
        await page.waitForLoadState('networkidle');

        // 「鎌倉児童ホーム」の「このテナントで操作する」ボタンを押す
        const enterBtn = page.locator('.card').filter({ hasText: '鎌倉児童ホーム' }).locator('button[type="submit"]');
        await enterBtn.click();

        // ダッシュボードへリダイレクトされる
        await page.waitForURL(/dashboard|\/kamaho-shokusu\/?$/);

        // テナントコンテキストバナーが表示される
        const banner = page.locator('.tenant-context-banner');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText('鎌倉児童ホーム');
        await expect(banner).toContainText('を操作中');

        // バナーに「テナント一覧へ戻る」リンクがある
        const backLink = banner.locator('a[href*="/admin/tenants"]');
        await expect(backLink).toBeVisible();
    });

    test('テナント選択後は選択したテナントのデータのみ見える（部屋）', async () => {
        // 前のテストでテナント1が選択済みのはず
        const today = new Date().toISOString().slice(0, 10);
        await page.goto(`${BASE}/kamaho-shokusu/TReservationInfo/view/${today}`);
        await page.waitForLoadState('networkidle');

        const body = await page.locator('body').innerText();
        // テナント1の部屋が表示される
        expect(body).toContain('ナザレ');
        // テナント2の部屋が表示されない
        expect(body).not.toContain('号棟');
    });

    test('テナント一覧へ戻るとバナーが消え全テナントモードになる', async () => {
        // バナーから一覧へ戻る
        await page.goto(`${BASE}/kamaho-shokusu/`);
        const banner = page.locator('.tenant-context-banner');
        const backLink = banner.locator('a[href*="/admin/tenants"]');
        await backLink.click();
        await page.waitForURL(/admin\/tenants/);

        // 退出ボタンを押す
        const exitBtn = page.locator('.card').filter({ hasText: '鎌倉児童ホーム' }).locator('button[type="submit"]').filter({ hasText: '退出' });
        await exitBtn.click();
        await page.waitForURL(/admin\/tenants/);

        // 全テナントモードバナーに切り替わる（または「テナントを選択する」が出る）
        const fullBanner = page.locator('.tenant-context-banner--all');
        await expect(fullBanner).toBeVisible();

        // 全テナントモードでは全テナントのデータが見える（カードに各テナントが表示）
        await expect(page.locator('body')).toContainText('鎌倉児童ホーム');
        await expect(page.locator('body')).toContainText('綾瀬ホーム');
    });
});
