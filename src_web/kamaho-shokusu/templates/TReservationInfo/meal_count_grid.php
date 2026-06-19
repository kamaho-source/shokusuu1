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
 * @var array  $dateCategories [date => 'past'|'last_minute'|'normal']
 * @var string $weekMondayStr  YYYY-MM-DD
 * @var string $periodLabel    表示期間ラベル
 * @var \DateTimeImmutable $prevMonday
 * @var \DateTimeImmutable $nextMonday
 * @var bool   $canGoPrev
 * @var bool   $canGoNext
 * @var bool   $isAdmin
 * @var bool   $canViewAll
 * @var bool   $canViewRoom
 * @var bool   $canUseAllMode
 * @var bool   $hasStaffId
 * @var int    $loginUserId
 * @var string $loginName
 */

$this->assign('title', 'エクセル食数予約');

$csrfToken = $this->request->getAttribute('csrfToken') ?? '';
$basePath  = rtrim($this->request->getAttribute('base') ?? '', '/');

$dates          = $gridData['dates']       ?? [];
$meals          = $gridData['meals']       ?? [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'];
$roomsData      = $gridData['rooms']       ?? [];
$dailyTotals    = $gridData['dailyTotals'] ?? [];
$dateCategories = $dateCategories          ?? [];

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

// 選択セル情報（数式バー表示用）
// 行番号は全ユーザーを通じた連番
$rowOffset = 0;
foreach ($roomsData as $rid => $ri) {
    foreach ($ri['users'] as $u) { $rowOffset++; }
}
$totalRows = $rowOffset;

// CSS・JS をレイアウトの <head> / </body> 直前ブロックに注入
$this->Html->css('pages/meal_count_grid.css', ['block' => true]);
$this->append('script', sprintf(
    '<script>window.MCG_CONFIG = %s;</script>',
    json_encode(['basePath' => $basePath, 'rooms' => $allRooms], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
));
$this->Html->script('japanese-holidays.min.js', ['block' => true]);
$this->Html->script('pages/meal_count_grid.js', ['block' => true]);
?>

<div class="excel-window">

    <!-- ═══════════════════════════════════════════
         ツールバー（フィルター + 期間ナビ）
    ═══════════════════════════════════════════ -->
    <div class="mcg-toolbar">
        <!-- 表示モード -->
        <span class="mcg-toolbar-label">表示</span>
        <select id="js-mode-select" onchange="mcgChangeMode(this.value)"
                <?= !$canViewRoom ? 'disabled' : '' ?>>
            <option value="individual" <?= $viewMode === 'individual' ? 'selected' : '' ?>>個人</option>
            <?php if ($canViewRoom): ?>
            <option value="room"       <?= $viewMode === 'room'       ? 'selected' : '' ?>>各部屋</option>
            <?php endif; ?>
            <?php if ($canUseAllMode): ?>
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

        <!-- 氏名（固定表示） -->
        <?php if ($viewMode === 'individual'): ?>
        <span class="mcg-toolbar-label">表示ユーザー</span>
        <span class="mcg-toolbar-user-name"><?= h($nameList[$selectedUserId] ?? $loginName) ?></span>
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

        <!-- 凡例 -->
        <div class="mcg-legend">
            <span class="mcg-legend-item mcg-legend-last-minute">直前（当日〜14日後）</span>
            <span class="mcg-legend-item mcg-legend-past">過去日（変更不可）</span>
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
                                $dateCat      = $dateCategories[$d] ?? 'normal';
                                $isLastMinute = ($dateCat === 'last_minute');
                                $isPastDate   = ($dateCat === 'past');
                                $cls = 'date-group'
                                    . ($isToday       ? ' is-today'       : '')
                                    . ($isSat         ? ' is-saturday'    : '')
                                    . ($isSun         ? ' is-sunday'      : '')
                                    . ($isLastMinute  ? ' is-last-minute' : '')
                                    . ($isPastDate    ? ' is-past-header' : '');
                            ?>
                                <th colspan="<?= count($meals) ?>" class="<?= h($cls) ?>" data-date="<?= h($d) ?>"
                                    <?php if ($isLastMinute): ?>
                                    data-tooltip="直前編集ウィンドウ（当日〜14日後）&#10;・予約のON/OFFを変更できます&#10;・職員はこの期間のキャンセルができません&#10;・変更は「登録」ボタンで確定します"
                                    <?php elseif ($isPastDate): ?>
                                    data-tooltip="過去日のため変更できません。"
                                    <?php endif; ?>
                                >
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
                                $dateCat = $dateCategories[$d] ?? 'normal';
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $cls = ($first ? 'meal-first ' : '')
                                        . ($isToday ? 'is-today ' : '')
                                        . ($isSat   ? 'is-saturday ' : '')
                                        . ($isSun   ? 'is-sunday ' : '')
                                        . ($dateCat === 'last_minute' ? 'is-last-minute' : '');
                                    $first = false;
                            ?>
                                <th class="<?= h(trim($cls)) ?>" data-date="<?= h($d) ?>"><?= h($mealLabel) ?></th>
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
                            $uid          = (int)$u['id'];
                            $uLevel       = (int)($u['i_user_level'] ?? 0);
                            // 編集可否: 管理者は全員、部屋アクセス権あり→自分+子供(level=1)、それ以外→自分のみ
                            $canEditRow   = $isAdmin
                                || ($uid === $loginUserId)
                                || ($canViewRoom && $uLevel === 1);
                        ?>
                        <tr data-user-id="<?= h($uid) ?>" data-room-id="<?= h($roomId) ?>" data-user-level="<?= h($uLevel) ?>">
                            <td class="col-row"><?= h($rowNum++) ?></td>
                            <td class="col-room" title="<?= h($roomName) ?>"><?= h($roomName) ?></td>
                            <td class="col-name" title="<?= h($u['name']) ?>"><?= h($u['name']) ?></td>
                            <?php foreach ($dates as $d):
                                $dt      = new \DateTimeImmutable($d);
                                $dowIdx  = (int)$dt->format('w');
                                $isToday = ($d === $today);
                                $isSat   = ($dowIdx === 6);
                                $isSun   = ($dowIdx === 0);
                                $dateCat = $dateCategories[$d] ?? 'normal';
                                $isPast  = ($dateCat === 'past');
                                $first   = true;
                                foreach ($meals as $mealType => $mealLabel):
                                    $reserved = !empty($grid[$uid][$d][$mealType]);
                                    $toggleable = !$isPast && $canEditRow;
                                    $tdClass = 'cell-meal'
                                        . ($toggleable ? ' mcg-toggleable' : '')
                                        . ($first   ? ' meal-first'     : '')
                                        . ($isToday ? ' is-today'       : '')
                                        . ($isSat   ? ' is-saturday'    : '')
                                        . ($isSun   ? ' is-sunday'      : '')
                                        . ($isPast                        ? ' is-past'        : '')
                                        . ($dateCat === 'last_minute'     ? ' is-last-minute' : '');
                                    $first = false;
                            ?>
                                <td class="<?= h($tdClass) ?>"
                                    data-user-id="<?= h($uid) ?>"
                                    data-room-id="<?= h($roomId) ?>"
                                    data-date="<?= h($d) ?>"
                                    data-meal="<?= h($mealType) ?>"
                                    data-reserved="<?= $reserved ? '1' : '0' ?>"
                                    title="<?= h($u['name'] . ' ' . $d . ' ' . $mealLabel) ?>"
                                    <?php if ($toggleable): ?>
                                    role="checkbox"
                                    aria-checked="<?= $reserved ? 'true' : 'false' ?>"
                                    tabindex="0"
                                    <?php endif; ?>
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


        </div><!-- /.excel-body -->
    </div><!-- /.excel-content -->

    <!-- ═══════════════════════════════════════════
         シートタブ
    ═══════════════════════════════════════════ -->
    <div class="excel-sheettabs">
        <span class="excel-sheettab-add">⊕</span>
        <a href="<?= h($makeUrl(['mode' => 'individual'])) ?>"
           class="excel-sheettab <?= $viewMode === 'individual' ? 'active' : '' ?>">個人</a>
        <?php if ($canViewRoom): ?>
        <a href="<?= h($makeUrl(['mode' => 'room'])) ?>"
           class="excel-sheettab <?= $viewMode === 'room' ? 'active' : '' ?>">各部屋</a>
        <?php endif; ?>
        <?php if ($canUseAllMode): ?>
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
/* ナビバーの実高さを CSS 変数にセット（excel-window の top 値に使用） */
(function () {
    function applyNavHeight() {
        var nav = document.getElementById('mainNav');
        if (nav) {
            document.documentElement.style.setProperty('--mcg-nav-h', nav.offsetHeight + 'px');
        }
    }
    applyNavHeight();
    window.addEventListener('resize', applyNavHeight);
}());

var MCG_BASE = (window.MCG_CONFIG && window.MCG_CONFIG.basePath) ? window.MCG_CONFIG.basePath : '';

function mcgActiveRoomId() {
    var btn = document.querySelector('.mcg-room-btn.active');
    return btn ? btn.dataset.roomId : '';
}

function mcgChangeMode(mode) {
    var roomId = mcgActiveRoomId();
    var week   = <?= json_encode($weekMondayStr) ?>;
    var qs = new URLSearchParams({ mode: mode, room_id: roomId, week: week }).toString();
    location.href = MCG_BASE + '/TReservationInfo/meal-count-grid?' + qs;
}
function mcgChangeRoom(roomId) {
    var mode = document.getElementById('js-mode-select') ? document.getElementById('js-mode-select').value : 'individual';
    var week = <?= json_encode($weekMondayStr) ?>;
    var qs = new URLSearchParams({ mode: mode, room_id: roomId, week: week }).toString();
    location.href = MCG_BASE + '/TReservationInfo/meal-count-grid?' + qs;
}
</script>