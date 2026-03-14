<?php
/**
 * 月別食数カレンダー
 *
 * 受け取る変数:
 *   array  $rooms          [id => name] 利用可能な部屋一覧
 *   bool   $isAdmin        管理者フラグ
 *   bool   $canViewAllRooms 全部屋閲覧可フラグ
 *   string $calMonthStr    表示月 "YYYY-MM"
 *   int|null $calRoomId    選択中の部屋ID (管理者全体表示の場合 null)
 *   array  $calMealDataArray  ['YYYY-MM-DD'][1..4] = count
 *   callable $mkUrl        URL生成クロージャ
 */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<int|string, mixed> $rooms */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $isAdmin */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $canViewAllRooms */
/** @noinspection PhpUndefinedVariableInspection */
/** @var string $calMonthStr */
/** @noinspection PhpUndefinedVariableInspection */
/** @var int|string|null $calRoomId */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<string, array<int, int>> $calMealDataArray */

$rooms = isset($rooms) ? $rooms : [];
$isAdmin = isset($isAdmin) ? (bool)$isAdmin : false;
$canViewAllRooms = isset($canViewAllRooms) ? (bool)$canViewAllRooms : $isAdmin;
$calMonthStr = isset($calMonthStr) ? (string)$calMonthStr : date('Y-m');
$calRoomId = isset($calRoomId) ? $calRoomId : null;
$calMealDataArray = isset($calMealDataArray) ? $calMealDataArray : [];
$mkUrl = isset($mkUrl)
    ? $mkUrl
    : static fn(array $merge): string => '?' . http_build_query(array_filter($merge, static fn($v) => $v !== null && $v !== ''));

[$calYear, $calMon] = array_map('intval', explode('-', $calMonthStr));
$calMonthLabel = sprintf('%d年%d月', $calYear, $calMon);
$prevMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $calYear, $calMon)))->modify('-1 month')->format('Y-m');
$nextMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $calYear, $calMon)))->modify('+1 month')->format('Y-m');

$prevUrl = $mkUrl(['cal_month' => $prevMonth, 'cal_room_id' => ($calRoomId !== null ? $calRoomId : null)]);
$nextUrl = $mkUrl(['cal_month' => $nextMonth, 'cal_room_id' => ($calRoomId !== null ? $calRoomId : null)]);

// 部屋切り替えURLを生成するクロージャ
$roomUrl = function(?int $rid) use ($mkUrl, $calMonthStr) {
    return $mkUrl(['cal_room_id' => $rid, 'cal_month' => $calMonthStr]);
};

// 月の開始日(曜日)・日数
$firstDay   = new \DateTimeImmutable(sprintf('%04d-%02d-01', $calYear, $calMon));
$daysInMonth = (int)$firstDay->format('t');
$startDow   = (int)$firstDay->format('w'); // 0=日

// 食事種別ラベル
$mealLabels = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁'];
$mealColors = [1 => '#17a2b8', 2 => '#28a745', 3 => '#6610f2', 4 => '#fd7e14'];

$today = date('Y-m-d');
?>
<div class="card border-0 shadow-sm mb-3" id="mealCalCard">
    <div class="card-header bg-white py-3">
        <!-- ヘッダー：タイトル＋月ナビ＋部屋選択 -->
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-bold me-auto"><i class="bi bi-calendar3"></i> 月別食数カレンダー</span>

            <!-- 前月 / 月ラベル / 次月 -->
            <div class="d-flex align-items-center gap-1">
                <a href="<?= h($prevUrl) ?>" class="btn btn-outline-secondary btn-sm" aria-label="前月">&#8249;</a>
                <span class="fw-bold px-2"><?= h($calMonthLabel) ?></span>
                <a href="<?= h($nextUrl) ?>" class="btn btn-outline-secondary btn-sm" aria-label="次月">&#8250;</a>
            </div>

            <!-- 部屋選択（管理者のみ全部屋オプション追加） -->
            <?php if (!empty($rooms)): ?>
            <form method="get" class="d-inline-flex align-items-center gap-1 mb-0" id="calRoomForm">
                <input type="hidden" name="cal_month" value="<?= h($calMonthStr) ?>">
                <select name="cal_room_id" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()" title="表示する部屋を選択">
                    <?php if ($canViewAllRooms): ?>
                    <option value="" <?= $calRoomId === null ? 'selected' : '' ?>>全部屋</option>
                    <?php endif; ?>
                    <?php foreach ($rooms as $rid => $rname): ?>
                    <option value="<?= h($rid) ?>" <?= ((int)$calRoomId === (int)$rid) ? 'selected' : '' ?>><?= h($rname) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-2 p-md-3">
        <!-- カレンダー本体 -->
        <div class="meal-cal-table-wrap">
            <table class="meal-cal-table w-100">
                <thead>
                    <tr>
                        <?php $dowLabels = ['日','月','火','水','木','金','土']; ?>
                        <?php foreach ($dowLabels as $i => $dl): ?>
                        <th class="meal-cal-dow <?= $i===0 ? 'dow-sun' : ($i===6 ? 'dow-sat' : '') ?>"><?= h($dl) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $day = 1;
                $totalCells = $startDow + $daysInMonth;
                $rows = (int)ceil($totalCells / 7);
                for ($row = 0; $row < $rows; $row++):
                ?>
                    <tr>
                    <?php for ($col = 0; $col < 7; $col++):
                        $cellIndex = $row * 7 + $col;
                        if ($cellIndex < $startDow || $day > $daysInMonth):
                    ?>
                        <td class="meal-cal-cell meal-cal-empty"></td>
                    <?php else:
                        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMon, $day);
                        $isToday = ($dateStr === $today);
                        $isSun = ($col === 0);
                        $isSat = ($col === 6);
                        $counts = $calMealDataArray[$dateStr] ?? [];
                        $hasAny = !empty($counts);
                        $cellClass = 'meal-cal-cell';
                        if ($isToday)  $cellClass .= ' meal-cal-today';
                        if ($isSun)    $cellClass .= ' meal-cal-sun';
                        if ($isSat)    $cellClass .= ' meal-cal-sat';
                        $dataCalRoomId = $calRoomId !== null ? $calRoomId : '';
                    ?>
                        <td class="<?= h($cellClass) ?>"
                            data-date="<?= h($dateStr) ?>"
                            data-room-id="<?= h($dataCalRoomId) ?>"
                            role="button"
                            tabindex="0"
                            aria-label="<?= h($dateStr) ?>の食数">
                            <div class="meal-cal-day"><?= $day ?></div>
                            <?php foreach ($mealLabels as $mt => $ml): ?>
                                <?php $cnt = (int)($counts[$mt] ?? 0); ?>
                                <div class="meal-cal-badge <?= $cnt === 0 ? 'meal-cal-badge-zero' : '' ?>"
                                     style="--meal-color:<?= h($mealColors[$mt]) ?>;">
                                    <span class="meal-cal-badge-label"><?= h($ml) ?></span>
                                    <span class="meal-cal-badge-count"><?= $cnt ?></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    <?php $day++; endif; ?>
                    <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle"></i> 日付セルをクリックすると食べる利用者の一覧を表示します。</p>
    </div>
</div>