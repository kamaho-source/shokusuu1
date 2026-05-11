'use strict';

/* ── 食事種別定数 ── */
var MEAL = { BREAKFAST: 1, LUNCH: 2, DINNER: 3, BENTO: 4 };

/* ── 排他関係マップ（ON にしたら OFF にすべき相手） ── */
var MEAL_OPPONENT = {};
MEAL_OPPONENT[MEAL.LUNCH] = MEAL.BENTO;
MEAL_OPPONENT[MEAL.BENTO] = MEAL.LUNCH;

/* ─────────────────────────────────────────────
 * Toast 通知
 * ─────────────────────────────────────────── */
function srShowToast(message, type) {
    var wrap = document.getElementById('sr-toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'sr-toast-wrap';
        wrap.className = 'sr-toast-wrap';
        document.body.appendChild(wrap);
    }

    var toast = document.createElement('div');
    toast.className = 'sr-toast sr-toast--' + (type || 'info');
    toast.textContent = message;
    wrap.appendChild(toast);

    // アニメーション終了後に要素を削除
    setTimeout(function () {
        toast.classList.add('sr-toast--hiding');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 300);
    }, 2700);
}

/* ─────────────────────────────────────────────
 * セルアニメーション（成功フラッシュ）
 * ─────────────────────────────────────────── */
function srFlashCell(td, isOn) {
    var cls = isOn ? 'sr-cell-flash-on' : 'sr-cell-flash-off';
    td.classList.remove('sr-cell-flash-on', 'sr-cell-flash-off');
    // reflow を強制してアニメーションを再起動
    void td.offsetWidth;
    td.classList.add(cls);
    setTimeout(function () { td.classList.remove(cls); }, 600);
}

/* ─────────────────────────────────────────────
 * 他部屋競合セルの無効化制御
 * ─────────────────────────────────────────── */
function srGetConflictingCells(date, meal, excludeRoomId) {
    var cells = [];
    document.querySelectorAll('.sr-toggleable').forEach(function (td) {
        if (td.dataset.date === date && td.dataset.meal === String(meal) && td.dataset.roomId !== String(excludeRoomId)) {
            cells.push(td);
        }
    });
    return cells;
}

function srDisableConflicts(date, meal, activeRoomId) {
    srGetConflictingCells(date, meal, activeRoomId).forEach(function (td) {
        if (td.dataset.reserved !== '1') {
            td.classList.add('sr-cell-disabled');
            td.dataset.srTooltip = '別の部屋で同日・同食事の予約があります';
        }
    });
}

function srEnableConflicts(date, meal) {
    document.querySelectorAll('.sr-toggleable').forEach(function (td) {
        if (td.dataset.date === date && td.dataset.meal === String(meal)) {
            td.classList.remove('sr-cell-disabled');
            delete td.dataset.srTooltip;
        }
    });
}

function srInitConflicts() {
    document.querySelectorAll('.sr-toggleable[data-reserved="1"]').forEach(function (td) {
        srDisableConflicts(td.dataset.date, td.dataset.meal, td.dataset.roomId);
    });
}

/* ─────────────────────────────────────────────
 * セル操作ヘルパー
 * ─────────────────────────────────────────── */
function srFindMealCell(userId, roomId, date, meal) {
    return document.querySelector(
        '.sr-toggleable[data-user-id="' + userId + '"]' +
        '[data-room-id="' + roomId + '"]' +
        '[data-date="' + date + '"]' +
        '[data-meal="' + meal + '"]'
    );
}

function srSetCellOn(td) {
    td.dataset.reserved = '1';
    if (!td.querySelector('.sr-cell-check')) {
        var span = document.createElement('span');
        span.className = 'sr-cell-check';
        td.appendChild(span);
    }
}

function srSetCellOff(td) {
    td.dataset.reserved = '0';
    var check = td.querySelector('.sr-cell-check');
    if (check) check.remove();
}

/* ─────────────────────────────────────────────
 * 食事セルのトグル（Optimistic UI）
 * ─────────────────────────────────────────── */
function srInitToggle() {
    var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var basePath  = (window.SR_CONFIG && window.SR_CONFIG.basePath) ? window.SR_CONFIG.basePath : '';

    document.querySelectorAll('.sr-toggleable').forEach(function (td) {
        td.addEventListener('click', function () {
            if (td.classList.contains('sr-cell-disabled')) return;
            if (td.dataset.srProcessing === '1') return;

            var userId   = td.dataset.userId;
            var roomId   = td.dataset.roomId;
            var date     = td.dataset.date;
            var meal     = parseInt(td.dataset.meal, 10);
            var reserved = td.dataset.reserved === '1';
            var newValue = reserved ? 0 : 1;

            /* ── スナップショット（失敗時の差し戻し用） ── */
            var snapshot = { reserved: td.dataset.reserved };
            var opponentCell = null;
            var opponentSnapshot = null;

            if (newValue === 1 && Object.prototype.hasOwnProperty.call(MEAL_OPPONENT, meal)) {
                opponentCell = srFindMealCell(userId, roomId, date, MEAL_OPPONENT[meal]);
                if (opponentCell) {
                    opponentSnapshot = { reserved: opponentCell.dataset.reserved };
                }
            }

            /* ── Optimistic update ── */
            td.dataset.srProcessing = '1';
            td.style.pointerEvents  = 'none';

            if (newValue === 1) {
                srSetCellOn(td);
                srDisableConflicts(date, meal, roomId);
                if (opponentCell) srSetCellOff(opponentCell);
            } else {
                srSetCellOff(td);
                var stillReserved = false;
                document.querySelectorAll('.sr-toggleable').forEach(function (other) {
                    if (other !== td && other.dataset.date === date && other.dataset.meal === String(meal) && other.dataset.reserved === '1') {
                        stillReserved = true;
                    }
                });
                if (!stillReserved) srEnableConflicts(date, meal);
            }

            /* ── API リクエスト ── */
            fetch(basePath + '/TReservationInfo/toggle/' + roomId, {
                method: 'POST',
                headers: {
                    'Content-Type':    'application/json',
                    'X-CSRF-Token':    csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':          'application/json',
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
                /* 成功: フラッシュアニメーションのみ（DOM は既に更新済み） */
                srFlashCell(td, newValue === 1);
            })
            .catch(function (err) {
                /* 失敗: Optimistic update を差し戻す */
                if (snapshot.reserved === '1') {
                    srSetCellOn(td);
                } else {
                    srSetCellOff(td);
                }
                if (opponentCell && opponentSnapshot) {
                    if (opponentSnapshot.reserved === '1') {
                        srSetCellOn(opponentCell);
                    } else {
                        srSetCellOff(opponentCell);
                    }
                }

                console.error('Toggle error:', err);
                srShowToast(err.message || '通信エラーが発生しました。', 'error');
            })
            .finally(function () {
                delete td.dataset.srProcessing;
                td.style.pointerEvents = '';
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    var isIndividual = window.SR_CONFIG && window.SR_CONFIG.isIndividual;
    if (isIndividual) {
        srInitConflicts();
        srInitToggle();
    }
});
