'use strict';

/* ── 食事種別定数 ── */
var MEAL = { BREAKFAST: 1, LUNCH: 2, DINNER: 3, BENTO: 4 };

/* ── 部屋名マップ（PHP から注入） ── */
var MCG_ROOM_NAMES = (window.MCG_CONFIG && window.MCG_CONFIG.rooms) ? window.MCG_CONFIG.rooms : {};
var MCG_BASE = (window.MCG_CONFIG && window.MCG_CONFIG.basePath) ? window.MCG_CONFIG.basePath : '';

/* ── 昼↔弁当 排他マップ ── */
var MEAL_OPPONENT = {};
MEAL_OPPONENT[MEAL.LUNCH] = MEAL.BENTO;
MEAL_OPPONENT[MEAL.BENTO] = MEAL.LUNCH;

/* ─────────────────────────────────────────────
 * Pending（未保存変更）管理
 *
 * キー: "userId|roomId|date|meal"
 * 値:   { td: HTMLElement, original: '0'|'1', desired: 0|1 }
 *
 * data-reserved は pending 状態を即反映する（排他制御・日計に使用）。
 * original は登録失敗時のロールバック用に保存する。
 * ─────────────────────────────────────────── */
var _mcgPending = new Map();

function _mcgKey(td) {
    return td.dataset.userId + '|' + td.dataset.roomId + '|' + td.dataset.date + '|' + td.dataset.meal;
}

function mcgAddPending(td, newValue) {
    var key = _mcgKey(td);
    var existing = _mcgPending.get(key);
    var original = existing ? existing.original : td.dataset.reserved;

    _mcgPending.set(key, { td: td, original: original, desired: newValue });

    /* data-reserved を pending 状態に合わせる（排他制御・日計が正確になる） */
    td.dataset.reserved = newValue === 1 ? '1' : '0';
    td.setAttribute('aria-checked', newValue === 1 ? 'true' : 'false');
    td.classList.remove('mcg-pending-on', 'mcg-pending-off');
    if (newValue === 1) {
        td.classList.add('mcg-pending-on');
        td.textContent = '●';
    } else {
        td.classList.add('mcg-pending-off');
        td.textContent = '×';
    }
}

function mcgRemovePending(td) {
    var key = _mcgKey(td);
    var entry = _mcgPending.get(key);
    if (entry) {
        /* data-reserved を元に戻す */
        td.dataset.reserved = entry.original;
        td.setAttribute('aria-checked', entry.original === '1' ? 'true' : 'false');
        _mcgPending.delete(key);
    }
    td.classList.remove('mcg-pending-on', 'mcg-pending-off');
    if (td.dataset.reserved === '1') {
        td.textContent = '1';
    } else {
        td.textContent = '';
    }
}

/* ─────────────────────────────────────────────
 * 登録ボタン更新
 * ─────────────────────────────────────────── */
function mcgUpdateRegisterBtn() {
    var btn = document.getElementById('mcg-register-btn');
    if (!btn) return;
    var count = _mcgPending.size;
    if (count > 0) {
        btn.disabled = false;
        btn.textContent = '登録 (' + count + '件)';
        btn.classList.add('has-changes');
    } else {
        btn.disabled = true;
        btn.textContent = '登録';
        btn.classList.remove('has-changes');
    }
}

/* ─────────────────────────────────────────────
 * 一括登録（Promise を返す）
 * ─────────────────────────────────────────── */
