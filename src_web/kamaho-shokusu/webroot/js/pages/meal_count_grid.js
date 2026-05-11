'use strict';

/* ── 食事種別定数 ── */
var MEAL = { BREAKFAST: 1, LUNCH: 2, DINNER: 3, BENTO: 4 };

/* ── 部屋名マップ（PHP から注入） ── */
var MCG_ROOM_NAMES = (window.MCG_CONFIG && window.MCG_CONFIG.rooms) ? window.MCG_CONFIG.rooms : {};

/* ── 昼↔弁当 排他マップ ── */
var MEAL_OPPONENT = {};
MEAL_OPPONENT[MEAL.LUNCH] = MEAL.BENTO;
MEAL_OPPONENT[MEAL.BENTO] = MEAL.LUNCH;

/* ─────────────────────────────────────────────
 * 排他制御（他部屋予約チェック）
 * ─────────────────────────────────────────── */

/**
 * 同一ユーザー・日付・食種のセルを全部屋分取得
 */
function mcgGetSiblingCells(userId, date, meal) {
    return document.querySelectorAll(
        '.mcg-toggleable[data-user-id="' + userId + '"]' +
        '[data-date="' + date + '"]' +
        '[data-meal="' + meal + '"]'
    );
}

/**
 * 指定ユーザー・日付・食種の排他状態を更新する。
 * 他部屋で予約済みのセルを conflict 状態にし、自身の予約がなければ解除する。
 */
function mcgSyncConflicts(userId, date, meal) {
    var cells = mcgGetSiblingCells(userId, date, meal);
    if (cells.length <= 1) return; // 複数部屋にまたがっていなければ不要

    var reservedRoomId = null;
    cells.forEach(function (cell) {
        if (cell.dataset.reserved === '1') {
            reservedRoomId = cell.dataset.roomId;
        }
    });

    cells.forEach(function (cell) {
        if (reservedRoomId !== null && cell.dataset.roomId !== reservedRoomId) {
            var roomName = MCG_ROOM_NAMES[reservedRoomId] || ('部屋' + reservedRoomId);
            cell.classList.add('mcg-cell-conflict');
            cell.dataset.conflictMsg = roomName + 'で予約済みのため選択できません';
        } else {
            cell.classList.remove('mcg-cell-conflict');
            delete cell.dataset.conflictMsg;
        }
    });
}

/**
 * ページ初期表示時に全セルの排他状態を一括設定する
 */
function mcgInitConflicts() {
    var seen = Object.create(null);
    document.querySelectorAll('.mcg-toggleable').forEach(function (cell) {
        var key = cell.dataset.userId + '|' + cell.dataset.date + '|' + cell.dataset.meal;
        if (!seen[key]) {
            seen[key] = true;
            mcgSyncConflicts(cell.dataset.userId, cell.dataset.date, cell.dataset.meal);
        }
    });
}

/* ── コンフリクトツールチップ（フローティング） ── */

var _mcgConflictTip = null;

function mcgShowConflictTip(msg, x, y) {
    if (!_mcgConflictTip) {
        _mcgConflictTip = document.createElement('div');
        _mcgConflictTip.className = 'mcg-conflict-tip';
        document.body.appendChild(_mcgConflictTip);
    }
    _mcgConflictTip.textContent = msg;
    _mcgConflictTip.style.display = 'block';
    _mcgConflictTip.style.left = (x + 12) + 'px';
    _mcgConflictTip.style.top  = (y - 36) + 'px';
}

function mcgHideConflictTip() {
    if (_mcgConflictTip) _mcgConflictTip.style.display = 'none';
}

function mcgInitConflictTip() {
    document.addEventListener('mouseover', function (e) {
        var cell = e.target.closest('.mcg-cell-conflict');
        if (cell && cell.dataset.conflictMsg) {
            mcgShowConflictTip(cell.dataset.conflictMsg, e.clientX, e.clientY);
        } else {
            mcgHideConflictTip();
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_mcgConflictTip && _mcgConflictTip.style.display === 'block') {
            _mcgConflictTip.style.left = (e.clientX + 12) + 'px';
            _mcgConflictTip.style.top  = (e.clientY - 36) + 'px';
        }
    });
    document.addEventListener('mouseleave', mcgHideConflictTip, true);
}

