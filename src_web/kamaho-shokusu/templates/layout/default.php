<!DOCTYPE html>
<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: '食数管理システム' ?></title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->meta('description', '食数管理システム') ?>
    <?= $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken')) ?>
    <?= $this->Html->css('animate.min.css') ?>
    <?= $this->Html->css('custom.css') ?>
    <?= $this->Html->css('ai-assistant.css') ?>
    <?= $this->Html->css('layout-header.css') ?>
    <?= $this->Html->script('api_response.js') ?>
    <script>
        window.AI_ASSISTANT_ASK_URL      = '<?= $this->Url->build(['controller' => 'AiAssistant', 'action' => 'ask']) ?>';
        window.AI_ASSISTANT_STREAM_URL   = '<?= $this->Url->build(['controller' => 'AiAssistant', 'action' => 'askStream']) ?>';
        window.AI_ASSISTANT_SUGGEST_URL  = '<?= $this->Url->build(['controller' => 'AiAssistant', 'action' => 'suggestions']) ?>';
        window.AI_ASSISTANT_FEEDBACK_URL = '<?= $this->Url->build(['controller' => 'AiAssistant', 'action' => 'feedback']) ?>';
        window.AI_ASSISTANT_BASE_URL     = '<?= rtrim($this->Url->build('/'), '/') ?>';
    </script>
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
$isStaff    = $user && ($isAdmin || in_array((int)$user->i_user_level, [0, 7]));
$isChild    = $user && (int)$user->i_user_level === 1;
$notificationUnreadCount = $notificationUnreadCount ?? 0;
$recentNotifications     = $recentNotifications ?? [];
?>

