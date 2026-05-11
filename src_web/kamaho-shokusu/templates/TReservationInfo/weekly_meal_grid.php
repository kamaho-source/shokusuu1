<?php
/**
 * 食数予約 週次 Excel グリッド画面（7日間）
 *
 * @var \App\View\AppView $this
 * @var array  $allRooms              [roomId => roomName]
 * @var array  $gridData              {dates, meals, rooms, dailyTotals}
 * @var string $weekMondayStr         YYYY-MM-DD
 * @var string $periodLabel           表示期間ラベル
 * @var \DateTimeImmutable $prevMonday
 * @var \DateTimeImmutable $nextMonday
 * @var bool   $canGoPrev
 * @var bool   $canGoNext
 * @var bool   $isAdmin
 * @var bool   $canViewAll
 * @var int    $loginUserId
 * @var string $loginName
 * @var array  $loginUserWeeklyTotals [mealType => count]
 * @var array  $loginUserRooms        [roomId => roomName]
 * @var string $basePath
 */

$this->assign('title', '週次食数予約グリッド');

$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
$basePath  = $this->request->getAttribute('base') ?? '';

$dates       = $gridData['dates']       ?? [];
$meals       = $gridData['meals']       ?? [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'];
$roomsData   = $gridData['rooms']       ?? [];
$dailyTotals = $gridData['dailyTotals'] ?? [];

$today = date('Y-m-d');
$dow   = ['日', '月', '火', '水', '木', '金', '土'];

// URL生成ヘルパー
$makeUrl = function (array $params) use ($weekMondayStr, $basePath): string {
    $p  = array_merge(['week' => $weekMondayStr], $params);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
    return $basePath . '/TReservationInfo/weekly-meal-grid' . ($qs ? '?' . $qs : '');
};

$prevUrl  = $makeUrl(['week' => $prevMonday->format('Y-m-d')]);
$nextUrl  = $makeUrl(['week' => $nextMonday->format('Y-m-d')]);
$todayUrl = $makeUrl(['week' => date('Y-m-d', strtotime('monday this week'))]);

// ログインユーザーの週合計
$loginUserWeekTotal = array_sum($loginUserWeeklyTotals);

// アバターカラーパレット（userId の剰余で割り当て）
$avatarColors = [
    '#4472c4', '#ed7d31', '#a9d18e', '#ffc000',
    '#5b9bd5', '#70ad47', '#7030a0', '#c00000',
    '#0070c0', '#375623',
];
$getAvatarColor = fn(int $uid): string => $avatarColors[$uid % count($avatarColors)];

// 週の総食数
$weekTotals = array_fill_keys(array_keys($meals), 0);
foreach ($dailyTotals as $dTotals) {
    foreach ($dTotals as $mt => $cnt) {
        $weekTotals[$mt] = ($weekTotals[$mt] ?? 0) + (int)$cnt;
    }
}
$weekGrandTotal = array_sum($weekTotals);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>週次食数予約グリッド — 食数管理</title>
    <meta name="csrfToken" content="<?= h($csrfToken) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $this->Html->css('pages/weekly_meal_grid.css') ?>
    <script>
        window.WMG_CONFIG = {
            basePath: <?= json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            rooms: <?= json_encode($allRooms, JSON_UNESCAPED_UNICODE) ?>,
        };
    </script>
</head>
<body>

<div class="excel-window">

    <!-- タイトルバー -->
    <div class="excel-titlebar">
        <span class="app-icon">X</span>
        <span class="title-text">週次予約.xlsx &mdash; 食数管理</span>
        <span class="user-badge" title="<?= h($loginName) ?>"><?= h(mb_substr($loginName, 0, 1)) ?></span>
        <span style="font-size:12px;margin-left:4px"><?= h($loginName) ?></span>
    </div>

    <!-- リボンタブ -->
    <div class="excel-ribbon-tabs">
        <button>ファイル</button>
        <button class="active">ホーム</button>
        <button>挿入</button>
        <button>データ</button>
        <button>表示</button>
        <button>ヘルプ</button>
    </div>
    <div class="excel-ribbon"></div>

    <!-- 数式バー -->
    <div class="excel-formulabar">
        <span class="cell-ref">A1</span>
        <span class="fx-label">fx</span>
        <span class="formula-text">=SUMIF(B:B,A1,C:C)</span>
    </div>

    <!-- ツールバー（期間ナビ） -->
    <div class="wmg-toolbar">
        <span class="wmg-toolbar-label">週</span>
        <span class="wmg-period-label-text"><?= h($periodLabel) ?></span>

        <?php if ($canGoPrev): ?>
            <a href="<?= h($prevUrl) ?>" class="wmg-nav-btn">◄ 前週</a>
        <?php else: ?>
            <button class="wmg-nav-btn" disabled>◄ 前週</button>
        <?php endif; ?>

        <a href="<?= h($todayUrl) ?>" class="wmg-nav-btn today">今週</a>

        <?php if ($canGoNext): ?>
            <a href="<?= h($nextUrl) ?>" class="wmg-nav-btn">翌週 ►</a>
        <?php else: ?>
            <button class="wmg-nav-btn" disabled>翌週 ►</button>
        <?php endif; ?>

        <div class="wmg-toolbar-spacer"></div>
        <a href="<?= h($basePath . '/TReservationInfo/meal-count-grid') ?>"
           class="wmg-nav-btn" style="font-size:11px;">4週間ビュー</a>
    </div>

    <!-- ユーザー情報カード -->
    <div class="wmg-user-card">
        <div class="wmg-avatar-lg"><?= h(mb_substr($loginName, 0, 1)) ?></div>

        <div class="wmg-user-info">
            <div class="wmg-user-name"><?= h($loginName) ?></div>
            <div class="wmg-room-badges">
                <?php foreach ($loginUserRooms as $rname): ?>
                    <span class="wmg-room-badge"><?= h($rname) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="wmg-card-divider"></div>

        <div>
            <div class="wmg-totals-label">今週の予約数</div>
            <div class="wmg-totals-items">
                <?php foreach ($meals as $mt => $ml): ?>
                    <div class="wmg-total-item">
                        <span class="wmg-total-meal-label"><?= h($ml) ?></span>
                        <span class="wmg-total-val"><?= h((int)($loginUserWeeklyTotals[$mt] ?? 0)) ?></span>
                    </div>
                    <span class="wmg-total-sep">|</span>
                <?php endforeach; ?>
                <div class="wmg-total-item sum">
                    <span class="wmg-total-meal-label">合計</span>
                    <span class="wmg-total-val"><?= h($loginUserWeekTotal) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- メインコンテンツ -->
    <div class="excel-content">
        <div class="excel-body">

            <div class="wmg-grid-wrap">
            <?php if (empty($roomsData)): ?>
                <div style="padding:24px;color:#666">表示対象のデータがありません。</div>
            <?php else: ?>
                <table class="wmg-grid" role="grid">
                    <thead>
                        <!-- 日付ヘッダー -->
                        <tr class="header-dates">
                            <th class="col-room">部屋</th>
                            <th class="col-name">氏名</th>
                            <?php foreach ($dates as $d):
                                $dt     = new \DateTimeImmutable($d);
                                $dowIdx = (int)$dt->format('w');
                                $cls    = 'date-group'
                                    . ($d === $today  ? ' is-today'    : '')
                                    . ($dowIdx === 6  ? ' is-saturday' : '')
                                    . ($dowIdx === 0  ? ' is-sunday'   : '');
                            ?>
                                <th colspan="<?= count($meals) ?>" class="<?= h($cls) ?>">
                                    <?= h($dt->format('n/j')) ?><br>
                                    <small><?= h($dow[$dowIdx]) ?></small>
                                </th>
                            <?php endforeach; ?>
                        </tr>

                        <!-- 食事種別ヘッダー -->
                        <tr class="header-meals">
                            <th class="col-room"></th>
                            <th class="col-name"></th>
                            <?php foreach ($dates as $d):
                                $dowIdx  = (int)(new \DateTimeImmutable($d))->format('w');
                                $isToday = $d === $today;
                                $isSat   = $dowIdx === 6;
                                $isSun   = $dowIdx === 0;
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $cls = ($first ? 'meal-first ' : '')
                                        . ($isToday ? 'is-today '    : '')
                                        . ($isSat   ? 'is-saturday ' : '')
                                        . ($isSun   ? 'is-sunday'    : '');
                                    $first = false;
                            ?>
                                <th class="<?= h(trim($cls)) ?>"><?= h($mealLabel) ?></th>
                            <?php endforeach; endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($roomsData as $roomId => $roomInfo):
                            $roomName = $roomInfo['name'];
                            $users    = $roomInfo['users'];
                            $grid     = $roomInfo['grid'];
                        ?>

                        <?php foreach ($users as $u):
                            $uid       = (int)$u['id'];
                            $isMe      = $uid === $loginUserId;
                            $color     = $getAvatarColor($uid);
                            $initial   = mb_substr($u['name'], 0, 1);
                            $trClass   = $isMe ? 'is-login-user' : '';
                        ?>
                        <tr class="<?= h($trClass) ?>"
                            data-user-id="<?= h($uid) ?>"
                            data-room-id="<?= h($roomId) ?>">
                            <td class="col-room" title="<?= h($roomName) ?>"><?= h($roomName) ?></td>
                            <td class="col-name" title="<?= h($u['name']) ?>">
                                <div class="wmg-name-cell">
                                    <div class="wmg-avatar-sm"
                                         style="background:<?= h($color) ?>"><?= h($initial) ?></div>
                                    <span class="wmg-name-text"><?= h($u['name']) ?></span>
                                </div>
                            </td>
                            <?php foreach ($dates as $d):
                                $dt      = new \DateTimeImmutable($d);
                                $dowIdx  = (int)$dt->format('w');
                                $isToday = $d === $today;
                                $isSat   = $dowIdx === 6;
                                $isSun   = $dowIdx === 0;
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $reserved = !empty($grid[$uid][$d][$mealType]);
                                    $tdClass  = 'cell-meal wmg-toggleable'
                                        . ($first   ? ' meal-first'   : '')
                                        . ($isToday ? ' is-today'     : '')
                                        . ($isSat   ? ' is-saturday'  : '')
                                        . ($isSun   ? ' is-sunday'    : '');
                                    $first = false;
                            ?>
                                <td class="<?= h($tdClass) ?>"
                                    data-user-id="<?= h($uid) ?>"
                                    data-room-id="<?= h($roomId) ?>"
                                    data-date="<?= h($d) ?>"
                                    data-meal="<?= h($mealType) ?>"
                                    data-reserved="<?= $reserved ? '1' : '0' ?>"
                                    title="<?= h($u['name'] . ' ' . $d . ' ' . $mealLabel) ?>"
                                    role="checkbox"
                                    aria-checked="<?= $reserved ? 'true' : 'false' ?>"
                                    tabindex="0"
                                ><?= $reserved ? '1' : '' ?></td>
                            <?php endforeach; endforeach; ?>
                        </tr>
                        <?php endforeach; ?>

                        <?php endforeach; ?>

                        <!-- ログインユーザーの週計行 -->
                        <tr class="row-login-weekly-total">
                            <td class="col-room"><?= h(mb_substr($loginName, 0, 6)) ?></td>
                            <td class="col-name">週計</td>
                            <?php foreach ($dates as $d):
                                $first = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $cls = ($first ? 'meal-first ' : '');
                                    $first = false;
                            ?>
                                <td class="<?= h(trim($cls)) ?>"
                                    data-wmy-date="<?= h($d) ?>"
                                    data-wmy-meal="<?= h($mealType) ?>"></td>
                            <?php endforeach; endforeach; ?>
                        </tr>

                        <!-- 日計行 -->
                        <tr class="row-daily-total">
                            <td class="col-room"></td>
                            <td class="col-name">日計</td>
                            <?php foreach ($dates as $d):
                                $dowIdx  = (int)(new \DateTimeImmutable($d))->format('w');
                                $isToday = $d === $today;
                                $isSat   = $dowIdx === 6;
                                $isSun   = $dowIdx === 0;
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $total = (int)($dailyTotals[$d][$mealType] ?? 0);
                                    $cls   = ($first   ? 'meal-first '   : '')
                                           . ($isToday ? 'is-today '     : '')
                                           . ($isSat   ? 'is-saturday '  : '')
                                           . ($isSun   ? 'is-sunday'     : '');
                                    $first = false;
                            ?>
                                <td class="<?= h(trim($cls)) ?>"
                                    data-date="<?= h($d) ?>"
                                    data-meal="<?= h($mealType) ?>"
                                ><?= $total > 0 ? h($total) : '' ?></td>
                            <?php endforeach; endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
            </div><!-- /.wmg-grid-wrap -->

        </div><!-- /.excel-body -->
    </div><!-- /.excel-content -->

    <!-- シートタブ -->
    <div class="excel-sheettabs">
        <span class="excel-sheettab-add">⊕</span>
        <a href="<?= h($basePath . '/TReservationInfo/weekly-meal-grid?week=' . urlencode($weekMondayStr)) ?>"
           class="excel-sheettab active">週次（7日）</a>
        <a href="<?= h($basePath . '/TReservationInfo/meal-count-grid') ?>"
           class="excel-sheettab">月次（28日）</a>
    </div>

    <!-- ステータスバー -->
    <div class="excel-statusbar">
        <span>準備完了</span>
        <span>週合計: <?= h($weekGrandTotal) ?></span>
    </div>

</div><!-- /.excel-window -->

<script>
'use strict';

var WMG_BASE       = (window.WMG_CONFIG && window.WMG_CONFIG.basePath) ? window.WMG_CONFIG.basePath : '';
var WMG_ROOM_NAMES = (window.WMG_CONFIG && window.WMG_CONFIG.rooms)    ? window.WMG_CONFIG.rooms    : {};
var MEAL_OPPONENT  = {};
MEAL_OPPONENT[2] = 4;
MEAL_OPPONENT[4] = 2;

/* ─── 排他制御（他部屋予約チェック） ─── */

function wmgSyncConflicts(userId, date, meal) {
    var cells = document.querySelectorAll(
        '.wmg-toggleable[data-user-id="' + userId + '"]' +
        '[data-date="' + date + '"][data-meal="' + meal + '"]'
    );
    if (cells.length <= 1) return;

    var reservedRoomId = null;
    cells.forEach(function (cell) {
        if (cell.dataset.reserved === '1') reservedRoomId = cell.dataset.roomId;
    });

    cells.forEach(function (cell) {
        if (reservedRoomId !== null && cell.dataset.roomId !== reservedRoomId) {
            var roomName = WMG_ROOM_NAMES[reservedRoomId] || ('部屋' + reservedRoomId);
            cell.classList.add('wmg-cell-conflict');
            cell.dataset.conflictMsg = roomName + 'で予約済みのため選択できません';
        } else {
            cell.classList.remove('wmg-cell-conflict');
            delete cell.dataset.conflictMsg;
        }
    });
}

function wmgInitConflicts() {
    var seen = Object.create(null);
    document.querySelectorAll('.wmg-toggleable').forEach(function (cell) {
        var key = cell.dataset.userId + '|' + cell.dataset.date + '|' + cell.dataset.meal;
        if (!seen[key]) {
            seen[key] = true;
            wmgSyncConflicts(cell.dataset.userId, cell.dataset.date, cell.dataset.meal);
        }
    });
}

var _wmgConflictTip = null;

function wmgInitConflictTip() {
    document.addEventListener('mouseover', function (e) {
        var cell = e.target.closest('.wmg-cell-conflict');
        if (cell && cell.dataset.conflictMsg) {
            if (!_wmgConflictTip) {
                _wmgConflictTip = document.createElement('div');
                _wmgConflictTip.className = 'wmg-conflict-tip';
                document.body.appendChild(_wmgConflictTip);
            }
            _wmgConflictTip.textContent = cell.dataset.conflictMsg;
            _wmgConflictTip.style.display = 'block';
            _wmgConflictTip.style.left = (e.clientX + 12) + 'px';
            _wmgConflictTip.style.top  = (e.clientY - 36) + 'px';
        } else if (_wmgConflictTip) {
            _wmgConflictTip.style.display = 'none';
        }
    });
    document.addEventListener('mousemove', function (e) {
        if (_wmgConflictTip && _wmgConflictTip.style.display === 'block') {
            _wmgConflictTip.style.left = (e.clientX + 12) + 'px';
            _wmgConflictTip.style.top  = (e.clientY - 36) + 'px';
        }
    });
    document.addEventListener('mouseleave', function () {
        if (_wmgConflictTip) _wmgConflictTip.style.display = 'none';
    }, true);
}

/* ─── Toast ─── */
function wmgShowToast(message, type) {
    var wrap = document.getElementById('wmg-toast-wrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'wmg-toast-wrap';
        wrap.className = 'wmg-toast-wrap';
        document.body.appendChild(wrap);
    }
    var toast = document.createElement('div');
    toast.className  = 'wmg-toast wmg-toast--' + (type || 'info');
    toast.textContent = message;
    wrap.appendChild(toast);
    setTimeout(function () {
        toast.classList.add('wmg-toast--hiding');
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 250);
    }, 2700);
}

