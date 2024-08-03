<!DOCTYPE html>

<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        <?= $this->fetch('title') ?>
    </title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->meta('icon') ?>

    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <a class="navbar-brand" href="<?= $this->Url->build('/') ?>">食数管理システム</a>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav">
            <li class="nav-item active">
                <a class="nav-link" href="<?=$this->Url->build('') ?>">Documentation</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="https://api.cakephp.org/">API</a>
            </li>
        </ul>
    </div>
</nav>
<main class="container mt-3">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>
<footer>
</footer>

<?= $this->Html->script('jquery.slim.min.js') ?>
<?= $this->Html->script('popper.min.js') ?>
<?= $this->Html->script('bootstrap.min.js') ?>
</body>
</html>