<?php if (!$isModal): ?>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $this->Url->build($isChild ? '/TReservationInfo' : '/') ?>">
                <i class="bi bi-calendar-check-fill fs-4"></i>
                <span>食数管理システム</span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($user): ?>
                        <?php if ($isStaff): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/') ?>">
                                    <i class="bi bi-speedometer2"></i>ダッシュボード
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/TReservationInfo') ?>">
                                    <i class="bi bi-calendar3"></i>予約
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $this->Url->build('/MRoomInfo/') ?>">
                                <i class="bi bi-door-open"></i>部屋情報
                            </a>
                        </li>
                        <?php if ($isAdmin): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $this->Url->build('/MUserInfo/') ?>">
                                    <i class="bi bi-people"></i>ユーザ一覧
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($user && $user->i_admin): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" id="adminDropdown" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-journal-text"></i>予約管理
                            </a>
                            <ul class="dropdown-menu border-0 shadow-sm animate__animated animate__fadeIn" aria-labelledby="adminDropdown">
                                <li><?= $this->Html->link('💰 食数単価一覧', ['controller' => 'MMealPriceInfo', 'action' => 'index'], ['class' => 'dropdown-item']) ?></li>
                                <li><?= $this->Html->link('📄 食事控除表', ['controller' => 'MMealPriceInfo', 'action' => 'GetMealSummary'], ['class' => 'dropdown-item']) ?></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><?= $this->Html->link('🔄 部屋異動予約', ['controller' => 'MRoomTransferSchedule', 'action' => 'index'], ['class' => 'dropdown-item']) ?></li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if ($user): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $this->Url->build('/Contacts') ?>">
                                <i class="bi bi-envelope"></i>お問い合わせ
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($user && $isSysAdmin): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>管理
                            </a>
                            <ul class="dropdown-menu border-0 shadow-sm">
                                <li>
                                    <a class="dropdown-item" href="<?= $this->Url->build('/admin/tenants') ?>">
                                        <i class="bi bi-building me-2 text-primary"></i>テナント管理
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?= $this->Url->build('/AuditLog') ?>">
                                        <i class="bi bi-shield-lock me-2 text-danger"></i>監査ログ
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?= $this->Url->build('/FeatureUsageSummary') ?>">
                                        <i class="bi bi-bar-chart me-2 text-warning"></i>機能使用頻度
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>
                </ul>

                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <?php
                    $allTenants     = $allTenants ?? [];
                    $activeTenantId = $activeTenantId ?? null;
                    // 現在操作中のテナント名を取得（バナー用）
                    $activeTenantName = null;
                    if ($isSysAdmin && $activeTenantId !== null) {
                        foreach ($allTenants as $t) {
                            if ($t->id === $activeTenantId) {
                                $activeTenantName = $t->name;
                                break;
                            }
                        }
                    }
                    ?>
                    <?php if ($user): ?>
                        <li class="nav-item dropdown me-2">
                            <a class="nav-link dropdown-toggle position-relative" href="#" id="notificationMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-bell"></i>
                                <?php if ($notificationUnreadCount > 0): ?>
                                    <span class="badge rounded-pill bg-danger">
                                        <?= h($notificationUnreadCount > 99 ? '99+' : (string)$notificationUnreadCount) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow animate__animated animate__fadeIn notification-dropdown" aria-labelledby="notificationMenu">
                                <?php if (empty($recentNotifications)): ?>
                                    <li><span class="dropdown-item-text text-muted">未読通知はありません</span></li>
                                <?php else: ?>
                                    <?php foreach ($recentNotifications as $notification): ?>
                                        <li>
                                            <a class="dropdown-item text-wrap border-bottom py-2" href="<?= $this->Url->build('/Notifications') ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <strong class="<?= (int)$notification->i_is_read === 0 ? 'text-dark' : 'text-muted' ?> small">
                                                        <?= h($notification->c_title) ?>
                                                    </strong>
                                                    <?php if ((int)$notification->i_is_read === 0): ?>
                                                        <span class="badge bg-danger" style="font-size: 0.6rem;">未読</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted mt-1" style="font-size: 0.75rem;"><?= h($notification->c_message) ?></div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <li class="text-center pt-2"><?= $this->Html->link('すべての通知を表示', ['controller' => 'Notifications', 'action' => 'index'], ['class' => 'dropdown-item small text-primary']) ?></li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle text-white rounded-pill px-3" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="background: rgba(255,255,255,0.2);">
                                <i class="bi bi-person-circle"></i>
                                <span class="d-inline-block text-truncate" style="max-width: 100px;"><?= h($user->c_user_name) ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow animate__animated animate__fadeIn" aria-labelledby="userMenu">
                                <li class="dropdown-header">
                                    <?= !empty($user->i_id_staff) ? '職員ID: ' . h($user->i_id_staff) : 'ユーザー' ?>
                                </li>
                                <li><?= $this->Html->link('👤 プロフィール', ['controller' => 'MUserInfo', 'action' => 'view', $user->i_id_user], ['class' => 'dropdown-item']) ?></li>
                                <li><?= $this->Html->link('🔑 パスワード変更', ['controller' => 'MUserInfo', 'action' => 'generalPasswordReset'], ['class' => 'dropdown-item']) ?></li>
                                <?php if ($isAdmin): ?>
                                    <li><?= $this->Html->link('🔑 管理者：パスワード変更', ['controller' => 'MUserInfo', 'action' => 'adminChangePassword'], ['class' => 'dropdown-item']) ?></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><?= $this->Html->link('🚪 ログアウト', ['controller' => 'MUserInfo', 'action' => 'logout'], ['class' => 'dropdown-item text-danger']) ?></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <?= $this->Html->link('ログイン', ['controller' => 'MUserInfo', 'action' => 'login'], ['class' => 'btn btn-outline-info rounded-pill px-4']) ?>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <?php if ($user): ?>
        <button id="ai-assistant-fab" title="お問い合わせAIに質問">
            <i class="bi bi-robot"></i>
            <span class="spinner-border spinner-border-sm d-none" id="ai-assistant-loading-fab" role="status"></span>
        </button>

        <!-- AI Assistant Chat Panel (Replaced Modal) -->
        <div id="ai-assistant-panel" class="shadow-lg border">
            <div class="panel-header bg-info text-white d-flex align-items-center justify-content-between p-3">
                <h5 class="m-0"><i class="bi bi-robot me-2"></i>お問い合わせAI</h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" id="ai-reset-btn" class="btn btn-sm btn-outline-light py-0 px-2" title="会話をリセット">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" class="btn-close btn-close-white" id="ai-panel-close"></button>
                </div>
            </div>
            <div class="panel-body bg-light p-3">
                <div id="ai-chat-box" class="mb-3 p-3 border rounded bg-white shadow-sm">
                    <div class="mb-3 p-2 rounded bg-info bg-opacity-10">
                        <strong>お問い合わせAI:</strong><br>
                        こんにちは！「食数管理システム」の使い方について何でも聞いてください。<br>
                        どのようなお手伝いが必要ですか？
                    </div>
                </div>
                <form id="ai-assistant-form">
                    <div class="input-group">
                        <input type="text" id="ai-question" class="form-control form-control-sm" placeholder="質問を入力..." required>
                        <button class="btn btn-info text-white btn-sm px-3" type="submit" id="ai-submit-btn">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$isModal && $isSysAdmin && $activeTenantName !== null): ?>
    <div class="tenant-context-banner">
        <div class="container d-flex align-items-center justify-content-between gap-2 py-1">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-building-fill"></i>
                <strong><?= h($activeTenantName) ?></strong>
                <span class="opacity-75">を操作中</span>
            </span>
            <a href="<?= $this->Url->build('/admin/tenants') ?>" class="tenant-context-banner__link">
                <i class="bi bi-grid me-1"></i>テナント一覧へ戻る
            </a>
        </div>
    </div>
<?php elseif (!$isModal && $isSysAdmin && $activeTenantName === null && $user): ?>
    <div class="tenant-context-banner tenant-context-banner--all">
        <div class="container d-flex align-items-center justify-content-between gap-2 py-1">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-globe"></i>
                <span>全テナントモード</span>
            </span>
            <a href="<?= $this->Url->build('/admin/tenants') ?>" class="tenant-context-banner__link">
                <i class="bi bi-grid me-1"></i>テナントを選択する
            </a>
        </div>
    </div>
<?php endif; ?>

<main class="<?= $isModal ? '' : 'container mt-3' ?>">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>

<?= $this->Html->script('jquery-3.5.1.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
<?= $this->Html->script('confirm_popup.js') ?>
<?= $this->Html->script('ai-assistant.js') ?>

<script>
    (() => {
        const nav = document.getElementById('mainNav');
        if (!nav) return;

        const applyPad = () => {
            const navH = nav.getBoundingClientRect().height;
            document.documentElement.style.setProperty('--nav-height', navH + 'px');

            const banner = document.querySelector('.tenant-context-banner');
            const bannerH = banner ? banner.getBoundingClientRect().height : 0;
            document.body.style.paddingTop = (navH + bannerH) + 'px';
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