/* ─── セル状態 ─── */
function wmgSetCellOn(td)  { td.dataset.reserved = '1'; td.textContent = '1'; td.setAttribute('aria-checked', 'true'); }
function wmgSetCellOff(td) { td.dataset.reserved = '0'; td.textContent = '';  td.setAttribute('aria-checked', 'false'); }

function wmgFlashCell(td, isOn) {
    var cls = isOn ? 'wmg-cell-flash-on' : 'wmg-cell-flash-off';
    td.classList.remove('wmg-cell-flash-on', 'wmg-cell-flash-off');
    void td.offsetWidth;
    td.classList.add(cls);
    setTimeout(function () { td.classList.remove(cls); }, 500);
}

/* ─── 日計・週計を再集計 ─── */
function wmgUpdateDailyTotal(date, meal) {
    var count = document.querySelectorAll(
        '.wmg-toggleable[data-date="' + date + '"][data-meal="' + meal + '"][data-reserved="1"]'
    ).length;
    var cell = document.querySelector(
        '.row-daily-total td[data-date="' + date + '"][data-meal="' + meal + '"]'
    );
    if (cell) cell.textContent = count > 0 ? String(count) : '';
}

function wmgUpdateLoginUserWeeklyTotals() {
    var loginUserId = <?= json_encode($loginUserId) ?>;
    var dates = <?= json_encode($dates) ?>;
    var mealTypes = [1, 2, 3, 4];

    mealTypes.forEach(function (mt) {
        var total = 0;
        dates.forEach(function (d) {
            var cell = document.querySelector(
                '.wmg-toggleable[data-user-id="' + loginUserId + '"][data-date="' + d + '"][data-meal="' + mt + '"][data-reserved="1"]'
            );
            if (cell) total++;
        });
    });

    /* 週計行セルを日ごとに集計（縦断ではなく行横断で表示） */
    dates.forEach(function (d) {
        mealTypes.forEach(function (mt) {
            var weekCell = document.querySelector(
                '.row-login-weekly-total td[data-wmy-date="' + d + '"][data-wmy-meal="' + mt + '"]'
            );
            if (!weekCell) return;
            var userCell = document.querySelector(
                '.wmg-toggleable[data-user-id="' + loginUserId + '"][data-date="' + d + '"][data-meal="' + mt + '"]'
            );
            weekCell.textContent = (userCell && userCell.dataset.reserved === '1') ? '1' : '';
        });
    });
}