/* ─────────────────────────────────────────────
 * Toast 通知
 * ─────────────────────────────────────────── */
function mcgShowToast(message, type) {
    var wrap = document.getElementById('mcg-toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id        = 'mcg-toast-wrap';
        wrap.className = 'mcg-toast-wrap';
        document.body.appendChild(wrap);
    }
    var toast = document.createElement('div');
    toast.className  = 'mcg-toast mcg-toast--' + (type || 'info');
    toast.textContent = message;
    wrap.appendChild(toast);
    setTimeout(function () {
        toast.classList.add('mcg-toast--hiding');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 250);
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
    setTimeout(function () { td.classList.remove(cls); }, 500);
}

/* ─────────────────────────────────────────────
 * セル状態ヘルパー（値は「1」 or 空）
 * ─────────────────────────────────────────── */
function mcgSetCellOn(td) {
    td.dataset.reserved = '1';
    td.textContent = '1';
    td.setAttribute('aria-checked', 'true');
}

function mcgSetCellOff(td) {
    td.dataset.reserved = '0';
    td.textContent = '';
    td.setAttribute('aria-checked', 'false');
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
 * 日計行を再集計
 * ─────────────────────────────────────────── */
function mcgUpdateDailyTotal(date, meal) {
    var count = document.querySelectorAll(
        '.mcg-toggleable[data-date="' + date + '"][data-meal="' + meal + '"][data-reserved="1"]'
    ).length;

    var cell = document.querySelector(
        '.row-daily-total td[data-date="' + date + '"][data-meal="' + meal + '"]'
    );
    if (cell) {
        cell.textContent = count > 0 ? String(count) : '';
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
        /* キーボード操作（Space/Enter）でも動作 */
        td.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                td.click();
            }
        });

        td.addEventListener('click', function () {
            if (td.dataset.mcgProcessing === '1') return;
            if (td.classList.contains('mcg-cell-conflict')) return; // 他部屋予約済み

            var userId   = td.dataset.userId;
            var roomId   = td.dataset.roomId;
            var date     = td.dataset.date;
            var meal     = parseInt(td.dataset.meal, 10);
            var reserved = td.dataset.reserved === '1';
            var newValue = reserved ? 0 : 1;

            /* スナップショット */
            var snapshot     = { reserved: td.dataset.reserved, text: td.textContent };
            var opponentCell = null;
            var opponentSnap = null;

            if (newValue === 1 && Object.prototype.hasOwnProperty.call(MEAL_OPPONENT, meal)) {
                opponentCell = mcgFindCell(userId, roomId, date, MEAL_OPPONENT[meal]);
                if (opponentCell) {
                    opponentSnap = { reserved: opponentCell.dataset.reserved, text: opponentCell.textContent };
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

            mcgUpdateDailyTotal(date, meal);
            if (opponentCell) {
                mcgUpdateDailyTotal(date, MEAL_OPPONENT[meal]);
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
                    try { data = JSON.parse(text); }
                    catch (e) {
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
                mcgSyncConflicts(userId, date, meal);
                if (opponentCell) mcgSyncConflicts(userId, date, MEAL_OPPONENT[meal]);
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
                mcgUpdateDailyTotal(date, meal);
                if (opponentCell) {
                    mcgUpdateDailyTotal(date, MEAL_OPPONENT[meal]);
                }
                mcgSyncConflicts(userId, date, meal);
                if (opponentCell) mcgSyncConflicts(userId, date, MEAL_OPPONENT[meal]);
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

/* ─────────────────────────────────────────────
 * 数式バーのセル参照を更新
 * ─────────────────────────────────────────── */
function mcgInitCellRef() {
    var cellRefEl = document.querySelector('.excel-formulabar .cell-ref');
    document.querySelectorAll('.mcg-toggleable').forEach(function (td, idx) {
        td.addEventListener('focus', function () {
            if (cellRefEl) {
                var col = String.fromCharCode(67 + (idx % 100)); // C始まり（仮）
                var row = td.closest('tr')?.rowIndex ?? 7;
                cellRefEl.textContent = col + row;
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    mcgInitConflicts();
    mcgInitConflictTip();
    mcgInitToggle();
    mcgInitCellRef();
});
