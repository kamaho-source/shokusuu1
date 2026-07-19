<!DOCTYPE html>
<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: '食数管理システム' ?></title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken')) ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->fetch('css') ?>
    <style>
        body { background: #f0f4f8; min-height: 100vh; }
        .public-header {
            background: #0dcaf0;
            padding: 1rem 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .public-header .brand {
            color: #fff;
            font-weight: 700;
            font-size: 1.2rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .public-footer {
            background: #fff;
            border-top: 1px solid #e8ecf0;
            padding: 1.5rem 0;
            color: #6c757d;
            font-size: 0.85rem;
            text-align: center;
        }
    </style>
</head>
<body>

<header class="public-header">
    <div class="container">
        <a class="brand" href="<?= $this->Url->build('/') ?>">
            <i class="bi bi-calendar-check-fill fs-5"></i>
            食数管理システム
        </a>
    </div>
</header>

<main class="container py-5">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>

<footer class="public-footer">
    <div class="container">
        &copy; <?= date('Y') ?> 食数管理システム
    </div>
</footer>

<?= $this->Html->script('jquery-3.5.1.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
<?= $this->fetch('script') ?>
</body>
</html>
