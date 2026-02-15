<?php
/**
 * @var \App\View\AppView $this
 * @var mixed $user
 * @var bool $isAdmin
 * @var string $activeKey
 */
$activeKey = $activeKey ?? '';
?>
<aside class="dash-sidebar">
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
    <a class="menu-item <?= $activeKey === 'dashboard' ? 'active' : '' ?>" href="<?= $this->Url->build('/') ?>">ダッシュボード</a>
    <a class="menu-item <?= $activeKey === 'reservation' ? 'active' : '' ?>" href="<?= $this->Url->build('/TReservationInfo') ?>">食数確認・予約</a>
    <a class="menu-item" href="<?= $this->Url->build('/MUserInfo/logout') ?>">ログアウト</a>

    <?php if ($isAdmin): ?>
        <div class="menu-title">管理者メニュー</div>
        <a class="menu-item <?= $activeKey === 'users' ? 'active' : '' ?>" href="<?= $this->Url->build('/MUserInfo') ?>">ユーザ一覧</a>
        <a class="menu-item <?= $activeKey === 'prices' ? 'active' : '' ?>" href="<?= $this->Url->build('/MMealPriceInfo') ?>">食数単価一覧</a>
        <a class="menu-item <?= $activeKey === 'summary' ? 'active' : '' ?>" href="<?= $this->Url->build('/MMealPriceInfo/GetMealSummary') ?>">食事控除表DL</a>
    <?php endif; ?>

    <div class="sidebar-bottom">
        <a class="legacy-home-btn" href="<?= $this->Url->build('/TReservationInfo') ?>">
            <span class="legacy-home-icon">H</span>
            従来のホームを表示
            <span class="legacy-home-arrow">→</span>
        </a>
    </div>
</aside>