/* ─── セルトグル ─── */
function wmgInitToggle() {
    var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var loginUserId = <?= json_encode($loginUserId) ?>;

    document.querySelectorAll('.wmg-toggleable').forEach(function (td) {
        td.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); td.click(); }
        });

        td.addEventListener('click', function () {
            if (td.dataset.wmgProcessing === '1') return;
            if (td.classList.contains('wmg-cell-conflict')) return; // 他部屋予約済み

            var userId   = td.dataset.userId;
            var roomId   = td.dataset.roomId;
            var date     = td.dataset.date;
            var meal     = parseInt(td.dataset.meal, 10);
            var reserved = td.dataset.reserved === '1';
            var newValue = reserved ? 0 : 1;

            var snapshot = { reserved: td.dataset.reserved };
            var opponentCell = null, opponentSnap = null;

            if (newValue === 1 && Object.prototype.hasOwnProperty.call(MEAL_OPPONENT, meal)) {
                opponentCell = document.querySelector(
                    '.wmg-toggleable[data-user-id="' + userId + '"]' +
                    '[data-room-id="' + roomId + '"]' +
                    '[data-date="' + date + '"]' +
                    '[data-meal="' + MEAL_OPPONENT[meal] + '"]'
                );
                if (opponentCell) opponentSnap = { reserved: opponentCell.dataset.reserved };
            }

            td.dataset.wmgProcessing = '1';
            td.style.pointerEvents   = 'none';

            if (newValue === 1) {
                wmgSetCellOn(td);
                if (opponentCell) wmgSetCellOff(opponentCell);
            } else {
                wmgSetCellOff(td);
            }

            wmgUpdateDailyTotal(date, meal);
            if (opponentCell) wmgUpdateDailyTotal(date, MEAL_OPPONENT[meal]);
            if (parseInt(userId, 10) === loginUserId) wmgUpdateLoginUserWeeklyTotals();

            fetch(WMG_BASE + '/TReservationInfo/toggle/' + roomId, {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json',
                },
                body: JSON.stringify({ userId: parseInt(userId, 10), date: date, meal: meal, value: newValue }),
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data;
                    try { data = JSON.parse(text); } catch (e) { throw new Error('サーバーエラー (HTTP ' + res.status + ')'); }
                    if (data.ok === false) throw new Error(data.message || 'エラーが発生しました。');
                    return data;
                });
            })
            .then(function () {
                wmgFlashCell(td, newValue === 1);
                wmgSyncConflicts(userId, date, meal);
                if (opponentCell) wmgSyncConflicts(userId, date, MEAL_OPPONENT[meal]);
            })
            .catch(function (err) {
                if (snapshot.reserved === '1') wmgSetCellOn(td); else wmgSetCellOff(td);
                if (opponentCell && opponentSnap) {
                    if (opponentSnap.reserved === '1') wmgSetCellOn(opponentCell); else wmgSetCellOff(opponentCell);
                }
                wmgUpdateDailyTotal(date, meal);
                if (opponentCell) wmgUpdateDailyTotal(date, MEAL_OPPONENT[meal]);
                if (parseInt(userId, 10) === loginUserId) wmgUpdateLoginUserWeeklyTotals();
                wmgSyncConflicts(userId, date, meal);
                if (opponentCell) wmgSyncConflicts(userId, date, MEAL_OPPONENT[meal]);
                wmgShowToast(err.message || '通信エラーが発生しました。', 'error');
            })
            .finally(function () {
                delete td.dataset.wmgProcessing;
                td.style.pointerEvents = '';
            });
        });
    });
}

/* ─── 数式バー セル参照 ─── */
function wmgInitCellRef() {
    var cellRefEl = document.querySelector('.excel-formulabar .cell-ref');
    document.querySelectorAll('.wmg-toggleable').forEach(function (td, idx) {
        td.addEventListener('focus', function () {
            if (cellRefEl) {
                var col = String.fromCharCode(65 + (idx % 26));
                var row = td.closest('tr') ? td.closest('tr').rowIndex + 1 : 1;
                cellRefEl.textContent = col + row;
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    wmgInitConflicts();
    wmgInitConflictTip();
    wmgUpdateLoginUserWeeklyTotals();
    wmgInitToggle();
    wmgInitCellRef();
});
</script>

</body>
</html>
