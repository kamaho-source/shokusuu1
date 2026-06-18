<?php
/** @var \App\View\AppView $this */
use Cake\Core\Configure;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: 'エラー' ?> — 食数管理システム</title>
    <?= $this->Html->css('bootstrap.min.css') ?>
    <?= $this->Html->css('animate.min.css') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #e0f7fa 0%, #f5f5f5 60%, #fff 100%);
            display: flex;
            flex-direction: column;
        }
        .error-card {
            max-width: 600px;
            border: none;
            border-radius: 1.25rem;
            box-shadow: 0 8px 32px rgba(0,0,0,.10);
        }
        .error-icon-wrap {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.8rem;
            margin: 0 auto 1.25rem;
        }
        .error-code {
            font-size: 4.5rem;
            font-weight: 800;
            letter-spacing: -2px;
            line-height: 1;
        }
        footer {
            margin-top: auto;
        }
    </style>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-info shadow-sm py-2 fixed-top" id="mainNav">
    <div class="container">
        <a class="navbar-brand fs-4" href="/">食数管理システム</a>
    </div>
</nav>

<main class="container flex-grow-1 d-flex align-items-center justify-content-center py-5">
    <div class="error-card card p-4 p-md-5 w-100 animate__animated animate__fadeInUp">
        <?= $this->fetch('content') ?>
    </div>
</main>

<footer class="text-center text-muted small py-3">
    &copy; <?= date('Y') ?> 食数管理システム
</footer>

<?= $this->Html->script('bootstrap.bundle.min.js') ?>
<?= $this->fetch('script') ?>
<script>
    (() => {
        const nav = document.getElementById('mainNav');
        if (!nav) return;
        const applyPad = () => { document.body.style.paddingTop = nav.getBoundingClientRect().height + 'px'; };
        applyPad();
        window.addEventListener('load', applyPad);
        window.addEventListener('resize', applyPad);
    })();
</script>
</body>
</html>
