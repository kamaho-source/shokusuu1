<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->layout = 'error';

// デバッグ時は CakePHP 既定の開発用レイアウトを使用する
if (Configure::read('debug')) {
    $this->layout = 'dev_error';
    $this->assign('title', $message);
    $this->assign('templateName', 'error500.php');

    $this->start('file');
    if (!empty($error->queryString)) :
        echo '<p class="notice"><strong>SQL Query: </strong>' . h($error->queryString) . '</p>';
    endif;
    if (!empty($error->params)) :
        echo '<strong>SQL Query Params: </strong>';
        Debugger::dump($error->params);
    endif;
    if ($error instanceof Error) :
        $file = $error->getFile();
        $line = $error->getLine();
        echo '<strong>エラー箇所: </strong>';
        echo $this->Html->link(
            sprintf('%s, line %s', Debugger::trimPath($file), $line),
            Debugger::editorUrl($file, $line)
        );
    endif;
    echo $this->element('auto_table_warning');
    $this->end();
}

$this->assign('title', 'システムエラーが発生しました');
?>

<div class="text-center mb-4">
    <div class="error-icon-wrap bg-danger bg-opacity-10 text-danger mx-auto">
        <i class="bi bi-cloud-slash-fill"></i>
    </div>
    <div class="error-code text-danger mb-2">500</div>
    <h1 class="h3 fw-bold">システムエラーが発生しました</h1>
</div>

<p class="text-center text-muted lead mb-2">
    申し訳ありません。一時的なシステムエラーが発生しています。
</p>
<p class="text-center text-muted small mb-4">
    しばらく時間をおいてから再度お試しください。<br>
    問題が続く場合は、システム管理者へお問い合わせください。
</p>

<div class="alert alert-light border text-center small mb-4">
    <i class="bi bi-info-circle me-1 text-info"></i>
    エラーは自動的に記録されています。担当者が確認・対応いたします。
</div>

<div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
    <a href="javascript:history.back()" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>前のページに戻る
    </a>
    <a href="/" class="btn btn-info text-white">
        <i class="bi bi-house me-1"></i>トップページへ
    </a>
    <a href="/Contacts" class="btn btn-outline-primary">
        <i class="bi bi-envelope me-1"></i>お問い合わせ
    </a>
</div>

<?php if (Configure::read('debug')): ?>
<hr class="mt-5">
<div class="alert alert-danger mt-3 small text-start">
    <strong>デバッグ情報（本番環境では非表示）:</strong><br>
    エラー: <code><?= h($message) ?></code>
    <?php if ($error instanceof \Throwable): ?>
        <br>ファイル: <code><?= h($error->getFile()) ?>:<?= (int)$error->getLine() ?></code>
        <details class="mt-2">
            <summary>スタックトレース</summary>
            <pre class="mt-2" style="font-size:0.75rem;overflow:auto;max-height:300px"><?= h($error->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>
