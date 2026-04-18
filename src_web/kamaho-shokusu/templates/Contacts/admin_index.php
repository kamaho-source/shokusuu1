<?php
/** @var \App\View\AppView $this */
$this->assign('title', 'お問い合わせ一覧（管理者）');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="fw-semibold mb-0">
        <i class="bi bi-inbox me-2 text-primary"></i>お問い合わせ一覧
    </h5>
</div>

<?php if (empty($contacts)): ?>
    <div class="alert alert-secondary">お問い合わせはまだありません。</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 140px;">受信日時</th>
                    <th style="width: 130px;">カテゴリ</th>
                    <th style="width: 110px;">お名前</th>
                    <th>メール</th>
                    <th>内容</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td class="text-muted small"><?= h($contact->created->format('Y-m-d H:i')) ?></td>
                        <td>
                            <span class="badge bg-secondary"><?= h($contact->category) ?></span>
                        </td>
                        <td><?= h($contact->name) ?></td>
                        <td>
                            <a href="mailto:<?= h($contact->email) ?>" class="text-decoration-none small">
                                <?= h($contact->email) ?>
                            </a>
                        </td>
                        <td>
                            <details>
                                <summary class="text-muted small" style="cursor: pointer;">
                                    <?= h(mb_substr($contact->body, 0, 40)) ?><?= mb_strlen($contact->body) > 40 ? '…' : '' ?>
                                </summary>
                                <div class="mt-2 small" style="white-space: pre-wrap;"><?= h($contact->body) ?></div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ページネーション -->
    <?php if (count($contacts) >= 30): ?>
        <div class="d-flex gap-2 mt-2">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn btn-outline-secondary btn-sm">&laquo; 前のページ</a>
            <?php endif; ?>
            <a href="?page=<?= $page + 1 ?>" class="btn btn-outline-secondary btn-sm">次のページ &raquo;</a>
        </div>
    <?php endif; ?>
<?php endif; ?>
