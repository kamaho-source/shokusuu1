// @ts-check
import { test, expect } from '@playwright/test';

/**
 * ログインヘルパー
 * E2E_USER / E2E_PASS 環境変数で認証情報を渡す。
 */
async function login(page) {
    const user = process.env.E2E_USER ?? 'admin';
    const pass = process.env.E2E_PASS ?? 'admin';
    await page.goto('/kamaho-shokusu/users/login');
    await page.fill('input[name="username"]', user);
    await page.fill('input[name="password"]', pass);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/(index|home|dashboard)/);
}

test.describe('予約フォーム - 集団予約', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('集団予約: 部屋選択で利用者一覧が表示される', async ({ page }) => {
        const today = new Date().toISOString().slice(0, 10);
        await page.goto(`/kamaho-shokusu/TReservationInfo/add?date=${today}`);

        // 集団タブを選択
        const typeSelect = page.locator('#c_reservation_type');
        await typeSelect.selectOption('2');

        // 部屋選択プルダウンが見える
        const roomSelect = page.locator('#room-select');
        await expect(roomSelect).toBeVisible();

        // 最初の部屋を選択
        const firstOption = roomSelect.locator('option').nth(1);
        const roomValue = await firstOption.getAttribute('value');
        if (!roomValue) test.skip('部屋オプションなし');
        await roomSelect.selectOption(roomValue);

        // 利用者テーブルが表示され、1行以上ある（もしくは「いません」メッセージ）
        const userTable = page.locator('#user-selection-table');
        await expect(userTable).toBeVisible({ timeout: 5000 });
        const rowCount = await userTable.locator('tbody tr').count();
        expect(rowCount).toBeGreaterThan(0);
    });

    test('集団予約: 予約タイプ切替で個人/集団セクションが正しく切り替わる', async ({ page }) => {
        const today = new Date().toISOString().slice(0, 10);
        await page.goto(`/kamaho-shokusu/TReservationInfo/add?date=${today}`);

        const typeSelect = page.locator('#c_reservation_type');
        const roomTable  = page.locator('#room-selection-table');
        const groupGroup = page.locator('#room-select-group');

        // 初期状態: 個人 (type=1)
        await typeSelect.selectOption('1');
        await expect(roomTable).toBeVisible();
        await expect(groupGroup).toBeHidden();

        // 集団に切り替え
        await typeSelect.selectOption('2');
        await expect(roomTable).toBeHidden();
        await expect(groupGroup).toBeVisible();
    });
});

test.describe('予約フォーム - quickDayModal (インデックス画面)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('モーダルから集団予約: 部屋選択で利用者一覧が取得される', async ({ page }) => {
        const today = new Date().toISOString().slice(0, 10);
        await page.goto(`/kamaho-shokusu/TReservationInfo/index?date=${today}`);

        // モーダルを開くボタンを探す（予約追加）
        const addBtn = page.locator('[data-action="add"], [data-modal-url*="add"], .btn-add-reservation').first();
        if (!(await addBtn.count())) {
            test.skip('予約追加ボタンが見つかりません');
        }
        await addBtn.click();

        // モーダルが開くのを待つ
        const modal = page.locator('#quickDayModal');
        await expect(modal).toBeVisible({ timeout: 5000 });

        // フォームが読み込まれるのを待つ
        const typeSelect = modal.locator('#c_reservation_type');
        await expect(typeSelect).toBeVisible({ timeout: 5000 });

        // 集団を選択
        await typeSelect.selectOption('2');

        // 部屋選択
        const roomSelect = modal.locator('#room-select');
        await expect(roomSelect).toBeVisible();
        const firstOption = roomSelect.locator('option').nth(1);
        const roomValue = await firstOption.getAttribute('value');
        if (!roomValue) test.skip('部屋オプションなし');
        await roomSelect.selectOption(roomValue);

        // 利用者テーブルが visible になる
        const userTable = modal.locator('#user-selection-table');
        await expect(userTable).toBeVisible({ timeout: 5000 });
        const rowCount = await userTable.locator('tbody tr').count();
        expect(rowCount).toBeGreaterThan(0);
    });
});
