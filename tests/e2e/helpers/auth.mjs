// @ts-check

/**
 * Playwright E2E 共通ヘルパー。
 *
 * 認証情報は環境変数で上書き可能:
 *   E2E_USER / E2E_PASS
 *   E2E_BASE_PATH … アプリのパスプレフィックス
 *     - bin/cake server: ''（既定）
 *     - Apache 等: '/kamaho-shokusu'
 *   E2E_BLOCK_LEADER_USER / E2E_BLOCK_LEADER_PASS（任意）
 *   E2E_BASE_URL（playwright.config.mjs）
 */

/** @returns {string} 先頭スラッシュ付き、末尾スラッシュなし。空なら '' */
export function basePath() {
    const raw = process.env.E2E_BASE_PATH;
    if (raw === undefined || raw === null) {
        // cake server 向け既定。本番相当は E2E_BASE_PATH=/kamaho-shokusu
        return '';
    }
    const trimmed = String(raw).replace(/\/+$/, '');
    if (trimmed === '' || trimmed === '/') {
        return '';
    }
    return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
}

/** @param {string} path */
export function appPath(path) {
    const p = path.startsWith('/') ? path : `/${path}`;
    return `${basePath()}${p}`;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ user?: string, pass?: string }} [opts]
 */
export async function login(page, opts = {}) {
    const user = opts.user ?? process.env.E2E_USER ?? 'e2e_admin';
    const pass = opts.pass ?? process.env.E2E_PASS ?? 'E2eTest#2026';
    await page.goto(appPath('/MUserInfo/login'));
    await page.fill('input[name="c_login_account"]', user);
    await page.fill('input[name="c_login_passwd"]', pass);

    await Promise.all([
        page.waitForURL(
            (url) => {
                const path = url.pathname;
                if (path.includes('/MUserInfo/login')) {
                    return false;
                }
                return (
                    path.includes('TReservationInfo') ||
                    path.includes('/pages') ||
                    path.includes('dashboard') ||
                    path === appPath('/') ||
                    path === `${basePath()}/` ||
                    path === '/'
                );
            },
            { timeout: 20000 }
        ),
        page.click('button[type="submit"], input[type="submit"]'),
    ]).catch(async (err) => {
        const flash = (await page.locator('.message, .alert, #flashMessage').allTextContents()).join(' ').trim();
        const stillLogin = page.url().includes('/MUserInfo/login');
        throw new Error(
            `ログイン失敗 (user=${user}). stillOnLogin=${stillLogin} url=${page.url()} flash=${flash || '(none)'} cause=${err.message}`
        );
    });
}

/**
 * 予約画面に遷移し CSRF トークンを取得する。
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
export async function getCsrfToken(page) {
    await page.goto(appPath('/TReservationInfo'));
    const token = await page.locator('head meta[name="csrfToken"]').first().getAttribute('content');
    if (!token) {
        throw new Error('CSRF トークンを取得できませんでした');
    }
    return token;
}

/**
 * Date をローカルタイムゾーンの YYYY-MM-DD に変換する。
 * @param {Date} [d]
 * @returns {string}
 */
export function formatLocalYmd(d = new Date()) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * @param {number} daysFromToday
 * @returns {string}
 */
export function localDatePlusDays(daysFromToday) {
    const d = new Date();
    d.setHours(12, 0, 0, 0);
    d.setDate(d.getDate() + daysFromToday);
    return formatLocalYmd(d);
}

/**
 * ログイン済み cookie 付きで JSON API を呼ぶ。
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {{ method?: string, body?: unknown, csrf?: string }} [opts]
 */
export async function apiJson(page, path, opts = {}) {
    const method = opts.method ?? 'GET';
    const csrf = opts.csrf ?? (await getCsrfToken(page));
    const fullPath = path.startsWith('http') ? path : appPath(path);
    return page.evaluate(
        async ({ path, method, body, csrf }) => {
            const headers = {
                Accept: 'application/json',
                'X-CSRF-Token': csrf,
            };
            /** @type {RequestInit} */
            const init = { method, credentials: 'same-origin', headers };
            if (body !== undefined) {
                headers['Content-Type'] = 'application/json; charset=utf-8';
                init.body = JSON.stringify(body);
            }
            const res = await fetch(path, init);
            const text = await res.text();
            let json = null;
            try {
                json = text ? JSON.parse(text) : null;
            } catch {
                json = { _raw: text };
            }
            return { status: res.status, json };
        },
        { path: fullPath, method, body: opts.body, csrf }
    );
}

/**
 * カレンダー設定から部屋 ID を取得する。取れなければ null。
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<number|null>}
 */
export async function resolveRoomId(page) {
    await page.goto(appPath('/TReservationInfo'));
    return page.evaluate(() => {
        const cfg = window.__TRESP || {};
        if (cfg.calRoomId != null && Number(cfg.calRoomId) > 0) {
            return Number(cfg.calRoomId);
        }
        if (cfg.primaryRoomId != null && Number(cfg.primaryRoomId) > 0) {
            return Number(cfg.primaryRoomId);
        }
        if (cfg.roomId != null && Number(cfg.roomId) > 0) {
            return Number(cfg.roomId);
        }
        const names = cfg.availableRoomNames || cfg.roomNames || {};
        const ids = Object.keys(names).map(Number).filter((n) => n > 0);
        return ids.length ? ids[0] : null;
    });
}
