<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Database\StatementInterface $error
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->layout = 'error';

$messageMap = [
    'Bad Request'           => '不正なリクエスト',
    'Forbidden'             => 'アクセス権限がありません',
    'Not Found'             => 'ページが見つかりません',
    'Method Not Allowed'    => '許可されていないメソッドです',
    'Gone'                  => 'このページは削除されました',
    'Unauthorized'          => '認証が必要です',
];
$japaneseTitle = $messageMap[$message] ?? 'エラーが発生しました';

if (Configure::read('debug')) :
    $this->layout = 'dev_error';

    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
?>
<?php if (!empty($error->queryString)) : ?>
    <p class="notice">
        <strong>SQL Query: </strong>
        <?= h($error->queryString) ?>
    </p>
<?php endif; ?>
<?php if (!empty($error->params)) : ?>
    <strong>SQL Query Params: </strong>
    <?php Debugger::dump($error->params) ?>
<?php endif; ?>

<?php
    echo $this->element('auto_table_warning');

    $this->end();
endif;

$this->assign('title', $japaneseTitle);
?>
<h2><?= h($japaneseTitle) ?></h2>
<p class="error">
    <strong>エラー: </strong>
    <?= sprintf('リクエストされたアドレス <strong>\'%s\'</strong> はこのサーバーで見つかりませんでした。', h($url)) ?>
</p>
