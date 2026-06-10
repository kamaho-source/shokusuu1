/**
 * ConfirmPopup — 共通確認モーダルユーティリティ
 *
 * 使い方:
 *   const ok = await window.ConfirmPopup.show('削除しますか？');
 *   const ok = await window.ConfirmPopup.show('...', { okLabel: '削除する', okColor: 'danger' });
 *   window.ConfirmPopup.showResult('更新しました。');
 *   window.ConfirmPopup.showResult('失敗しました。', false);
 */
(function (global) {
    'use strict';

    const OK_COLORS = {
        primary: '#6366f1',
        danger:  '#ef4444',
        warning: '#f59e0b',
        success: '#10b981',
    };

    const ICON_THEMES = {
        warning: { bg: '#fffbeb', stroke: '#f59e0b' },
        danger:  { bg: '#fee2e2', stroke: '#ef4444' },
        success: { bg: '#d1fae5', stroke: '#10b981' },
        primary: { bg: '#ede9fe', stroke: '#6366f1' },
    };

    let initialized = false;
    let currentResolve = null;
    let resultTimer = null;

    function ensureDOM() {
        if (initialized) return;
        initialized = true;

        const overlay = document.createElement('div');
        overlay.id = 'cp-overlay';

        const popup = document.createElement('div');
        popup.id = 'cp-popup';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-modal', 'true');
        popup.setAttribute('aria-labelledby', 'cp-message');
        popup.innerHTML =
            '<div class="cp-icon-wrap">' +
                '<svg class="cp-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">' +
                    '<circle cx="12" cy="12" r="10"/>' +
                    '<line x1="12" y1="8" x2="12" y2="12"/>' +
                    '<circle cx="12" cy="16" r=".5" fill="currentColor"/>' +
                '</svg>' +
            '</div>' +
            '<p id="cp-message"></p>' +
            '<div class="cp-actions">' +
                '<button id="cp-cancel" type="button">キャンセル</button>' +
                '<button id="cp-ok"     type="button">確定</button>' +
            '</div>';

        const result = document.createElement('div');
        result.id = 'cp-result';
        result.setAttribute('role', 'status');
        result.innerHTML =
            '<span id="cp-result-icon"></span>' +
            '<span id="cp-result-msg"></span>';

        document.body.appendChild(overlay);
        document.body.appendChild(popup);
        document.body.appendChild(result);

        document.getElementById('cp-ok').addEventListener('click', function () {
            resolve(true);
        });
        document.getElementById('cp-cancel').addEventListener('click', function () {
            resolve(false);
        });
        overlay.addEventListener('click', function () {
            resolve(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.getElementById('cp-popup').classList.contains('show')) {
                resolve(false);
            }
        });
    }

    function resolve(result) {
        hide();
        if (currentResolve) {
            const fn = currentResolve;
            currentResolve = null;
            fn(result);
        }
    }

    function hide() {
        document.getElementById('cp-overlay').classList.remove('show');
        const popup = document.getElementById('cp-popup');
        popup.classList.remove('show');
        popup.style.borderTop = '';
    }

    /**
     * 確認モーダルを表示し、ユーザーの選択を Promise で返す。
     * @param {string} message
     * @param {{ okLabel?: string, cancelLabel?: string, okColor?: 'primary'|'danger'|'warning'|'success' }} [options]
     * @returns {Promise<boolean>}
     */
    function show(message, options) {
        ensureDOM();

        const okLabel     = (options && options.okLabel)     || '確定';
        const cancelLabel = (options && options.cancelLabel) || 'キャンセル';
        const okColor     = (options && options.okColor)     || 'primary';
        const type        = (options && options.type)        || 'warning';
        const theme       = ICON_THEMES[type] || ICON_THEMES.warning;

        document.getElementById('cp-message').textContent  = message;
        document.getElementById('cp-ok').textContent       = okLabel;
        document.getElementById('cp-ok').style.background  = OK_COLORS[okColor] || OK_COLORS.primary;
        document.getElementById('cp-cancel').textContent   = cancelLabel;

        const iconWrap = document.querySelector('.cp-icon-wrap');
        const iconSvg  = document.querySelector('.cp-icon-svg');
        iconWrap.style.background = theme.bg;
        iconSvg.style.stroke      = theme.stroke;

        const popup = document.getElementById('cp-popup');
        popup.style.borderTop = type === 'danger' ? '4px solid #ef4444' : '';

        document.getElementById('cp-overlay').classList.add('show');
        popup.classList.add('show');
        document.getElementById('cp-ok').focus();

        return new Promise(function (res) {
            currentResolve = res;
        });
    }

    /**
     * 右下にトースト通知を表示する。
     * @param {string} message
     * @param {boolean} [success=true]
     */
    function showResult(message, success) {
        ensureDOM();
        if (success === undefined) success = true;

        document.getElementById('cp-result-icon').textContent = success ? '✅' : '❌';
        document.getElementById('cp-result-msg').textContent  = message;
        document.getElementById('cp-result').className        = success ? 'show success' : 'show error';

        clearTimeout(resultTimer);
        resultTimer = setTimeout(function () {
            document.getElementById('cp-result').className = '';
        }, 2500);
    }

    global.ConfirmPopup = { show: show, showResult: showResult };
})(window);
