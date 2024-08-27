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
            <!-- 左側のリンク -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= $this->Url->build('/MRoomInfo/') ?>">部屋情報</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $this->Url->build('/MUserInfo/') ?>">ユーザ一覧</a>
                </li>
            </ul>
            <!-- 右側のリンク -->
            <ul class="navbar-nav ms-auto">
                <?php if ($user): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <?= h($user->c__user_name) ?> <!-- ユーザー名の表示 -->
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
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>

<!-- 必要なスクリプトを正しい順序で読み込む -->

<?=$this->Html->script('jquery-3.5.1,min.js')?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?> <!-- Popper.jsはバンドル内に含まれています -->
<script>
    $(document).ready(function() {
        // ドロップダウンのリンクをクリックしたときの動作を制御
        $('#userDropdown').on('click', function(event) {
            event.preventDefault(); // デフォルトの動作を無効化
            $(this).dropdown('toggle'); // ドロップダウンをトグル
        });
    });
</script>
</body>
</html>
