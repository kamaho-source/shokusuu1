// @ts-check
import { test } from '@playwright/test';
import { login } from './helpers/auth.mjs';

/**
 * 主要画面のスクリーンショット撮影（目視確認用）。
 * 出力先: test-results/screenshots/{sp|desktop}-{name}.png
 */

const SCREENS = [
    { name: 'login',        path: '/kamaho-shokusu/MUserInfo/login', auth: false },
    { name: 'dashboard',    path: '/kamaho-shokusu/',                auth: true },
    { name: 'reservation',  path: '/kamaho-shokusu/TReservationInfo', auth: true },
    { name: 'reservation-add', path: '/kamaho-shokusu/TReservationInfo/add', auth: true },
    { name: 'approval-admin', path: '/kamaho-shokusu/Approval/adminIndex', auth: true },
    { name: 'user-index',   path: '/kamaho-shokusu/MUserInfo/',      auth: true },
];

for (const vp of [
    { label: 'sp',      viewport: { width: 375, height: 812 } },
    { label: 'desktop', viewport: { width: 1280, height: 800 } },
]) {
    test.describe(`スクリーンショット (${vp.label})`, () => {
        test.use({ viewport: vp.viewport });

        test(`主要画面を撮影する (${vp.label})`, async ({ page }) => {
            let loggedIn = false;
            for (const screen of SCREENS) {
                if (screen.auth && !loggedIn) {
                    await login(page);
                    loggedIn = true;
                }
                await page.goto(screen.path);
                await page.waitForLoadState('networkidle');
                await page.screenshot({
                    path: `test-results/screenshots/${vp.label}-${screen.name}.png`,
                    fullPage: true,
                });
            }
        });
    });
}
