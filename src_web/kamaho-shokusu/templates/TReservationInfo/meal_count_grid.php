<?php
/**
 * 食数予約 Excel グリッド画面
 *
 * 部屋×ユーザー×日付×食事種別のスプレッドシート形式グリッドで
 * 予約状況を一覧表示・トグル操作できる。
 *
 * 受け取るビュー変数:
 *   - $rooms         : array [roomId => roomName]
 *   - $gridData      : array {dates, meals, rooms, dailyTotals}
 *   - $weekMondayStr : string YYYY-MM-DD
 *   - $prevMonday    : DateTimeImmutable
 *   - $nextMonday    : DateTimeImmutable
 *   - $canGoPrev     : bool
 *   - $canGoNext     : bool
 *   - $isAdmin       : bool
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', '食数予約グリッド');

$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
$basePath  = $this->request->getAttribute('base') ?? '';

$dates       = $gridData['dates']       ?? [];
$meals       = $gridData['meals']       ?? [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'];
$roomsData   = $gridData['rooms']       ?? [];
$dailyTotals = $gridData['dailyTotals'] ?? [];

$today   = date('Y-m-d');
$dow     = ['日', '月', '火', '水', '木', '金', '土'];

// 日付ラベル
$dateLabels = [];
foreach ($dates as $d) {
    try {
        $dt = new \DateTimeImmutable($d);
        $dateLabels[$d] = $dt->format('n/j') . '(' . $dow[(int)$dt->format('w')] . ')';
    } catch (\Throwable $e) {
        $dateLabels[$d] = $d;
    }
}

// 週範囲ラベル
$weekRangeLabel = '';
if (!empty($dates)) {
    $weekRangeLabel = ($dateLabels[$dates[0]] ?? $dates[0])
        . ' 〜 '
        . ($dateLabels[$dates[count($dates) - 1]] ?? $dates[count($dates) - 1]);
}

// 前週・次週URL
$prevUrl = $this->Url->build('/TReservationInfo/meal-count-grid?week=' . $prevMonday->format('Y-m-d'));
$nextUrl = $this->Url->build('/TReservationInfo/meal-count-grid?week=' . $nextMonday->format('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約グリッド</title>
    <meta name="csrfToken" content="<?= h($csrfToken) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= $this->Html->css('pages/meal_count_grid.css') ?>
    <script>
        window.MCG_CONFIG = {
            basePath: <?= json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        };
    </script>
</head>
<body>
<div class="container-fluid py-3">

    <!-- ページヘッダー -->
    <div class="mcg-page-header">
        <div>
            <div class="mcg-subtitle">食数管理</div>
            <h1>食数予約グリッド</h1>
        </div>

        <!-- 週ナビゲーション -->
        <nav class="mcg-nav" aria-label="週ナビゲーション">
            <?php if ($canGoPrev): ?>
                <a href="<?= h($prevUrl) ?>" class="btn btn-sm btn-outline-secondary" title="前週">&#8249;</a>
            <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary" disabled>&#8249;</button>
            <?php endif; ?>

            <span class="mcg-date-range"><?= h($weekRangeLabel) ?></span>

            <?php if ($canGoNext): ?>
                <a href="<?= h($nextUrl) ?>" class="btn btn-sm btn-outline-secondary" title="次週">&#8250;</a>
            <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary" disabled>&#8250;</button>
            <?php endif; ?>
        </nav>
    </div>

    <!-- 凡例 -->
    <div class="mcg-legend mb-3">
        <span class="mcg-legend-item"><span class="mcg-cell-check" style="display:inline-block"></span> 予約あり</span>
        <span class="mcg-legend-item" style="color:#2563eb">■ 土曜</span>
        <span class="mcg-legend-item" style="color:#dc2626">■ 日曜</span>
        <span class="mcg-legend-item" style="color:#b45309">■ 今日</span>
    </div>

    <?php if (empty($roomsData)): ?>
        <div class="alert alert-info">表示対象の部屋がありません。</div>
    <?php else: ?>

    <!-- グリッド -->
    <div class="mcg-grid-wrap">
        <table class="mcg-grid" role="grid" aria-label="食数予約グリッド">
            <thead>
                <!-- 日付ヘッダー行 -->
                <tr class="header-dates">
                    <th class="col-room" scope="col">部屋</th>
                    <th class="col-name" scope="col">氏名</th>
                    <?php foreach ($dates as $d):
                        $isToday    = ($d === $today);
                        $dow_idx    = (int)(new \DateTimeImmutable($d))->format('w');
                        $isSat      = ($dow_idx === 6);
                        $isSun      = ($dow_idx === 0);
                        $thClass = 'date-group' . ($isToday ? ' is-today' : '') . ($isSat ? ' is-saturday' : '') . ($isSun ? ' is-sunday' : '');
                    ?>
                        <th colspan="<?= count($meals) ?>" class="<?= h($thClass) ?>" scope="colgroup">
                            <?= h($dateLabels[$d] ?? $d) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>

                <!-- 食事種別ヘッダー行 -->
                <tr class="header-meals">
                    <th class="col-room"></th>
                    <th class="col-name"></th>
                    <?php foreach ($dates as $d):
                        $isToday = ($d === $today);
                        $first   = true;
                        foreach ($meals as $mealType => $mealLabel):
                            $thCls = 'cell-meal' . ($first ? ' meal-first' : '') . ($isToday ? ' is-today' : '');
                            $first = false;
                    ?>
                        <th class="<?= h($thCls) ?>" scope="col" title="<?= h($d . ' ' . $mealLabel) ?>">
                            <?= h($mealLabel) ?>
                        </th>
                    <?php endforeach; endforeach; ?>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($roomsData as $roomId => $roomInfo):
                    $roomName = $roomInfo['name'];
                    $users    = $roomInfo['users'];
                    $grid     = $roomInfo['grid'];
                ?>

                    <!-- 部屋グループヘッダー行 -->
                    <tr class="row-room-header">
                        <td class="col-room" colspan="2"><?= h($roomName) ?></td>
                        <?php foreach ($dates as $d):
                            $isToday = ($d === $today);
                            $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                            $first   = true;
                            foreach ($meals as $mealType => $mealLabel):
                                $cls = ($first ? 'meal-first ' : '') . ($isToday ? 'is-today' : '');
                                $first = false;
                        ?>
                            <td class="<?= h($cls) ?>"></td>
                        <?php endforeach; endforeach; ?>
                    </tr>

                    <!-- ユーザー行 -->
                    <?php foreach ($users as $user):
                        $uid = (int)$user['id'];
                    ?>
                    <tr data-user-id="<?= h($uid) ?>" data-room-id="<?= h($roomId) ?>">
                        <td class="col-room"></td>
                        <td class="col-name" title="<?= h($user['name']) ?>"><?= h($user['name']) ?></td>
                        <?php foreach ($dates as $d):
                            $isToday = ($d === $today);
                            $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                            $isSat   = ($dow_idx === 6);
                            $isSun   = ($dow_idx === 0);
                            $first   = true;
                            foreach ($meals as $mealType => $mealLabel):
                                $reserved = !empty($grid[$uid][$d][$mealType]);
                                $tdClass = 'cell-meal mcg-toggleable'
                                    . ($first ? ' meal-first' : '')
                                    . ($isToday ? ' is-today' : '')
                                    . ($isSat ? ' is-saturday' : '')
                                    . ($isSun ? ' is-sunday' : '');
                                $first = false;
                        ?>
                            <td class="<?= h($tdClass) ?>"
                                data-user-id="<?= h($uid) ?>"
                                data-room-id="<?= h($roomId) ?>"
                                data-date="<?= h($d) ?>"
                                data-meal="<?= h($mealType) ?>"
                                data-reserved="<?= $reserved ? '1' : '0' ?>"
                                title="<?= h($user['name'] . ' ' . ($dateLabels[$d] ?? $d) . ' ' . $mealLabel) ?>"
                                role="checkbox"
                                aria-checked="<?= $reserved ? 'true' : 'false' ?>"
                                tabindex="0"
                            ><?php if ($reserved): ?><span class="mcg-cell-check"></span><?php endif; ?></td>
                        <?php endforeach; endforeach; ?>
                    </tr>
                    <?php endforeach; ?>

                    <!-- 部屋小計行 -->
                    <tr class="row-room-subtotal" data-room-id="<?= h($roomId) ?>">
                        <td class="col-room" colspan="2">小計</td>
                        <?php foreach ($dates as $d):
                            $isToday = ($d === $today);
                            $first   = true;
                            foreach ($meals as $mealType => $mealLabel):
                                // 部屋内の予約数を集計
                                $count = 0;
                                foreach ($users as $u) {
                                    $uid2 = (int)$u['id'];
                                    if (!empty($grid[$uid2][$d][$mealType])) $count++;
                                }
                                $cls = ($first ? 'meal-first ' : '') . ($isToday ? 'is-today' : '');
                                $first = false;
                        ?>
                            <td class="<?= h($cls) ?>"
                                data-date="<?= h($d) ?>"
                                data-meal="<?= h($mealType) ?>"
                            ><?= $count > 0 ? h($count) : '' ?></td>
                        <?php endforeach; endforeach; ?>
                    </tr>

                <?php endforeach; ?>

                <!-- 日計行 -->
                <tr class="row-daily-total">
                    <td class="col-room" colspan="2">日計</td>
                    <?php foreach ($dates as $d):
                        $isToday = ($d === $today);
                        $first   = true;
                        foreach ($meals as $mealType => $mealLabel):
                            $total = (int)($dailyTotals[$d][$mealType] ?? 0);
                            $cls = ($first ? 'meal-first ' : '') . ($isToday ? 'is-today' : '');
                            $first = false;
                    ?>
                        <td class="<?= h($cls) ?>"
                            data-date="<?= h($d) ?>"
                            data-meal="<?= h($mealType) ?>"
                        ><?= $total > 0 ? h($total) : '' ?></td>
                    <?php endforeach; endforeach; ?>
                </tr>

            </tbody>
        </table>
    </div><!-- /.mcg-grid-wrap -->

    <?php endif; ?>

</div><!-- /.container-fluid -->

<?= $this->Html->script('pages/meal_count_grid.js') ?>
</body>
</html>
