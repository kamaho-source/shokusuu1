<?php
/**
 * 監査ログのページネーション（現在地が分かる「N / M ページ」表示 + 先頭・末尾 + 前後の窓 + ページ指定）
 *
 * @var \App\View\AppView $this
 */
$params    = $this->Paginator->params();
$current   = (int)($params['currentPage'] ?? $params['page'] ?? 1);
$pageCount = (int)($params['pageCount'] ?? 1);
if ($pageCount <= 1) {
    return;
}

// Bootstrap 5 のページネーション用テンプレート（numbers() のオプションではクラスが付かない）
$this->Paginator->setTemplates([
    'number'   => '<li class="page-item"><a class="page-link" href="{{url}}">{{text}}</a></li>',
    'current'  => '<li class="page-item active" aria-current="page"><span class="page-link">{{text}}</span></li>',
    'ellipsis' => '<li class="page-item disabled"><span class="page-link">…</span></li>',
]);
$edge = static fn(string $label, string $title, bool $disabled, string $url = ''): string => $disabled
    ? '<li class="page-item disabled"><span class="page-link" title="' . h($title) . '">' . $label . '</span></li>'
    : '<li class="page-item"><a class="page-link" href="' . h($url) . '" title="' . h($title) . '">' . $label . '</a></li>';
$urlFor = fn(int $page): string => $this->Paginator->generateUrl(['page' => $page], [], ['escape' => false]);

// ページ指定フォームは現在の絞り込み条件を引き継ぐ
$hidden = [];
foreach ($this->getRequest()->getQueryParams() as $k => $v) {
    if ($k !== 'page' && is_scalar($v)) {
        $hidden[$k] = (string)$v;
    }
}
?>
<style>
    .audit-pager { background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 10px 14px; }
    .audit-pager .pagination { gap: 4px; flex-wrap: wrap; }
    .audit-pager .page-link { min-width: 44px; padding: .5rem .8rem; font-size: 1rem; text-align: center; border-radius: 8px !important; }
    .audit-pager .page-item.active .page-link { background: #0d6efd; border-color: #0d6efd; color: #fff; font-weight: 700; }
    .audit-pager .page-item.disabled .page-link { background: transparent; border-color: transparent; color: #6c757d; }
    .audit-pager .pg-nav .page-link { font-weight: 600; }
</style>
<nav aria-label="ページ移動" class="audit-pager d-flex flex-wrap align-items-center justify-content-between gap-2">
    <ul class="pagination pg-nav mb-0">
        <?= $edge('« 最初', '最初のページへ', $current <= 1, $urlFor(1)) ?>
        <?= $edge('‹ 前へ', '前のページへ', $current <= 1, $urlFor(max(1, $current - 1))) ?>
    </ul>
    <ul class="pagination mb-0 justify-content-center">
        <?= $this->Paginator->numbers(['modulus' => 4, 'first' => 1, 'last' => 1]) ?>
    </ul>
    <ul class="pagination pg-nav mb-0">
        <?= $edge('次へ ›', '次のページへ', $current >= $pageCount, $urlFor(min($pageCount, $current + 1))) ?>
        <?= $edge('最後 »', '最後のページへ', $current >= $pageCount, $urlFor($pageCount)) ?>
    </ul>
    <form method="get" class="d-flex align-items-center gap-2 mb-0 w-100 justify-content-center border-top pt-2">
        <?php foreach ($hidden as $k => $v): ?>
            <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <span class="fw-bold fs-6"><?= $current ?> / <?= $pageCount ?> ページ</span>
        <label for="auditPageJump<?= spl_object_id($this) ?>" class="text-muted small ms-2">ページを指定:</label>
        <select name="page" id="auditPageJump<?= spl_object_id($this) ?>" class="form-select form-select-sm w-auto"
                onchange="this.form.submit()">
            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                <option value="<?= $i ?>"<?= $i === $current ? ' selected' : '' ?>><?= $i ?> ページ目</option>
            <?php endfor; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">移動</button></noscript>
    </form>
</nav>
