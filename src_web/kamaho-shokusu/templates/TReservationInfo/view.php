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
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700&display=swap');

    #mainNav { display: none !important; }
    body { padding-top: 0 !important; background: #f6f8fb; }
    main.container { max-width: 100%; padding: 0; }

    .page-shell {
        display: grid;
        grid-template-columns: 260px 1fr;
        min-height: 100vh;
        font-family: "M PLUS Rounded 1c", "Noto Sans JP", sans-serif;
        color: #223;
    }
    .side {
        background: #ffffff;
        border-right: 1px solid #e8edf3;
        padding: 22px 18px;
        position: sticky;
        top: 0;
        height: 100vh;
    }
    .brand {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
        font-size: 1.2rem;
        margin-bottom: 18px;
    }
    .brand-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: #62cbbf;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 1.2rem;
    }
    .profile-card {
        background: #f7fafc;
        border: 1px solid #edf2f7;
        border-radius: 16px;
        padding: 14px;
        display: grid;
        grid-template-columns: 48px 1fr;
        gap: 12px;
        align-items: center;
        margin-bottom: 18px;
    }
    .avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #e2e8f0;
        display: grid;
        place-items: center;
        font-weight: 700;
        color: #4a5568;
    }
    .profile-meta { font-size: .85rem; color: #718096; }
    .profile-name { font-weight: 700; margin-top: 2px; }
    .menu-title {
        font-size: .85rem;
        color: #9aa6b2;
        letter-spacing: .08em;
        margin: 14px 0 8px;
    }
    .menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 12px;
        text-decoration: none;
        color: #374151;
        font-weight: 500;
    }
    .menu-item.active {
        background: #e9f6f3;
        color: #148777;
        border: 1px solid #cfeee7;
    }
    .menu-item:hover { background: #f3f6f9; }

    .main {
        padding: 26px 28px 40px;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
    }
    .top-title { font-size: 1.2rem; font-weight: 700; }
    .date-pill {
        background: #ffffff;
        border: 1px solid #e8edf3;
        border-radius: 12px;
        padding: 8px 14px;
        font-weight: 600;
        color: #52606d;
    }
    .bell {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        border: 1px solid #e8edf3;
        background: #fff;
        display: grid;
        place-items: center;
        color: #73808c;
    }

    .card {
        background: #fff;
        border: 1px solid #edf2f7;
        border-radius: 18px;
        padding: 18px;
    }
    .subhead {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
    }
    .subtext { color: #7a8b97; font-size: .9rem; }

    .tabs {
        display: flex;
        gap: 10px;
        border-bottom: 1px solid #edf2f7;
        margin-top: 12px;
    }
    .tab {
        padding: 10px 12px;
        border-bottom: 2px solid transparent;
        text-decoration: none;
        color: #6b7785;
        font-weight: 600;
    }
    .tab.active {
        color: #0f8a7a;
        border-bottom-color: #0f8a7a;
    }

    .table-row {
        display: grid;
        grid-template-columns: 60px 1fr 110px 110px 110px 110px;
        align-items: center;
        gap: 10px;
        padding: 10px 6px;
        border-bottom: 1px solid #f0f2f5;
    }
    .table-row.header {
        color: #8a96a3;
        font-size: .85rem;
        font-weight: 600;
    }
    .meal-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #eef6ff;
        border: 1px solid #dbe7ff;
        color: #2c5aa0;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .85rem;
        font-weight: 600;
    }
    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .85rem;
        font-weight: 600;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .other-room {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 6px;
        font-size: .78rem;
        color: #6b7785;
        background: #f8fafc;
        border: 1px dashed #e2e8f0;
        padding: 2px 6px;
        border-radius: 8px;
        white-space: nowrap;
    }
    .status-yes {
        background: #e8fff2;
        color: #15803d;
        border-color: #c9f5dd;
    }
    .status-no {
        background: #f1f5f9;
        color: #64748b;
        border-color: #e2e8f0;
    }
    .status-ok {
        background: #e8fff2;
        color: #15803d;
        border: 1px solid #c9f5dd;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .status-ng {
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .85rem;
        font-weight: 600;
    }
    .summary {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
        color: #6b7785;
        font-weight: 600;
    }
    .summary .num { font-size: 1.1rem; color: #222; }
    .btn-teal {
        padding: 8px 14px;
        border-radius: 12px;
        background: #62cbbf;
        color: #fff;
        font-weight: 700;
        text-decoration: none;
        border: none;
    }

    /* 部屋別サマリーテーブル */
    .summary-card { margin-bottom: 20px; }
    .summary-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .92rem;
    }
    .summary-table th {
        background: #f0f4f8;
        color: #52606d;
        font-weight: 700;
        padding: 10px 12px;
        text-align: center;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .summary-table th.room-col {
        text-align: left;
        min-width: 120px;
    }
    .summary-table td {
        padding: 10px 12px;
        border: 1px solid #e8edf3;
        text-align: center;
        vertical-align: middle;
    }
    .summary-table td.room-name-cell {
        text-align: left;
        font-weight: 600;
        color: #374151;
        white-space: nowrap;
    }
    .eat-count {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #e8fff2;
        color: #15803d;
        border: 1px solid #c9f5dd;
        border-radius: 8px;
        padding: 3px 10px;
        font-weight: 700;
        font-size: .9rem;
    }
    .eat-count .sub {
        font-size: .78rem;
        color: #64748b;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 2px 6px;
        font-weight: 500;
    }
    .zero-count {
        color: #94a3b8;
        font-size: .88rem;
    }

    .mobile-menu-btn {
        display: none;
        padding: 8px 14px;
        border: 1px solid #e8edf3;
        border-radius: 10px;
        background: #fff;
        font-weight: 600;
        font-size: .9rem;
        cursor: pointer;
        color: #374151;
    }
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.4);
        z-index: 900;
    }
    @media (max-width: 992px) {
        .page-shell { grid-template-columns: 1fr; }
        .side {
            position: fixed;
            top: 0; left: 0;
            width: 78%;
            max-width: 300px;
            height: 100%;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform .3s ease;
            border-right: 1px solid #e8edf3;
        }
        .side.is-open { transform: translateX(0); }
        .sidebar-overlay.is-active { display: block; }
        .mobile-menu-btn { display: inline-block; }
        .topbar { flex-wrap: wrap; }
        .table-scroll-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-row { min-width: 480px; }
        .tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
        .tab { white-space: nowrap; }
        .summary-table { font-size: .82rem; overflow-x: auto; display: block; }
        .summary-table th, .summary-table td { padding: 7px 8px; }
    }
