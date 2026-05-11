<?php
/**
 * 職員用グリッド: 1データ行
 *
 * @var array $row          {room_id, room_name, user_id, user_name, staff_id}
 * @var \DateTimeImmutable[] $dates
 * @var array $grid         [userId][roomId][date][mealType] = 0|1
 * @var string $todayStr    YYYY-MM-DD
 * @var bool $showRoomName  部屋名を表示するか（同じ部屋の2行目以降はfalse）
 * @var array $mealTypes    [1=>'朝', 2=>'昼', 3=>'夜', 4=>'弁']
 */

$row          = $row ?? [];
$dates        = $dates ?? [];
$grid         = $grid ?? [];
$todayStr     = $todayStr ?? date('Y-m-d');
$showRoomName = $showRoomName ?? true;
$mealTypes    = $mealTypes ?? [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁'];
$interactive  = $interactive ?? true;

$uid       = (int)$row['user_id'];
$rid       = (int)$row['room_id'];
$nameFirst = mb_substr((string)($row['user_name'] ?? ''), 0, 1);
?>
<tr>
    <td class="col-room"><?= $showRoomName ? h($row['room_name']) : '' ?></td>
    <td class="col-name">
        <span style="display:inline-flex;align-items:center;justify-content:center;
                     width:22px;height:22px;border-radius:50%;
                     background:#3b82f6;color:#fff;
                     font-size:11px;font-weight:700;flex-shrink:0;">
            <?= h($nameFirst) ?>
        </span>
    </td>
    <?php foreach ($dates as $d): ?>
        <?php
        $dateStr = $d->format('Y-m-d');
        $dow     = (int)$d->format('N');
        $isToday = ($dateStr === $todayStr);
        $isSat   = ($dow === 6);
        $isSun   = ($dow === 7);
        $dayCls  = match(true) {
            $isToday => 'is-today',
            $isSat   => 'is-saturday',
            $isSun   => 'is-sunday',
            default  => '',
        };
        ?>
        <?php foreach ($mealTypes as $mt => $ml): ?>
            <?php $reserved = ($grid[$uid][$rid][$dateStr][$mt] ?? 0) === 1; ?>
            <?php if ($interactive): ?>
            <td class="cell-meal sr-toggleable <?= $mt === 1 ? 'meal-first' : '' ?> <?= $dayCls ?>"
                data-user-id="<?= $uid ?>"
                data-room-id="<?= $rid ?>"
                data-date="<?= h($dateStr) ?>"
                data-meal="<?= $mt ?>"
                data-reserved="<?= $reserved ? '1' : '0' ?>"
                style="cursor:pointer;">
                <?php if ($reserved): ?>
                    <span class="sr-cell-check"></span>
                <?php endif; ?>
            </td>
            <?php else: ?>
            <td class="cell-meal <?= $mt === 1 ? 'meal-first' : '' ?> <?= $dayCls ?>">
                <?php if ($reserved): ?>
                    <span class="sr-cell-check"></span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endforeach; ?>
</tr>