function mcgRegisterAll() {
    if (_mcgPending.size === 0) return Promise.resolve();

    var btn = document.getElementById('mcg-register-btn');
    if (btn) { btn.disabled = true; btn.textContent = '保存中…'; btn.classList.remove('has-changes'); }

    var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var entries = Array.from(_mcgPending.entries());

    return Promise.allSettled(entries.map(function (pair) {
        var key = pair[0];
        var entry = pair[1];
        var parts  = key.split('|');
        var userId = parts[0], roomId = parts[1], date = parts[2], meal = parts[3];

        return fetch(MCG_BASE + '/TReservationInfo/toggle/' + roomId, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            body: JSON.stringify({
                userId: parseInt(userId, 10),
                date:   date,
                meal:   parseInt(meal, 10),
                value:  entry.desired,
            }),
        }).then(function (res) {
            return res.text().then(function (text) {
                var data;
                try { data = JSON.parse(text); } catch (e) { throw new Error('HTTP ' + res.status); }
                if (data.ok === false) throw new Error(data.message || 'エラー');
                return key;
            });
        });
    })).then(function (results) {
        var successKeys = [];
        var failCount   = 0;

        results.forEach(function (result, i) {
            var key   = entries[i][0];
            var entry = entries[i][1];
            var parts = key.split('|');

            if (result.status === 'fulfilled') {
                successKeys.push(key);
                _mcgPending.delete(key);
                entry.td.classList.remove('mcg-pending-on', 'mcg-pending-off');
                /* data-reserved は既に desired 値になっている */
                if (entry.desired === 1) {
                    entry.td.textContent = '1';
                } else {
                    entry.td.textContent = '';
                }
                /* 保存済みセルを無効化して誤操作を防ぐ */
                entry.td.classList.add('mcg-cell-saved');
                entry.td.setAttribute('aria-disabled', 'true');
                mcgFlashCell(entry.td, entry.desired === 1);
            } else {
                failCount++;
                /* ロールバック */
                _mcgPending.delete(key);
                entry.td.dataset.reserved = entry.original;
                entry.td.classList.remove('mcg-pending-on', 'mcg-pending-off');
                if (entry.original === '1') {
                    entry.td.textContent = '1';
                    entry.td.setAttribute('aria-checked', 'true');
                } else {
                    entry.td.textContent = '';
                    entry.td.setAttribute('aria-checked', 'false');
                }
            }
        });

        /* 影響セルの日計・排他状態を再計算 */
        var seen = Object.create(null);
        results.forEach(function (result, i) {
            var entry = entries[i][1];
            var parts = entries[i][0].split('|');
            var k = parts[2] + '|' + parts[3];
            if (!seen[k]) {
                seen[k] = true;
                mcgUpdateDailyTotal(parts[2], parseInt(parts[3], 10));
                mcgSyncConflicts(parts[0], parts[2], parts[3]);
            }
        });

        if (successKeys.length > 0) {
            mcgShowToast(successKeys.length + '件を登録しました', 'success');
        }
        if (failCount > 0) {
            mcgShowToast(failCount + '件の登録に失敗しました', 'error');
        }

        mcgUpdateRegisterBtn();
    });
}

/* ─────────────────────────────────────────────
 * 排他制御（他部屋予約チェック）
 * ─────────────────────────────────────────── */

function mcgGetSiblingCells(userId, date, meal) {
    return document.querySelectorAll(
        '.mcg-toggleable[data-user-id="' + userId + '"]' +
        '[data-date="' + date + '"]' +
        '[data-meal="' + meal + '"]'
    );
}