</style>

<div id="sidebar-overlay" class="sidebar-overlay"></div>
<div class="page-shell">
    <aside class="side" id="view-side">
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
            <div class="d-flex align-items-center gap-2">
                <button class="mobile-menu-btn" id="view-sidebar-toggle">☰ メニュー</button>
                <div class="top-title">食数状況確認</div>
            </div>
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
            <div class="table-scroll-wrap">
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
            </div><!-- /.table-scroll-wrap -->
            </div>

            <div class="summary">
                <div>合計利用者　<span class="num"><?= h($totalEat) ?></span> 名</div>
                <div>欠食予定　<span class="num"><?= h($totalNo) ?></span> 名</div>
                <a class="btn-teal" href="<?= $this->Url->build('/') ?>">ダッシュボードへ戻る</a>
            </div>
        </div>
    </main>
</div>
<script>
(function () {
    var btn = document.getElementById('view-sidebar-toggle');
    var side = document.getElementById('view-side');
    var overlay = document.getElementById('sidebar-overlay');
    if (!btn || !side || !overlay) return;
    btn.addEventListener('click', function () {
        side.classList.toggle('is-open');
        overlay.classList.toggle('is-active');
    });
    overlay.addEventListener('click', function () {
        side.classList.remove('is-open');
        overlay.classList.remove('is-active');
    });
})();
</script>