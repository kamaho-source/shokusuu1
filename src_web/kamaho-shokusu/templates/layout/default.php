<!DOCTYPE html>
<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?></title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->meta('icon') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-info">
    <div class="container">
        <a class="navbar-brand" href="<?= $this->Url->build('/TReservationInfo') ?>">食数管理システム</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto ">
                <?php if ($user): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="<?= $this->Url->build('/MRoomInfo/') ?>">部屋情報</a>
                    </li>
                <?php if ($user->get('i_admin') === 1): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="<?= $this->Url->build('/MUserInfo/') ?>">ユーザ一覧</a>
                    </li>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($user && $user->i_admin): ?>
                    <li class="nav-item dropdown">
                        <!-- ドロップダウンをトリガーするリンク -->
                        <a class="nav-link dropdown-toggle active" id="userDropdown" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            予約情報
                        </a>
                        <!-- ドロップダウンメニュー -->
                        <ul class="dropdown-menu" aria-labelledby="userDropdown">
                            <li>
                                <?= $this->Html->link('食数単価一覧', ['controller' => 'MMealPriceInfo', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                                <?= $this->Html->link('食事控除表ダウンロード', ['controller' => 'MMealPriceInfo', 'action' => 'GetMealSummary'], ['class' => 'dropdown-item']) ?>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto">
                <?php if ($user): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <?php if (!empty($user->i_id_staff)): ?>
                                <span class="small text-light">(職員ID: <?= h($user->i_id_staff) ?>)</span>
                            <?php endif; ?>
                            <?= h($user->c_user_name) ?>
                        </a>
                        <div class="dropdown-menu" aria-labelledby="userDropdown">
                            <?= $this->Html->link('プロフィール', ['controller' => 'MUserInfo', 'action' => 'view', $user->i_id_user], ['class' => 'dropdown-item']) ?>
                            <?= $this->Html->link('ログアウト', ['controller' => 'MUserInfo', 'action' => 'logout'], ['class' => 'dropdown-item']) ?>
                            <?php if ($user->i_admin === 1): ?>
                                <?= $this->Html->link('管理者：パスワード変更', ['controller' => 'MUserInfo', 'action' => 'admin_change_password'], ['class' => 'dropdown-item']) ?>
                            <?php endif; ?>
                        </div>
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
