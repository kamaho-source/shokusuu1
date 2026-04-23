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

// 選択部屋の集計
$roomMealSummary = [];
foreach ($mealTypes as $mealType => $mealLabel) {
    $data = $mealDataArray[$mealLabel][$activeRoomId] ?? null;
    $roomMealSummary[$mealType] = [
        'label' => $mealLabel,
        'eat' => (int)($data['taberu_ninzuu'] ?? 0),
        'no' => (int)($data['tabenai_ninzuu'] ?? 0),
    ];
}

// 表示用（昼をデフォルトで合計表示）
$defaultMealType = 2;
$totalEat = $roomMealSummary[$defaultMealType]['eat'] ?? 0;
$totalNo = $roomMealSummary[$defaultMealType]['no'] ?? 0;
echo $this->Html->css('pages/t_reservation_view.css');
?>

<div class="page-shell">
    <aside class="side">
        <div class="brand">
            <div class="brand-icon">🍴</div>
            食数管理システム
        </div>
        <div class="profile-card">
            <div class="avatar"><?=h(mb_substr($user->get('c_user_name'), 0, 1))?></div>
            <div>
                <div class="profile-meta">STAFF ID: <?= h($user->get('i_id_staff') ?? '---') ?></div>
                <div class="profile-name"><?= h($user->get('c_user_name') ?? '') ?></div>
            </div>
        </div>

        <div class="menu-title">メインメニュー</div>
        <a class="menu-item" href="<?= $this->Url->build('/') ?>">ダッシュボード</a>
        <a class="menu-item active" href="<?= $this->Url->build('/TReservationInfo/view/' . h($date)) ?>">食数状況確認</a>
        <a class="menu-item" href="<?= $this->Url->build('/TReservationInfo') ?>">食数確認・予約</a>
        <?php if ($isAdmin): ?>
            <a class="menu-item" href="<?= $this->Url->build('/MUserInfo') ?>">利用者管理</a>
            <a class="menu-item" href="<?= $this->Url->build('/MMealPriceInfo/GetMealSummary') ?>">集計・出力</a>
        <?php endif; ?>
    </aside>

    <main class="main">
        <div class="topbar">
            <div class="top-title">食数状況確認</div>
            <div class="d-flex align-items-center gap-2">
                <div class="date-pill"><?= h($dateLabel) ?></div>
                <div class="bell">🔔</div>
            </div>
        </div>
        

        <div class="card">
            <div class="subhead">
                <span>利用者別食数詳細</span>
            </div>
            <div class="subtext"><?= h($dateLabel) ?>・<?= h($activeRoomName) ?></div>

            <div class="tabs">
                <?php foreach ($roomsForTabs as $rid => $rname): ?>
                    <a class="tab <?= ((int)$rid === (int)$activeRoomId) ? 'active' : '' ?>"
                       href="<?= $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'view', $date, '?' => ['room_id' => $rid]]) ?>">
                        <?= h($rname) ?>
                    </a>
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
    </main>
</div>
