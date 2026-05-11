<?php
/**
 * 職員用週間予約グリッド画面
 * ログインユーザー自身の予約を1週間単位で表示する。
 */

/** @var int    $loginUserId */
/** @var string $loginUserName */
/** @var array  $allRoomsForUser   [room_id => room_name] */
/** @var \DateTimeImmutable[] $dates  月〜日の7要素 */
/** @var \DateTimeImmutable  $weekStart */
/** @var \DateTimeImmutable  $weekEnd */
/** @var \DateTimeImmutable  $prevWeekStart */
/** @var \DateTimeImmutable  $nextWeekStart */
/** @var array  $gridRows   [{room_id, room_name, user_id, user_name, staff_id}] */
/** @var array  $grid       [userId][roomId][date][mealType(1-4)] = 0|1 */
/** @var bool   $isAdmin */
/** @var string $mode  'individual'|'room'|'all' */

$todayStr     = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$weekStartStr = $weekStart->format('Y-m-d');
$thisMonday   = (new \DateTimeImmutable('monday this week', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$basePath     = $this->request->getAttribute('base') ?? '';
$actionBase   = $basePath . '/TReservationInfo/staff-reservation';

$modeParam    = '&mode=' . h($mode);
$isIndividual = ($mode === 'individual');

$mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁'];
$dayLabels = ['月', '火', '水', '木', '金', '土', '日'];

$fmtDate = static fn(\DateTimeImmutable $d): string => $d->format('n/j');
$fmtDay  = static fn(\DateTimeImmutable $d): string => $dayLabels[(int)$d->format('N') - 1];

$nameFirst = mb_substr($loginUserName, 0, 1);

/* ── 所属部屋一覧（サマリー用） ── */
$roomNames = array_values($allRoomsForUser);

/* ── 週間合計（ログインユーザー分のみ・サマリーカード用） ── */
$weekTotals = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($gridRows as $row) {
    if ((int)$row['user_id'] !== $loginUserId) continue;
    $uid = $loginUserId;
    $rid = (int)$row['room_id'];
    foreach ($dates as $d) {
        $ds = $d->format('Y-m-d');
        for ($mt = 1; $mt <= 4; $mt++) {
            if (($grid[$uid][$rid][$ds][$mt] ?? 0) === 1) {
                $weekTotals[$mt]++;
            }
        }
    }
}
$weekGrandTotal = array_sum($weekTotals);

/* ── 日計（日付×食事種別） ── */
$dailyTotals = [];
foreach ($dates as $d) {
    $ds = $d->format('Y-m-d');
    $dailyTotals[$ds] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($gridRows as $row) {
        $uid = (int)$row['user_id'];
        $rid = (int)$row['room_id'];
        for ($mt = 1; $mt <= 4; $mt++) {
            if (($grid[$uid][$rid][$ds][$mt] ?? 0) === 1) {
                $dailyTotals[$ds][$mt]++;
            }
        }
    }
}

/* ── ユーザー日計（全部屋合算） ── */
$userDateTotals = [];
foreach ($gridRows as $row) {
    $uid = (int)$row['user_id'];
    $rid = (int)$row['room_id'];
    foreach ($dates as $d) {
        $ds = $d->format('Y-m-d');
        for ($mt = 1; $mt <= 4; $mt++) {
            if (($grid[$uid][$rid][$ds][$mt] ?? 0) === 1) {
                $userDateTotals[$uid][$ds][$mt] = ($userDateTotals[$uid][$ds][$mt] ?? 0) + 1;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約（職員）</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <?= $this->Html->css('pages/staff_reservation') ?>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
</head>
<body>
<div class="container-fluid px-3 py-3">

    <!-- ===== ページヘッダー ===== -->
    <div class="sr-page-header">
        <div>
            <div class="sr-subtitle">MEAL RESERVATION · WEEKLY</div>
            <h1>食数予約</h1>
        </div>
        <span class="sr-date-range">
            <?= h($weekStart->format('Y/n/j')) ?> 〜 <?= h($weekEnd->format('n/j')) ?>
        </span>
    </div>

    <!-- ===== ツールバー ===== -->
    <div class="sr-toolbar">
        <!-- 表示モード -->
        <div class="sr-mode-switcher">
            <div class="sr-mode-label">表示モード</div>
            <div class="sr-mode-tabs">
                <a href="<?= h($actionBase . '?week_start=' . $weekStartStr . '&mode=individual') ?>"
                   class="sr-mode-tab <?= $mode === 'individual' ? 'is-active' : '' ?>">
                    👤 個人
                </a>
                <a href="<?= h($actionBase . '?week_start=' . $weekStartStr . '&mode=room') ?>"
                   class="sr-mode-tab <?= $mode === 'room' ? 'is-active' : '' ?>">
                    🏠 各部屋
                </a>
                <?php if ($isAdmin): ?>
                <a href="<?= h($actionBase . '?week_start=' . $weekStartStr . '&mode=all') ?>"
                   class="sr-mode-tab <?= $mode === 'all' ? 'is-active' : '' ?>">
                    🌐 全部
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="sr-toolbar-divider"></div>

        <!-- 週ナビゲーション -->
        <div class="sr-nav">
            <a href="<?= h($actionBase . '?week_start=' . $prevWeekStart->format('Y-m-d') . $modeParam) ?>"
               class="btn btn-outline-secondary btn-sm">◄ 前週</a>
            <a href="<?= h($actionBase . '?week_start=' . $thisMonday . $modeParam) ?>"
               class="btn btn-primary btn-sm">今週</a>
            <a href="<?= h($actionBase . '?week_start=' . $nextWeekStart->format('Y-m-d') . $modeParam) ?>"
               class="btn btn-outline-secondary btn-sm">翌週 ►</a>
        </div>
    </div>

    <!-- ===== サマリーカード ===== -->
    <div class="sr-summary-cards">
        <div class="sr-user-card">
            <div class="sr-avatar"><?= h($nameFirst) ?></div>
            <div class="sr-card-body">
                <div class="sr-card-name"><?= h($loginUserName) ?>さん</div>
                <div class="sr-card-rooms">
                    <?php foreach ($roomNames as $rn): ?>
                        <span class="sr-room-badge">
                            <i class="bi bi-house-fill" style="font-size:10px"></i>
                            <?= h($rn) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div class="sr-card-meta">
                    <?= h($weekStart->format('n/j')) ?>（<?= h($fmtDay($weekStart)) ?>）〜
                    <?= h($weekEnd->format('n/j')) ?>（<?= h($fmtDay($weekEnd)) ?>）
                </div>
            </div>
            <div class="sr-card-totals">
                <?php foreach ($mealTypes as $mt => $label): ?>
                    <div class="sr-total-item">
                        <span class="t-label"><?= h($label) ?></span>
                        <span class="t-value"><?= (int)($weekTotals[$mt] ?? 0) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="sr-total-item total-all">
                    <span class="t-label">合計</span>
                    <span class="t-value"><?= (int)$weekGrandTotal ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== グリッドテーブル ===== -->
    <?php if (empty($gridRows)): ?>
        <div class="alert alert-info">所属部屋の情報がありません。</div>
    <?php else: ?>
    <div class="sr-grid-wrap">
        <table class="sr-grid">
            <colgroup>
                <col class="col-room">
                <col class="col-name">
                <?php foreach ($dates as $_d): ?>
                    <col class="cell-meal"><col class="cell-meal"><col class="cell-meal"><col class="cell-meal">
                <?php endforeach; ?>
            </colgroup>
            <thead>
                <!-- ① 日付行 -->
                <tr class="header-dates">
                    <th class="col-room" rowspan="2">部屋</th>
                    <th class="col-name" rowspan="2">氏名</th>
                    <?php foreach ($dates as $d): ?>
                        <?php
                        $dStr    = $d->format('Y-m-d');
                        $dow     = (int)$d->format('N');
                        $isToday = ($dStr === $todayStr);
                        $isSat   = ($dow === 6);
                        $isSun   = ($dow === 7);
                        $cls     = 'date-group';
                        if ($isToday)   $cls .= ' is-today';
                        elseif ($isSat) $cls .= ' is-saturday';
                        elseif ($isSun) $cls .= ' is-sunday';
                        ?>
                        <th colspan="4" class="<?= $cls ?>">
                            <?= h($fmtDate($d)) ?>
                            <span class="fw-normal"><?= h($fmtDay($d)) ?></span>
                            <?php if ($isToday): ?><br><small class="fw-bold">今日</small><?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <!-- ② 食事種別行（col-room・col-nameはrowspanで占有済み、ここには不要） -->
                <tr class="header-meals">
                    <?php foreach ($dates as $d): ?>
                        <?php foreach ($mealTypes as $mt => $mlabel): ?>
                            <th class="<?= $mt === 1 ? 'meal-first' : '' ?>"><?= h($mlabel) ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $prevRoomId = null;
                foreach ($gridRows as $row):
                    $rowRoomId = (int)$row['room_id'];
                    echo $this->element('TReservationInfo/staff_grid_row', [
                        'row'          => $row,
                        'dates'        => $dates,
                        'grid'         => $grid,
                        'todayStr'     => $todayStr,
                        'mealTypes'    => $mealTypes,
                        'showRoomName' => ($prevRoomId !== $rowRoomId),
                        'interactive'  => $isIndividual,
                    ]);
                    $prevRoomId = $rowRoomId;
                endforeach;
                ?>

                <!-- ユーザー合計行 -->
                <tr class="row-user-total">
                    <td class="col-room"><?= h($loginUserName) ?>さん合計</td>
                    <td class="col-name"></td>
                    <?php foreach ($dates as $d): ?>
                        <?php $ds = $d->format('Y-m-d'); ?>
                        <?php foreach ($mealTypes as $mt => $ml): ?>
                            <?php $v = $userDateTotals[$loginUserId][$ds][$mt] ?? 0; ?>
                            <td class="cell-meal <?= $mt === 1 ? 'meal-first' : '' ?>">
                                <?= $v ? h((string)$v) : '' ?>
                            </td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>

                <!-- 日計行 -->
                <tr class="row-daily-total">
                    <td class="col-room">日計</td>
                    <td class="col-name"></td>
                    <?php foreach ($dates as $d): ?>
                        <?php $ds = $d->format('Y-m-d'); ?>
                        <?php foreach ($mealTypes as $mt => $ml): ?>
                            <?php $dv = $dailyTotals[$ds][$mt] ?? 0; ?>
                            <td class="cell-meal <?= $mt === 1 ? 'meal-first' : '' ?>">
                                <?= $dv ? h((string)$dv) : '' ?>
                            </td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
<script>
window.SR_CONFIG = {
    basePath:      <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>,
    isIndividual:  <?= $isIndividual ? 'true' : 'false' ?>
};
</script>
<?= $this->Html->script('pages/staff_reservation') ?>
</body>
</html>
