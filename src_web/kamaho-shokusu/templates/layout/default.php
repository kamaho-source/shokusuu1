<!DOCTYPE html>
<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?></title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->meta('description', '食数管理システム') ?>
    <?= $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken')) ?>
    <?= $this->Html->css('animate.min.css') ?>
    <?= $this->Html->css('custom.css') ?>
    <?= $this->Html->script('api_response.js') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
</head>
<body>
<?php
/** @var \App\View\AppView $this */
$request    = $this->getRequest();
$isModal    = ($request->getQuery('modal') === '1');
$user       = $request->getAttribute('identity');
$iAdmin     = $user ? (int)$user->i_admin : 0;
$isAdmin    = in_array($iAdmin, [1, 3]);
$isSysAdmin = ($iAdmin === 3);
$isStaff    = $user && ($isAdmin || (int)$user->i_user_level === 0);
$isChild    = $user && (int)$user->i_user_level === 1;
$notificationUnreadCount = $notificationUnreadCount ?? 0;
$recentNotifications     = $recentNotifications ?? [];
?>

<?php if (!$isModal): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-info shadow-sm py-3 fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand fs-4" href="<?= $this->Url->build($isChild ? '/TReservationInfo' : '/') ?>">食数管理システム</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($user): ?>
                        <?php if ($isStaff): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/') ?>">🏠 ダッシュボード</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/TReservationInfo') ?>">🗓 予約（従来）</a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $this->Url->build('/MRoomInfo/') ?>">🏠 部屋情報</a>
                        </li>
                        <?php if ($isAdmin): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/MUserInfo/') ?>">👥 ユーザ一覧</a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($user && $user->i_admin): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" id="adminDropdown" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                📋 予約情報
                            </a>
                            <ul class="dropdown-menu animate__animated animate__fadeIn" aria-labelledby="adminDropdown">
                                <li><?= $this->Html->link('💰 食数単価一覧', ['controller' => 'MMealPriceInfo', 'action' => 'index'], ['class' => 'dropdown-item']) ?></li>
                                <li><?= $this->Html->link('📄 食事控除表ダウンロード', ['controller' => 'MMealPriceInfo', 'action' => 'GetMealSummary'], ['class' => 'dropdown-item']) ?></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><?= $this->Html->link('🔄 部屋異動予約', ['controller' => 'MRoomTransferSchedule', 'action' => 'index'], ['class' => 'dropdown-item']) ?></li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if ($user): ?>
                        <?php if ($isAdmin): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" id="contactDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    &#128140; お問い合わせ
                                </a>
                                <ul class="dropdown-menu animate__animated animate__fadeIn" aria-labelledby="contactDropdown">
                                    <li><?= $this->Html->link('&#128140; お問い合わせフォーム', ['controller' => 'Contacts', 'action' => 'index'], ['class' => 'dropdown-item', 'escape' => false]) ?></li>
                                    <li><?= $this->Html->link('&#128235; 問い合わせ一覧', ['controller' => 'Contacts', 'action' => 'adminIndex'], ['class' => 'dropdown-item', 'escape' => false]) ?></li>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/Contacts') ?>">&#128140; お問い合わせ</a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($user && $isSysAdmin): ?>
                        <li class="nav-item">
                            <a class="nav-link text-warning fw-bold" href="<?= $this->Url->build('/AuditLog') ?>">
                                &#128274; 監査ログ
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <ul class="navbar-nav ms-auto">
                    <?php if ($user): ?>
                        <li class="nav-item dropdown me-2">
                            <a class="nav-link dropdown-toggle position-relative" href="#" id="notificationMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                通知
                                <?php if ($notificationUnreadCount > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?= h($notificationUnreadCount > 99 ? '99+' : (string)$notificationUnreadCount) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn" aria-labelledby="notificationMenu" style="min-width: 24rem;">
                                <?php if (empty($recentNotifications)): ?>
                                    <li><span class="dropdown-item-text text-muted">未読通知はありません</span></li>
                                <?php else: ?>
                                    <?php foreach ($recentNotifications as $notification): ?>
                                        <li>
                                            <a class="dropdown-item text-wrap" href="<?= $this->Url->build('/Notifications') ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <strong class="<?= (int)$notification->i_is_read === 0 ? 'text-dark' : 'text-muted' ?>">
                                                        <?= h($notification->c_title) ?>
                                                    </strong>
                                                    <?php if ((int)$notification->i_is_read === 0): ?>
                                                        <span class="badge bg-danger">未読</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted mt-1"><?= h($notification->c_message) ?></div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><?= $this->Html->link('通知一覧を開く', ['controller' => 'Notifications', 'action' => 'index'], ['class' => 'dropdown-item']) ?></li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?= !empty($user->i_id_staff) ? '<span class="small text-light">(職員ID: ' . h($user->i_id_staff) . ')</span>' : '' ?>
                                <?= h($user->c_user_name) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn" aria-labelledby="userMenu">
                                <li><?= $this->Html->link('👤 プロフィール', ['controller' => 'MUserInfo', 'action' => 'view', $user->i_id_user], ['class' => 'dropdown-item']) ?></li>
                                <li><?= $this->Html->link('🔑 パスワード変更', ['controller' => 'MUserInfo', 'action' => 'general_password_reset'], ['class' => 'dropdown-item']) ?></li>
                                <?php if ($isAdmin): ?>
                                    <li><?= $this->Html->link('🔑 管理者：パスワード変更', ['controller' => 'MUserInfo', 'action' => 'AdminChangePassword'], ['class' => 'dropdown-item']) ?></li>
                                <?php endif; ?>
                                <li><?= $this->Html->link('🚪 ログアウト', ['controller' => 'MUserInfo', 'action' => 'logout'], ['class' => 'dropdown-item']) ?></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <?= $this->Html->link('ログイン', ['controller' => 'MUserInfo', 'action' => 'login'], ['class' => 'nav-link']) ?>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
<?php endif; ?>

<main class="<?= $isModal ? '' : 'container-xl mt-3' ?>">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>

<?= $this->Html->script('jquery-3.5.1.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
<?= $this->Html->script('confirm_popup.js') ?>

<script>
    (() => {
        const nav = document.getElementById('mainNav');
        if (!nav) return;

        const applyPad = () => {
            document.body.style.paddingTop = nav.getBoundingClientRect().height + 'px';
        };

        applyPad();
        window.addEventListener('load', applyPad);
        window.addEventListener('resize', applyPad);

        if (window.ResizeObserver) {
            new ResizeObserver(applyPad).observe(nav);
        }

        document.addEventListener('shown.bs.collapse', applyPad);
        document.addEventListener('hidden.bs.collapse', applyPad);
    })();
</script>
</body>
</html>
