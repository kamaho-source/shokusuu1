<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $logs
 */
$this->assign('title', '施設別設定 変更履歴');

$boolLabel = fn($v) => $v ? '<span class="badge bg-success">ON</span>' : '<span class="badge bg-secondary">OFF</span>';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0"><i class="bi bi-clock-history me-2"></i>施設別設定 変更履歴</h3>
    <?= $this->Html->link(
        '<i class="bi bi-pencil-square me-1"></i>設定を編集',
        ['action' => 'edit'],
        ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
    ) ?>
</div>

<?= $this->Flash->render() ?>

<!-- 件数 & ページネーション -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted small mb-0">
        <?= $this->Paginator->counter('全 {{count}} 件中 {{start}}〜{{end}} 件を表示') ?>
    </p>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?= $this->Paginator->prev('«', ['tag' => 'li', 'class' => 'page-item', 'linkAttributes' => ['class' => 'page-link']], null, ['tag' => 'li', 'class' => 'page-item disabled', 'linkAttributes' => ['class' => 'page-link']]) ?>
            <?= $this->Paginator->numbers(['tag' => 'li', 'class' => 'page-item', 'currentTag' => 'li', 'currentClass' => 'page-item active', 'linkAttributes' => ['class' => 'page-link'], 'escape' => false]) ?>
            <?= $this->Paginator->next('»', ['tag' => 'li', 'class' => 'page-item', 'linkAttributes' => ['class' => 'page-link']], null, ['tag' => 'li', 'class' => 'page-item disabled', 'linkAttributes' => ['class' => 'page-link']]) ?>
        </ul>
    </nav>
</div>

<div class="table-responsive shadow-sm rounded border">
    <table class="table table-hover table-sm mb-0" style="font-size:0.85rem;">
        <thead class="table-dark">
            <tr>
                <th style="width:155px;">操作日時</th>
                <th style="width:130px;">操作者</th>
                <th>変更内容</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <?php $detail = $log->c_detail ? json_decode($log->c_detail, true) : null; ?>
            <?php $changes = $detail['changes'] ?? []; ?>
            <tr>
                <td class="font-monospace text-nowrap align-top pt-3">
                    <?= h($log->dt_create) ?>
                </td>
                <td class="align-top pt-3">
                    <div><?= h($log->c_actor_user_name) ?></div>
                    <?php if ($log->c_actor_login_id): ?>
                        <div class="text-muted small"><?= h($log->c_actor_login_id) ?></div>
                    <?php endif; ?>
                </td>
                <td class="align-top">
                    <?php if (empty($changes)): ?>
                        <span class="text-muted small">変更なし（値は同じ）</span>
                    <?php else: ?>
                        <table class="table table-borderless table-sm mb-0" style="font-size:0.83rem;">
                            <thead>
                                <tr class="text-muted border-bottom">
                                    <th style="width:180px;" class="ps-0">項目</th>
                                    <th style="width:160px;">変更前</th>
                                    <th style="width:8px;" class="text-center">→</th>
                                    <th>変更後</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($changes as $change): ?>
                                <tr>
                                    <td class="ps-0 fw-semibold"><?= h($change['field']) ?></td>
                                    <td>
                                        <?php if (is_bool($change['before'])): ?>
                                            <?= $boolLabel($change['before']) ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?= h($change['before'] ?? '（未設定）') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted">→</td>
                                    <td>
                                        <?php if (is_bool($change['after'])): ?>
                                            <?= $boolLabel($change['after']) ?>
                                        <?php else: ?>
                                            <strong><?= h($change['after'] ?? '（未設定）') ?></strong>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (iterator_count($logs) === 0): ?>
            <tr>
                <td colspan="3" class="text-center text-muted py-5">
                    <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                    変更履歴はまだありません
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 下部ページネーション -->
<nav class="mt-3">
    <ul class="pagination justify-content-center mb-0">
        <?= $this->Paginator->prev('«', ['tag' => 'li', 'class' => 'page-item', 'linkAttributes' => ['class' => 'page-link']], null, ['tag' => 'li', 'class' => 'page-item disabled', 'linkAttributes' => ['class' => 'page-link']]) ?>
        <?= $this->Paginator->numbers(['tag' => 'li', 'class' => 'page-item', 'currentTag' => 'li', 'currentClass' => 'page-item active', 'linkAttributes' => ['class' => 'page-link'], 'escape' => false]) ?>
        <?= $this->Paginator->next('»', ['tag' => 'li', 'class' => 'page-item', 'linkAttributes' => ['class' => 'page-link']], null, ['tag' => 'li', 'class' => 'page-item disabled', 'linkAttributes' => ['class' => 'page-link']]) ?>
    </ul>
</nav>
