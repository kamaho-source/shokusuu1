'use strict';

/* ── 食事種別定数 ── */
var MEAL = { BREAKFAST: 1, LUNCH: 2, DINNER: 3, BENTO: 4 };

/* ── 昼↔弁当の排他マップ ── */
var MEAL_OPPONENT = {};
MEAL_OPPONENT[MEAL.LUNCH] = MEAL.BENTO;
MEAL_OPPONENT[MEAL.BENTO] = MEAL.LUNCH;

/* ─────────────────────────────────────────────
 * Toast 通知
 * ─────────────────────────────────────────── */
function mcgShowToast(message, type) {
    var wrap = document.getElementById('mcg-toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'mcg-toast-wrap';
        wrap.className = 'mcg-toast-wrap';
        document.body.appendChild(wrap);
    }

    var toast = document.createElement('div');
    toast.className = 'mcg-toast mcg-toast--' + (type || 'info');
    toast.textContent = message;
    wrap.appendChild(toast);

    setTimeout(function () {
        toast.classList.add('mcg-toast--hiding');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 300);
    }, 2700);
}

/* ─────────────────────────────────────────────
 * セルアニメーション
 * ─────────────────────────────────────────── */
function mcgFlashCell(td, isOn) {
    var cls = isOn ? 'mcg-cell-flash-on' : 'mcg-cell-flash-off';
    td.classList.remove('mcg-cell-flash-on', 'mcg-cell-flash-off');
    void td.offsetWidth;
    td.classList.add(cls);
    setTimeout(function () { td.classList.remove(cls); }, 550);
}

/* ─────────────────────────────────────────────
 * セル状態ヘルパー
 * ─────────────────────────────────────────── */
function mcgSetCellOn(td) {
    td.dataset.reserved = '1';
    if (!td.querySelector('.mcg-cell-check')) {
        var span = document.createElement('span');
        span.className = 'mcg-cell-check';
        td.appendChild(span);
    }
}

function mcgSetCellOff(td) {
    td.dataset.reserved = '0';
    var check = td.querySelector('.mcg-cell-check');
    if (check) check.remove();
}

/* ─────────────────────────────────────────────
 * 指定セルを検索
 * ─────────────────────────────────────────── */
function mcgFindCell(userId, roomId, date, meal) {
    return document.querySelector(
        '.mcg-toggleable[data-user-id="' + userId + '"]' +
        '[data-room-id="' + roomId + '"]' +
        '[data-date="' + date + '"]' +
        '[data-meal="' + meal + '"]'
    );
}

/* ─────────────────────────────────────────────
 * 小計セルを更新する（部屋小計・日計行）
 * ─────────────────────────────────────────── */
function mcgUpdateTotals(roomId, date, meal) {
    // 部屋小計
    var subtotalSelector = '.row-room-subtotal[data-room-id="' + roomId + '"]' +
        ' td[data-date="' + date + '"][data-meal="' + meal + '"]';
    var subtotalCell = document.querySelector(subtotalSelector);
    if (subtotalCell) {
        var roomCount = document.querySelectorAll(
            '.mcg-toggleable[data-room-id="' + roomId + '"][data-date="' + date + '"][data-meal="' + meal + '"][data-reserved="1"]'
        ).length;
        subtotalCell.textContent = roomCount > 0 ? String(roomCount) : '';
    }

    // 日計行
    var dailySelector = '.row-daily-total td[data-date="' + date + '"][data-meal="' + meal + '"]';
    var dailyCell = document.querySelector(dailySelector);
    if (dailyCell) {
        var totalCount = document.querySelectorAll(
            '.mcg-toggleable[data-date="' + date + '"][data-meal="' + meal + '"][data-reserved="1"]'
        ).length;
        dailyCell.textContent = totalCount > 0 ? String(totalCount) : '';
    }
}

/* ─────────────────────────────────────────────
 * セルトグル（Optimistic UI）
 * ─────────────────────────────────────────── */
function mcgInitToggle() {
    var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var basePath  = (window.MCG_CONFIG && window.MCG_CONFIG.basePath) ? window.MCG_CONFIG.basePath : '';

    document.querySelectorAll('.mcg-toggleable').forEach(function (td) {
        td.addEventListener('click', function () {
            if (td.dataset.mcgProcessing === '1') return;

            var userId   = td.dataset.userId;
            var roomId   = td.dataset.roomId;
            var date     = td.dataset.date;
            var meal     = parseInt(td.dataset.meal, 10);
            var reserved = td.dataset.reserved === '1';
            var newValue = reserved ? 0 : 1;

            /* スナップショット */
            var snapshot     = { reserved: td.dataset.reserved };
            var opponentCell = null;
            var opponentSnap = null;

            if (newValue === 1 && Object.prototype.hasOwnProperty.call(MEAL_OPPONENT, meal)) {
                opponentCell = mcgFindCell(userId, roomId, date, MEAL_OPPONENT[meal]);
                if (opponentCell) {
                    opponentSnap = { reserved: opponentCell.dataset.reserved };
                }
            }

            /* Optimistic update */
            td.dataset.mcgProcessing = '1';
            td.style.pointerEvents   = 'none';

            if (newValue === 1) {
                mcgSetCellOn(td);
                if (opponentCell) mcgSetCellOff(opponentCell);
            } else {
                mcgSetCellOff(td);
            }
            mcgUpdateTotals(roomId, date, meal);
            if (opponentCell) {
                mcgUpdateTotals(roomId, date, MEAL_OPPONENT[meal]);
            }

            /* API リクエスト */
            fetch(basePath + '/TReservationInfo/toggle/' + roomId, {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json',
                },
                body: JSON.stringify({
                    userId: parseInt(userId, 10),
                    date:   date,
                    meal:   meal,
                    value:  newValue,
                }),
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Non-JSON response (HTTP ' + res.status + '):', text.substring(0, 500));
                        throw new Error('サーバーエラーが発生しました (HTTP ' + res.status + ')。');
                    }
                    if (data.ok === false) {
                        throw new Error(data.message || 'エラーが発生しました。');
                    }
                    return data;
                });
            })
            .then(function () {
                mcgFlashCell(td, newValue === 1);
            })
            .catch(function (err) {
                /* 差し戻し */
                if (snapshot.reserved === '1') {
                    mcgSetCellOn(td);
                } else {
                    mcgSetCellOff(td);
                }
                if (opponentCell && opponentSnap) {
                    if (opponentSnap.reserved === '1') {
                        mcgSetCellOn(opponentCell);
                    } else {
                        mcgSetCellOff(opponentCell);
                    }
                }
                mcgUpdateTotals(roomId, date, meal);
                if (opponentCell) {
                    mcgUpdateTotals(roomId, date, MEAL_OPPONENT[meal]);
                }

                console.error('Toggle error:', err);
                mcgShowToast(err.message || '通信エラーが発生しました。', 'error');
            })
            .finally(function () {
                delete td.dataset.mcgProcessing;
                td.style.pointerEvents = '';
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    mcgInitToggle();
});
