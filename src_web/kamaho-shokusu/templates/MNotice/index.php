<?php
/**
 * お知らせ一覧（管理者用）
 *
 * @var \App\View\AppView $this
 * @var list<\App\Domain\Entity\Notice> $notices
 */

$this->assign('title', 'お知らせ管理');
$importanceLabels = [
    0 => ['label' => '通常',  'class' => 'text-bg-secondary'],
    1 => ['label' => '重要',  'class' => 'text-bg-danger'],
];
$typeLabels = [
    0 => ['label' => 'お知らせ',       'class' => 'text-bg-secondary'],
    1 => ['label' => '🚀 リリースノート', 'class' => 'text-bg-success'],
];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h3 class="mb-0">
            <i class="bi bi-megaphone"></i> お知らせ管理
        </h3>
        <?= $this->Html->link(
            '<i class="bi bi-plus-circle"></i> 新規作成',
            ['action' => 'add'],
            ['class' => 'btn btn-success', 'escape' => false]
        ) ?>
    </div>

    <?= $this->Flash->render() ?>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-nowrap">種別</th>
                    <th class="text-nowrap">重要度</th>
                    <th>タイトル</th>
                    <th class="text-nowrap">掲示期間</th>
                    <th class="text-nowrap">登録者</th>
                    <th class="text-nowrap">登録日時</th>
                    <th class="text-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowCount = 0; ?>
            <?php foreach ($notices as $notice): ?>
                <?php
                    $rowCount++;
                    $imp  = $importanceLabels[$notice->importance] ?? $importanceLabels[0];
                    $type = $typeLabels[$notice->type] ?? $typeLabels[0];
                    // 日付は Y/m/d で出す（以前は地域設定に依存する「11/5/26」の形になっていた）
                    $startStr = $notice->startDate !== null ? str_replace('-', '/', $notice->startDate) : null;
                    $endStr   = $notice->endDate !== null ? str_replace('-', '/', $notice->endDate) : null;
                    if ($startStr && $endStr) {
                        $period = h($startStr) . ' 〜 ' . h($endStr);
                    } elseif ($startStr) {
                        $period = h($startStr) . ' 〜 無期限';
                    } elseif ($endStr) {
                        $period = '即時 〜 ' . h($endStr);
                    } else {
                        $period = '無期限';
                    }
                    $dtCreate = $notice->createdAt
                        ? (new \DateTime($notice->createdAt))->format('Y/m/d H:i')
                        : '-';
                ?>
                <tr>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $type['class'] ?>"><?= $type['label'] ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $imp['class'] ?>"><?= $imp['label'] ?></span>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= h($notice->title) ?></div>
                        <?php if ($notice->body): ?>
                            <div class="text-muted small text-truncate" style="max-width:300px;">
                                <?= h(mb_substr($notice->body, 0, 60)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap small"><?= $period ?></td>
                    <td class="text-muted small"><?= h($notice->createdBy ?? '-') ?></td>
                    <td class="text-muted small text-nowrap"><?= h($dtCreate) ?></td>
                    <td class="text-nowrap">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i>',
                            ['action' => 'edit', $notice->id],
                            ['class' => 'btn btn-sm btn-outline-primary me-1', 'escape' => false, 'title' => '編集']
                        ) ?>

                        <?= $this->Form->create(null, [
                            'url'    => ['action' => 'delete', $notice->id],
                            'method' => 'post',
                            'id'     => 'delete-form-' . $notice->id,
                            'style'  => 'display:none',
                        ]) ?>
                        <?= $this->Form->end() ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger notice-delete-btn"
                                data-form-id="delete-form-<?= $notice->id ?>"
                                data-title="<?= h($notice->title) ?>"
                                title="削除">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                        登録されているお知らせはありません。
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.notice-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const title = btn.dataset.title;
            const ok = await window.ConfirmPopup?.show(
                `「${title}」を削除しますか？`,
                { okLabel: '削除する', okColor: 'danger', cancelLabel: '戻る' }
            ) ?? confirm(`「${title}」を削除しますか？`);
            if (ok) {
                document.getElementById(btn.dataset.formId)?.submit();
            }
        });
    });
});
</script>
