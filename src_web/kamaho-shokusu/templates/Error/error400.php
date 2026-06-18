<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 * @var string $message
 * @var string $url
 * @var int $code
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->layout = 'error';

// デバッグ時は CakePHP 既定の開発用レイアウトを使用する
if (Configure::read('debug')) {
    $this->layout = 'dev_error';
    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    if (!empty($error->queryString)) :
        echo '<p class="notice"><strong>SQL Query: </strong>' . h($error->queryString) . '</p>';
    endif;
    if (!empty($error->params)) :
        echo '<strong>SQL Query Params: </strong>';
        Debugger::dump($error->params);
    endif;
    echo $this->element('auto_table_warning');
    $this->end();
}

// ステータスコード別の表示設定
$statusCode = $code ?? 400;
$configs = [
    400 => [
        'icon'       => 'bi-exclamation-triangle-fill',
        'iconBg'     => 'bg-warning bg-opacity-10 text-warning',
        'title'      => '不正なリクエストです',
        'lead'       => 'リクエストの形式が正しくありません。',
        'suggestion' => '入力内容を確認してから、もう一度お試しください。',
        'badge'      => 'warning',
    ],
    401 => [
        'icon'       => 'bi-lock-fill',
        'iconBg'     => 'bg-secondary bg-opacity-10 text-secondary',
        'title'      => 'ログインが必要です',
        'lead'       => 'このページを表示するにはログインが必要です。',
        'suggestion' => 'ログインページからログインしてください。',
        'badge'      => 'secondary',
        'action'     => ['url' => '/MUserInfo/login', 'label' => 'ログインページへ', 'icon' => 'bi-box-arrow-in-right'],
    ],
    403 => [
        'icon'       => 'bi-shield-fill-x',
        'iconBg'     => 'bg-danger bg-opacity-10 text-danger',
        'title'      => 'アクセス権限がありません',
        'lead'       => 'このページを表示する権限がありません。',
        'suggestion' => '管理者にお問い合わせいただくか、別のアカウントでログインしてください。',
        'badge'      => 'danger',
    ],
    404 => [
        'icon'       => 'bi-search',
        'iconBg'     => 'bg-info bg-opacity-10 text-info',
        'title'      => 'ページが見つかりません',
        'lead'       => 'お探しのページは存在しないか、移動・削除された可能性があります。',
        'suggestion' => 'URLをご確認のうえ、トップページからお探しください。',
        'badge'      => 'info',
    ],
    405 => [
        'icon'       => 'bi-slash-circle-fill',
        'iconBg'     => 'bg-warning bg-opacity-10 text-warning',
        'title'      => '操作が許可されていません',
        'lead'       => 'このリクエストは許可されていません。',
        'suggestion' => '操作が正しいかどうかご確認ください。',
        'badge'      => 'warning',
    ],
    410 => [
        'icon'       => 'bi-trash3-fill',
        'iconBg'     => 'bg-secondary bg-opacity-10 text-secondary',
        'title'      => 'このページは削除されました',
        'lead'       => 'お探しのページは削除されています。',
        'suggestion' => 'トップページからお探しください。',
        'badge'      => 'secondary',
    ],
];

$cfg = $configs[$statusCode] ?? [
    'icon'       => 'bi-exclamation-circle-fill',
    'iconBg'     => 'bg-warning bg-opacity-10 text-warning',
    'title'      => 'エラーが発生しました',
    'lead'       => '予期しないエラーが発生しました。',
    'suggestion' => '前のページに戻るか、トップページへお進みください。',
    'badge'      => 'warning',
];

$this->assign('title', $cfg['title']);
?>

<div class="text-center mb-4">
    <div class="error-icon-wrap <?= $cfg['iconBg'] ?> mx-auto">
        <i class="bi <?= $cfg['icon'] ?>"></i>
    </div>
    <div class="error-code text-<?= $cfg['badge'] ?> mb-2"><?= $statusCode ?></div>
    <h1 class="h3 fw-bold"><?= h($cfg['title']) ?></h1>
</div>

<p class="text-center text-muted lead mb-2"><?= h($cfg['lead']) ?></p>
<p class="text-center text-muted small mb-4"><?= h($cfg['suggestion']) ?></p>

<div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
    <a href="javascript:history.back()" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>前のページに戻る
    </a>
    <a href="/" class="btn btn-info text-white">
        <i class="bi bi-house me-1"></i>トップページへ
    </a>
    <?php if (!empty($cfg['action'])): ?>
        <a href="<?= h($cfg['action']['url']) ?>" class="btn btn-primary">
            <i class="bi <?= h($cfg['action']['icon']) ?> me-1"></i><?= h($cfg['action']['label']) ?>
        </a>
    <?php endif; ?>
</div>

<?php if (Configure::read('debug')): ?>
<hr class="mt-5">
<div class="alert alert-warning mt-3 small text-start">
    <strong>デバッグ情報（本番環境では非表示）:</strong><br>
    URL: <code><?= h($url) ?></code><br>
    エラー: <?= h($message) ?>
</div>
<?php endif; ?>
