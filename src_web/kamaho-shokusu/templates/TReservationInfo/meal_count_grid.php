<?php
/**
 * 食数予約 Excel グリッド画面
 *
 * @var \App\View\AppView $this
 * @var array  $allRooms       [roomId => roomName]
 * @var int|null $selectedRoomId
 * @var array  $nameList       [userId => userName]（個人モード用）
 * @var int    $selectedUserId
 * @var string $viewMode       'individual' | 'room' | 'all'
 * @var array  $gridData       {dates, meals, rooms, dailyTotals}
 * @var array  $monthlyTotals  [mealType => int]
 * @var string $weekMondayStr  YYYY-MM-DD
 * @var string $periodLabel    表示期間ラベル
 * @var \DateTimeImmutable $prevMonday
 * @var \DateTimeImmutable $nextMonday
 * @var bool   $canGoPrev
 * @var bool   $canGoNext
 * @var bool   $isAdmin
 * @var bool   $canViewAll
 * @var int    $loginUserId
 * @var string $loginName
 */

$this->assign('title', '食数予約グリッド');

$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
$basePath  = $this->request->getAttribute('base') ?? '';

$dates       = $gridData['dates']       ?? [];
$meals       = $gridData['meals']       ?? [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'];
$roomsData   = $gridData['rooms']       ?? [];
$dailyTotals = $gridData['dailyTotals'] ?? [];

$today      = date('Y-m-d');
$dow        = ['日', '月', '火', '水', '木', '金', '土'];

// 日付ラベル (n/j + 曜日)
$dateLabels = [];
foreach ($dates as $d) {
    $dt = new \DateTimeImmutable($d);
    $dateLabels[$d] = $dt->format('n/j') . "\n" . $dow[(int)$dt->format('w')];
}

// モード別 URL 生成ヘルパー
$makeUrl = function (array $params) use ($viewMode, $selectedRoomId, $selectedUserId, $weekMondayStr, $basePath): string {
    $p = array_merge([
        'mode'    => $viewMode,
        'room_id' => $selectedRoomId,
        'user_id' => $selectedUserId,
        'week'    => $weekMondayStr,
    ], $params);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
    return $basePath . '/TReservationInfo/meal-count-grid' . ($qs ? '?' . $qs : '');
};

$prevUrl   = $makeUrl(['week' => $prevMonday->format('Y-m-d')]);
$nextUrl   = $makeUrl(['week' => $nextMonday->format('Y-m-d')]);
$todayUrl  = $makeUrl(['week' => date('Y-m-d', strtotime('monday this week'))]);

// 月計バーの最大値
$maxMonthly = max(1, max($monthlyTotals ?: [1]));

// 選択セル情報（数式バー表示用）
// 行番号は全ユーザーを通じた連番
$rowOffset = 0;
foreach ($roomsData as $rid => $ri) {
    foreach ($ri['users'] as $u) { $rowOffset++; }
}
$totalRows  = $rowOffset;
$lastColLtr = 'R'; // 仮: 日計列
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>食数予約グリッド — 食数管理</title>
    <meta name="csrfToken" content="<?= h($csrfToken) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $this->Html->css('pages/meal_count_grid.css') ?>
    <script>
        window.MCG_CONFIG = {
            basePath: <?= json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            rooms: <?= json_encode($allRooms, JSON_UNESCAPED_UNICODE) ?>,
        };
    </script>
</head>
<body>

<div class="excel-window">

    <!-- ═══════════════════════════════════════════
         タイトルバー
    ═══════════════════════════════════════════ -->
    <div class="excel-titlebar">
        <span class="app-icon">X</span>
        <span class="title-text">食数予約.xlsx &mdash; 食数管理</span>
        <span class="user-badge" title="<?= h($loginName) ?>"><?= h(mb_substr($loginName, 0, 1)) ?></span>
        <span style="font-size:12px;margin-left:4px"><?= h($loginName) ?></span>
    </div>

    <!-- ═══════════════════════════════════════════
         リボン タブ
    ═══════════════════════════════════════════ -->
    <div class="excel-ribbon-tabs">
        <button>ファイル</button>
        <button class="active">ホーム</button>
        <button>挿入</button>
        <button>データ</button>
        <button>表示</button>
        <button>ヘルプ</button>
    </div>
    <div class="excel-ribbon"></div>

    <!-- ═══════════════════════════════════════════
         数式バー
    ═══════════════════════════════════════════ -->
    <div class="excel-formulabar">
        <span class="cell-ref">C7</span>
        <span class="fx-label">fx</span>
        <span class="formula-text">=COUNTIF(C7:<?= h($lastColLtr) ?>7,1)</span>
    </div>

    <!-- ═══════════════════════════════════════════
         ツールバー（フィルター + 期間ナビ）
    ═══════════════════════════════════════════ -->
    <div class="mcg-toolbar">
        <!-- 表示モード -->
        <span class="mcg-toolbar-label">表示</span>
        <select id="js-mode-select" onchange="mcgChangeMode(this.value)"
                <?= !$canViewAll ? 'disabled' : '' ?>>
            <option value="individual" <?= $viewMode === 'individual' ? 'selected' : '' ?>>個人</option>
            <?php if ($canViewAll): ?>
            <option value="room"       <?= $viewMode === 'room'       ? 'selected' : '' ?>>各部屋</option>
            <option value="all"        <?= $viewMode === 'all'        ? 'selected' : '' ?>>全部</option>
            <?php endif; ?>
        </select>

        <?php if ($viewMode !== 'individual'): ?>
        <div class="mcg-toolbar-sep"></div>

        <!-- 部屋（room / all モードのみ表示） -->
        <span class="mcg-toolbar-label">部屋</span>
        <div class="mcg-room-btngroup" id="js-room-btngroup">
            <?php foreach ($allRooms as $rid => $rname): ?>
                <button type="button"
                        class="mcg-room-btn<?= (int)$rid === $selectedRoomId ? ' active' : '' ?>"
                        data-room-id="<?= h($rid) ?>"
                        <?= ($viewMode === 'all') ? 'disabled' : '' ?>
                        onclick="mcgChangeRoom(this.dataset.roomId)"
                ><?= h($rname) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 氏名（個人モードのみ・管理者は全ユーザー選択可） -->
        <?php if ($viewMode === 'individual' && $canViewAll && !empty($nameList)): ?>
        <span class="mcg-toolbar-label">表示ユーザー</span>
        <select id="js-name-select" onchange="mcgChangeName(this.value)">
            <?php foreach ($nameList as $uid => $uname): ?>
                <option value="<?= h($uid) ?>" <?= (int)$uid === $selectedUserId ? 'selected' : '' ?>>
                    <?= h($uname) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- 期間ナビ -->
        <div class="mcg-period-group">
            <span class="mcg-toolbar-label">期間</span>
            <span class="mcg-period-label-text"><?= h($periodLabel) ?></span>

            <?php if ($canGoPrev): ?>
                <a href="<?= h($prevUrl) ?>" class="mcg-nav-btn">◄ 前4週</a>
            <?php else: ?>
                <button class="mcg-nav-btn" disabled>◄ 前4週</button>
            <?php endif; ?>

            <a href="<?= h($todayUrl) ?>" class="mcg-nav-btn today">今日</a>

            <?php if ($canGoNext): ?>
                <a href="<?= h($nextUrl) ?>" class="mcg-nav-btn">翌4週 ►</a>
            <?php else: ?>
                <button class="mcg-nav-btn" disabled>翌4週 ►</button>
            <?php endif; ?>
        </div>

        <!-- 登録ボタン -->
        <button id="mcg-register-btn" type="button" disabled>登録</button>
    </div>

    <!-- ═══════════════════════════════════════════
         メインコンテンツ
    ═══════════════════════════════════════════ -->
    <div class="excel-content">
        <div class="excel-body">

            <!-- グリッド -->
            <div class="mcg-grid-wrap">
            <?php if (empty($roomsData)): ?>
                <div style="padding:24px;color:#666">表示対象のデータがありません。</div>
            <?php else: ?>
                <table class="mcg-grid" role="grid">
                    <thead>
                        <!-- 日付ヘッダー行 -->
                        <tr class="header-dates">
                            <th class="col-row">行</th>
                            <th class="col-room">部屋</th>
                            <th class="col-name">氏名</th>
                            <?php foreach ($dates as $d):
                                $dt      = new \DateTimeImmutable($d);
                                $dowIdx  = (int)$dt->format('w');
                                $isToday = ($d === $today);
                                $isSat   = ($dowIdx === 6);
                                $isSun   = ($dowIdx === 0);
                                $cls = 'date-group'
                                    . ($isToday ? ' is-today' : '')
                                    . ($isSat   ? ' is-saturday' : '')
                                    . ($isSun   ? ' is-sunday' : '');
                            ?>
                                <th colspan="<?= count($meals) ?>" class="<?= h($cls) ?>">
                                    <?= h($dt->format('n/j')) ?><br>
                                    <small><?= h($dow[$dowIdx]) ?></small>
                                </th>
                            <?php endforeach; ?>
                        </tr>

                        <!-- 食事種別ヘッダー行 -->
                        <tr class="header-meals">
                            <th class="col-row"></th>
                            <th class="col-room"></th>
                            <th class="col-name"></th>
                            <?php foreach ($dates as $d):
                                $isToday = ($d === $today);
                                $dowIdx  = (int)(new \DateTimeImmutable($d))->format('w');
                                $isSat   = ($dowIdx === 6);
                                $isSun   = ($dowIdx === 0);
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $cls = ($first ? 'meal-first ' : '')
                                        . ($isToday ? 'is-today ' : '')
                                        . ($isSat   ? 'is-saturday ' : '')
                                        . ($isSun   ? 'is-sunday' : '');
                                    $first = false;
                            ?>
                                <th class="<?= h(trim($cls)) ?>"><?= h($mealLabel) ?></th>
                            <?php endforeach; endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php
                        $rowNum = 1;
                        foreach ($roomsData as $roomId => $roomInfo):
                            $roomName = $roomInfo['name'];
                            $users    = $roomInfo['users'];
                            $grid     = $roomInfo['grid'];
                        ?>

                        <?php foreach ($users as $u):
                            $uid = (int)$u['id'];
                        ?>
                        <tr data-user-id="<?= h($uid) ?>" data-room-id="<?= h($roomId) ?>">
                            <td class="col-row"><?= h($rowNum++) ?></td>
                            <td class="col-room" title="<?= h($roomName) ?>"><?= h($roomName) ?></td>
                            <td class="col-name" title="<?= h($u['name']) ?>"><?= h($u['name']) ?></td>
                            <?php foreach ($dates as $d):
                                $dt      = new \DateTimeImmutable($d);
                                $dowIdx  = (int)$dt->format('w');
                                $isToday = ($d === $today);
                                $isSat   = ($dowIdx === 6);
                                $isSun   = ($dowIdx === 0);
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $reserved = !empty($grid[$uid][$d][$mealType]);
                                    $tdClass = 'cell-meal mcg-toggleable'
                                        . ($first ? ' meal-first' : '')
                                        . ($isToday ? ' is-today' : '')
                                        . ($isSat   ? ' is-saturday' : '')
                                        . ($isSun   ? ' is-sunday' : '');
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

                        <!-- 日計行 -->
                        <tr class="row-daily-total">
                            <td class="col-row"></td>
                            <td class="col-room" colspan="2">日計</td>
                            <?php foreach ($dates as $d):
                                $dowIdx  = (int)(new \DateTimeImmutable($d))->format('w');
                                $isToday = ($d === $today);
                                $isSat   = ($dowIdx === 6);
                                $isSun   = ($dowIdx === 0);
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $total = (int)($dailyTotals[$d][$mealType] ?? 0);
                                    $cls = ($first ? 'meal-first ' : '')
                                        . ($isToday ? 'is-today ' : '')
                                        . ($isSat   ? 'is-saturday ' : '')
                                        . ($isSun   ? 'is-sunday' : '');
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
            </div><!-- /.mcg-grid-wrap -->

            <!-- 月計サマリーパネル -->
            <div style="padding:0 10px 10px">
                <div class="mcg-summary">
                    <div class="mcg-summary-title">食事別の月計</div>
                    <?php foreach ($meals as $mealType => $mealLabel):
                        $val = (int)($monthlyTotals[$mealType] ?? 0);
                        $pct = $maxMonthly > 0 ? round($val / $maxMonthly * 100) : 0;
                    ?>
                    <div class="mcg-summary-row">
                        <span class="mcg-summary-label"><?= h($mealLabel) ?></span>
                        <div class="mcg-summary-bar-wrap">
                            <div class="mcg-summary-bar" style="width:<?= h($pct) ?>%"></div>
                        </div>
                        <span class="mcg-summary-val"><?= h($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div><!-- /.excel-body -->
    </div><!-- /.excel-content -->

    <!-- ═══════════════════════════════════════════
         シートタブ
    ═══════════════════════════════════════════ -->
    <div class="excel-sheettabs">
        <span class="excel-sheettab-add">⊕</span>
        <a href="<?= h($makeUrl(['mode' => 'individual'])) ?>"
           class="excel-sheettab <?= $viewMode === 'individual' ? 'active' : '' ?>">個人</a>
        <?php if ($canViewAll): ?>
        <a href="<?= h($makeUrl(['mode' => 'room'])) ?>"
           class="excel-sheettab <?= $viewMode === 'room' ? 'active' : '' ?>">各部屋</a>
        <a href="<?= h($makeUrl(['mode' => 'all'])) ?>"
           class="excel-sheettab <?= $viewMode === 'all' ? 'active' : '' ?>">全部</a>
        <a href="#" class="excel-sheettab">入力作法</a>
        <?php endif; ?>
    </div>

    <!-- ステータスバー -->
    <div class="excel-statusbar">
        <span>準備完了</span>
        <span>
            合計: <?= h(array_sum($monthlyTotals)) ?>
            個数: <?= h(array_sum($dailyTotals ? array_map('array_sum', $dailyTotals) : [0])) ?>
        </span>
    </div>

</div><!-- /.excel-window -->

<script>
var MCG_BASE = (window.MCG_CONFIG && window.MCG_CONFIG.basePath) ? window.MCG_CONFIG.basePath : '';

function mcgActiveRoomId() {
    var btn = document.querySelector('.mcg-room-btn.active');
    return btn ? btn.dataset.roomId : '';
}

function mcgChangeMode(mode) {
    var roomId = mcgActiveRoomId();
    var userId = document.getElementById('js-name-select') ? document.getElementById('js-name-select').value : '';
    var week   = <?= json_encode($weekMondayStr) ?>;
    var qs = new URLSearchParams({ mode: mode, room_id: roomId, user_id: userId, week: week }).toString();
    location.href = MCG_BASE + '/TReservationInfo/meal-count-grid?' + qs;
}
function mcgChangeRoom(roomId) {
    var mode = document.getElementById('js-mode-select') ? document.getElementById('js-mode-select').value : 'individual';
    var week = <?= json_encode($weekMondayStr) ?>;
    var qs = new URLSearchParams({ mode: mode, room_id: roomId, week: week }).toString();
    location.href = MCG_BASE + '/TReservationInfo/meal-count-grid?' + qs;
}
function mcgChangeName(userId) {
    var mode   = document.getElementById('js-mode-select') ? document.getElementById('js-mode-select').value : 'individual';
    var roomId = mcgActiveRoomId();
    var week   = <?= json_encode($weekMondayStr) ?>;
    var qs = new URLSearchParams({ mode: mode, room_id: roomId, user_id: userId, week: week }).toString();
    location.href = MCG_BASE + '/TReservationInfo/meal-count-grid?' + qs;
}
</script>

<?= $this->Html->script('pages/meal_count_grid.js') ?>
</body>
</html>
