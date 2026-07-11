<?php
/**
 * 食数状況確認（部屋別）
 *
 * @var string $date
 * @var array $mealDataArray
 * @var array $authorizedRooms
 * @var array $roomUsers
 * @var array $userMealMap
 * @var array $otherRoomMealMap
 * @var int $activeRoomId
 * @var string $activeRoomName
 * @var int|null $userRoomId
 * @var bool $isAdmin
 */
use Cake\I18n\FrozenDate;

$this->assign('title', '食数状況確認');
$user = $this->request->getAttribute('identity');
$roomsForTabs = $authorizedRooms ?? [];
$activeRoomId = (int)($activeRoomId ?? 0);
$activeRoomName = (string)($activeRoomName ?? '所属部屋');

$targetDate = new FrozenDate($date);
$dow = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabel = $targetDate->format('Y年n月j日') . '(' . $dow[(int)$targetDate->format('w')] . ')';

$mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];

// 選択部屋の集計（昼：$userMealMap から直接算出）
$defaultMealType = 2;
$totalEat = 0;
$totalNo = 0;
foreach ($roomUsers as $u) {
    $uid = (int)$u['user_id'];
    if (!empty($userMealMap[$uid][$defaultMealType])) {
        $totalEat++;
    } else {
        $totalNo++;
    }
}
echo $this->Html->css('pages/t_reservation_view.css');
?>

<div class="rv-wrap">
    <div class="topbar">
        <div class="top-title">食数状況確認</div>
        <div class="date-pill"><?= h($dateLabel) ?></div>
    </div>


        <div class="card">
            <div class="subhead">
                <span>利用者別食数詳細</span>
            </div>
            <div class="subtext"><?= h($dateLabel) ?>・<?= h($activeRoomName) ?></div>

            <div class="tabs">
                <?php
                $viewFormAction = $this->Url->build('/TReservationInfo/view/' . rawurlencode((string)$date));
                $csrfToken = (string)($this->request->getAttribute('csrfToken') ?? '');
                foreach ($roomsForTabs as $rid => $rname):
                    $activeClass = ((int)$rid === (int)$activeRoomId) ? 'active' : '';
                ?>
                    <form method="POST" action="<?= h($viewFormAction) ?>" style="display:inline;margin:0;padding:0">
                        <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="room_id" value="<?= (int)$rid ?>">
                        <button type="submit" class="tab <?= $activeClass ?>"><?= h($rname) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>

            <div class="mt-3">
                <div class="table-row header">
                    <div>番号</div>
                    <div>氏名</div>
                    <div>朝</div>
                    <div>昼</div>
                    <div>夜</div>
                    <div>弁当</div>
                </div>

                <?php if (empty($roomUsers)): ?>
                    <div class="py-3 text-muted">所属利用者がいません。</div>
                <?php else: ?>
                    <?php $idx = 1; ?>
                    <?php foreach ($roomUsers as $u): ?>
                        <?php
                        $uid = (int)$u['user_id'];
                        $b = (bool)($userMealMap[$uid][1] ?? false);
                        $l = (bool)($userMealMap[$uid][2] ?? false);
                        $d = (bool)($userMealMap[$uid][3] ?? false);
                        $bento = (bool)($userMealMap[$uid][4] ?? false);
                        $ob = $otherRoomMealMap[$uid][1] ?? null;
                        $ol = $otherRoomMealMap[$uid][2] ?? null;
                        $od = $otherRoomMealMap[$uid][3] ?? null;
                        $obento = $otherRoomMealMap[$uid][4] ?? null;
                        $otherRooms = array_filter([$ob, $ol, $od, $obento]);
                        $otherRoomLabel = '';
                        if (!empty($otherRooms)) {
                            $otherRoomLabel = implode(' / ', array_unique($otherRooms));
                        }
                        $debugOtherLabels = [];
                        if ($ob) $debugOtherLabels[] = '朝:' . $ob;
                        if ($ol) $debugOtherLabels[] = '昼:' . $ol;
                        if ($od) $debugOtherLabels[] = '夜:' . $od;
                        if ($obento) $debugOtherLabels[] = '弁:' . $obento;
                        $debugOtherRoomLabel = '';
                        if (!empty($debugOtherLabels)) {
                            $debugOtherRoomLabel = implode(' / ', $debugOtherLabels);
                        }
                        ?>
                        <div class="table-row">
                            <div><?= h($idx) ?></div>
                            <div>
                                <div class="fw-semibold d-flex align-items-center flex-wrap">
                                    <span><?= h($u['name']) ?></span>
                                    <?php if ($otherRoomLabel): ?>
                                        <span class="other-room">他部屋: <?= h($otherRoomLabel) ?></span>
                                    <?php endif; ?>

                                </div>
                                <?php if (!empty($u['staff_id'])): ?>
                                    <div class="small text-muted">職員ID: <?= h($u['staff_id']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="status-chip <?= $b ? 'status-yes' : 'status-no' ?>"><?= $b ? '食べる' : '食べない' ?></span>
                            </div>
                            <div>
                                <span class="status-chip <?= $l ? 'status-yes' : 'status-no' ?>"><?= $l ? '食べる' : '食べない' ?></span>
                            </div>
                            <div>
                                <span class="status-chip <?= $d ? 'status-yes' : 'status-no' ?>"><?= $d ? '食べる' : '食べない' ?></span>
                            </div>
                            <div>
                                <span class="status-chip <?= $bento ? 'status-yes' : 'status-no' ?>"><?= $bento ? '食べる' : '食べない' ?></span>
                            </div>
                        </div>
                        <?php $idx++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="summary">
                <div>合計利用者　<span class="num"><?= h($totalEat) ?></span> 名</div>
                <div>欠食予定　<span class="num"><?= h($totalNo) ?></span> 名</div>
                <a class="btn-teal" href="<?= $this->Url->build('/') ?>">ダッシュボードへ戻る</a>
            </div>
        </div>
</div>