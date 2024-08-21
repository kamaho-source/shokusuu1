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
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item active">
                    <a class="nav-link" href="<?=$this->Url->build('/MRoomInfo/') ?>">部屋情報</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="<?=$this->Url->build('/MUserInfo/')?>">ユーザ一覧</a>
                </li>
                <li class="nav-item dropdown active">
                    <a class="nav-link dropdown-toggle" href="#" id="reservationDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        予約管理
                    </a>
                    <div class="dropdown-menu" aria-labelledby="reservationDropdown">
                        <?= $this->Html->link('予約一覧', ['controller' => 'TReservationInfo', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                        <?= $this->Html->link('予約情報編集', ['controller' => 'TReservationInfo', 'action' => 'edit'], ['class' => 'dropdown-item']) ?>
                        <?= $this->Html->link('予約情報追加', ['controller' => 'TReservationInfo', 'action' => 'add'], ['class' => 'dropdown-item']) ?>
                    </div>
                </li>
            </ul>
            <ul class="navbar-nav">
                <?php if ($user): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <?= h($user->c__user_name) ?> <!-- ここでユーザー名を表示 -->
                        </a>
                        <div class="dropdown-menu" aria-labelledby="userDropdown">
                            <?= $this->Html->link('プロフィール', ['controller' => 'MUserInfo', 'action' => 'view', $user->i_id_user], ['class' => 'dropdown-item']) ?>
                            <?= $this->Html->link('ログアウト', ['controller' => 'MUserInfo', 'action' => 'logout'], ['class' => 'dropdown-item']) ?>
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
    <?php foreach (['flash', 'error', 'success', 'info', 'warning'] as $key): ?>
        <?= $this->Flash->render($key, ['params' => ['class' => 'alert alert-' . ($key === 'flash' ? 'primary' : $key) . ' alert-dismissible fade show']]) ?>
    <?php endforeach; ?>
    <?= $this->fetch('content') ?>
</main>

</main>
<footer>
</footer>
<?= $this->Html->script('jquery.slim.min.js') ?>
<?= $this->Html->script('popper.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
</body>
</html>
