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
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>

    <?= $this->fetch('script') ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-info shadow-sm py-3">
    <div class="container">
        <a class="navbar-brand fs-4" href="<?= $this->Url->build('/TReservationInfo') ?>">食数管理システム</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php if ($user): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $this->Url->build('/MRoomInfo/') ?>">🏠 部屋情報</a>
                    </li>
                    <?php if ($user->get('i_admin') === 1): ?>
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
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto">
                <?php if ($user): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= !empty($user->i_id_staff) ? '<span class="small text-light">(職員ID: ' . h($user->i_id_staff) . ')</span>' : '' ?>
                            <?= h($user->c_user_name) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn" aria-labelledby="userMenu">
                            <li><?= $this->Html->link('👤 プロフィール', ['controller' => 'MUserInfo', 'action' => 'view', $user->i_id_user], ['class' => 'dropdown-item']) ?></li>
                            <li><?= $this->Html->link('🚪 ログアウト', ['controller' => 'MUserInfo', 'action' => 'logout'], ['class' => 'dropdown-item']) ?></li>
                            <?php if ($user->i_admin === 1): ?>
                                <li><?= $this->Html->link('🔒 管理者：パスワード変更', ['controller' => 'MUserInfo', 'action' => 'admin_change_password'], ['class' => 'dropdown-item']) ?></li>
                            <?php endif; ?>
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



<main class="container mt-3">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>

<!-- 必要なスクリプトを正しい順序で読み込む -->
<?= $this->Html->script('jquery-3.5.1.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
</body>
</html>