function mcgSyncConflicts(userId, date, meal) {
    var cells = mcgGetSiblingCells(userId, date, meal);
    if (cells.length <= 1) return;

    var reservedRoomId = null;
    cells.forEach(function (cell) {
        if (cell.dataset.reserved === '1') reservedRoomId = cell.dataset.roomId;
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

/* ── コンフリクトツールチップ ── */

var _mcgConflictTip = null;

function mcgInitConflictTip() {
    document.addEventListener('mouseover', function (e) {
        var cell = e.target.closest('.mcg-cell-conflict');
        if (cell && cell.dataset.conflictMsg) {
            if (!_mcgConflictTip) {
                _mcgConflictTip = document.createElement('div');
                _mcgConflictTip.className = 'mcg-conflict-tip';
                document.body.appendChild(_mcgConflictTip);
            }
            _mcgConflictTip.textContent = cell.dataset.conflictMsg;
            _mcgConflictTip.style.display = 'block';
            _mcgConflictTip.style.left = (e.clientX + 12) + 'px';
            _mcgConflictTip.style.top  = (e.clientY - 36) + 'px';
        } else if (_mcgConflictTip) {
            _mcgConflictTip.style.display = 'none';
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_mcgConflictTip && _mcgConflictTip.style.display === 'block') {
            _mcgConflictTip.style.left = (e.clientX + 12) + 'px';
            _mcgConflictTip.style.top  = (e.clientY - 36) + 'px';
        }
    });
    document.addEventListener('mouseleave', function () {
        if (_mcgConflictTip) _mcgConflictTip.style.display = 'none';
    }, true);
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
    toast.className   = 'mcg-toast mcg-toast--' + (type || 'info');
    toast.textContent = message;
    wrap.appendChild(toast);
    setTimeout(function () {
        toast.classList.add('mcg-toast--hiding');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 250);
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
    if (cell) cell.textContent = count > 0 ? String(count) : '';
}

/* ─────────────────────────────────────────────
 * セルクリック（Pending モード）
 * ─────────────────────────────────────────── */
function mcgInitToggle() {
    document.querySelectorAll('.mcg-toggleable').forEach(function (td) {
        td.addEventListener('keydown', function (e) {
            if (td.classList.contains('mcg-cell-saved')) return;
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); td.click(); }
        });

        td.addEventListener('click', function () {
            if (td.classList.contains('is-past')) return;
            if (td.classList.contains('mcg-cell-conflict')) return;
            if (td.classList.contains('mcg-cell-saved')) return;

            var userId = td.dataset.userId;
            var roomId = td.dataset.roomId;
            var date   = td.dataset.date;
            var meal   = parseInt(td.dataset.meal, 10);

            /* 現在の有効な予約状態（pending 込み） */
            var currentReserved = td.dataset.reserved === '1';
            var newValue        = currentReserved ? 0 : 1;

            /* 昼↔弁当 排他: 同部屋の対立セルを pending OFF にする */
            if (newValue === 1 && Object.prototype.hasOwnProperty.call(MEAL_OPPONENT, meal)) {
                var opponentMeal = MEAL_OPPONENT[meal];
                var opponentTd   = mcgFindCell(userId, roomId, date, opponentMeal);
                if (opponentTd && opponentTd.dataset.reserved === '1') {
                    var opponentKey     = _mcgKey(opponentTd);
                    var opponentEntry   = _mcgPending.get(opponentKey);
                    var opponentOriginal = opponentEntry ? opponentEntry.original : opponentTd.dataset.reserved;

                    if (opponentOriginal === '0') {
                        /* 元々 OFF だったものが pending ON になっていた → 単純に pending 解除 */
                        mcgRemovePending(opponentTd);
                    } else {
                        /* 元々 ON → pending OFF */
                        mcgAddPending(opponentTd, 0);
                    }
                    mcgUpdateDailyTotal(date, opponentMeal);
                    mcgSyncConflicts(userId, date, String(opponentMeal));
                }
            }

            /* 元の状態に戻るなら pending 解除、それ以外は pending 追加 */
            var key      = _mcgKey(td);
            var entry    = _mcgPending.get(key);
            var original = entry ? entry.original : td.dataset.reserved;

            if (String(newValue) === original) {
                mcgRemovePending(td);
            } else {
                mcgAddPending(td, newValue);
            }

            mcgUpdateDailyTotal(date, meal);
            mcgSyncConflicts(userId, date, td.dataset.meal);
            mcgUpdateRegisterBtn();
        });
    });
}

/* ─────────────────────────────────────────────
 * ページ離脱ガード（未保存あり）
 * ─────────────────────────────────────────── */
function mcgInitBeforeUnload() {
    window.addEventListener('beforeunload', function (e) {
        if (_mcgPending.size > 0) {
            e.preventDefault();
            e.returnValue = '登録されていない変更があります。ページを離れますか？';
        }
    });
}

/* ─────────────────────────────────────────────
 * ナビリンク（前4週・翌4週・今日）クリック時の自動保存
 * ─────────────────────────────────────────── */
function mcgInitNavSave() {
    document.querySelectorAll('a.mcg-nav-btn').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (_mcgPending.size === 0) return;
            e.preventDefault();
            var href = this.href;
            mcgRegisterAll().then(function () {
                window.location.href = href;
            });
        });
    });
}

function mcgInitHeaderTooltip() {
    var tip = document.createElement('div');
    tip.className = 'mcg-tooltip';
    tip.style.opacity = '0';
    document.body.appendChild(tip);

    document.querySelectorAll('.mcg-grid thead th[data-tooltip]').forEach(function (th) {
        th.addEventListener('mouseenter', function (e) {
            tip.textContent = th.dataset.tooltip;
            tip.style.opacity = '1';
            _mcgPositionTooltip(tip, th);
        });
        th.addEventListener('mousemove', function () {
            _mcgPositionTooltip(tip, th);
        });
        th.addEventListener('mouseleave', function () {
            tip.style.opacity = '0';
        });
    });
}

function _mcgPositionTooltip(tip, anchor) {
    var rect = anchor.getBoundingClientRect();
    var left = rect.left + rect.width / 2 - tip.offsetWidth / 2;
    // 画面右端からはみ出さないよう補正
    left = Math.min(left, window.innerWidth - tip.offsetWidth - 8);
    left = Math.max(left, 8);
    tip.style.left = left + 'px';
    tip.style.top  = (rect.bottom + 6) + 'px';
}

/* ─────────────────────────────────────────────
 * 祝日セルに is-holiday クラスを付与
 * ─────────────────────────────────────────── */
function mcgApplyHolidays() {
    if (!window.JapaneseHolidays) return;
    document.querySelectorAll('[data-date]').forEach(function (el) {
        var d = new Date(el.dataset.date + 'T00:00:00');
        if (JapaneseHolidays.isHoliday(d)) {
            el.classList.add('is-holiday');
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    mcgApplyHolidays();
    mcgInitConflicts();
    mcgInitConflictTip();
    mcgInitToggle();
    mcgInitBeforeUnload();
    mcgInitNavSave();
    mcgInitHeaderTooltip();
    mcgUpdateRegisterBtn();

    var btn = document.getElementById('mcg-register-btn');
    if (btn) btn.addEventListener('click', mcgRegisterAll);
});
