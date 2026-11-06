<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Application\Query\Dto\RoomTransferView> $schedules
 * @var string|null $statusFilter
 */

$this->assign('title', '部屋異動予約一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

$statusLabels = [
    0 => ['label' => '予約中',    'class' => 'text-bg-warning',  'icon' => 'bi-clock'],
    1 => ['label' => '適用済み',  'class' => 'text-bg-success',  'icon' => 'bi-check-circle-fill'],
    2 => ['label' => 'キャンセル','class' => 'text-bg-secondary', 'icon' => 'bi-x-circle-fill'],
    3 => ['label' => '適用失敗',  'class' => 'text-bg-danger',    'icon' => 'bi-exclamation-triangle-fill'],
];

$today = new \DateTime(\Cake\I18n\Date::today('Asia/Tokyo')->format('Y-m-d'));
$dayNames = ['日', '月', '火', '水', '木', '金', '土'];
?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">


<div class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h3 class="mb-0">
            <i class="bi bi-arrow-left-right"></i> 部屋異動予約一覧
        </h3>
        <?= $this->Html->link(
            '<i class="bi bi-plus-circle"></i> 新規登録',
            ['action' => 'add'],
            ['class' => 'btn btn-success', 'escape' => false]
        ) ?>
    </div>

    <!-- ステータスフィルター -->
    <div class="mb-3 d-flex gap-2 flex-wrap">
        <?= $this->Html->link('すべて', ['action' => 'index'], [
            'class' => 'btn btn-sm ' . ($statusFilter === null ? 'btn-primary' : 'btn-outline-primary'),
        ]) ?>
        <?= $this->Html->link(
            '<i class="bi bi-clock"></i> 予約中',
            ['action' => 'index', '?' => ['status' => '0']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '0' ? 'btn-warning' : 'btn-outline-warning'), 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-check-circle-fill"></i> 適用済み',
            ['action' => 'index', '?' => ['status' => '1']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '1' ? 'btn-success' : 'btn-outline-success'), 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-circle-fill"></i> キャンセル',
            ['action' => 'index', '?' => ['status' => '2']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '2' ? 'btn-secondary' : 'btn-outline-secondary'), 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-exclamation-triangle-fill"></i> 適用失敗',
            ['action' => 'index', '?' => ['status' => '3']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '3' ? 'btn-danger' : 'btn-outline-danger'), 'escape' => false]
        ) ?>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-nowrap">対象ユーザー</th>
                    <th class="text-nowrap">異動元部屋</th>
                    <th class="text-nowrap">異動先部屋</th>
                    <th class="text-nowrap">有効開始日</th>
                    <th class="text-nowrap">ステータス</th>
                    <th class="text-nowrap">登録者</th>
                    <th class="text-nowrap">登録日時</th>
                    <th class="text-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowCount = 0; ?>
            <?php foreach ($schedules as $schedule): ?>
                <?php
                    $rowCount++;
                    $statusInfo = $statusLabels[$schedule->status] ?? ['label' => '不明', 'class' => 'text-bg-dark', 'icon' => 'bi-question'];

                    $effectiveDateObj = new \DateTime($schedule->effectiveDate);
                    $diff = (int)$today->diff($effectiveDateObj)->days * ($effectiveDateObj >= $today ? 1 : -1);
                    $dow  = $dayNames[(int)$effectiveDateObj->format('w')];
                    $dateFormatted = $effectiveDateObj->format('Y/m/d') . "（{$dow}）";

                    if ($diff < 0) {
                        $dateBadge = '<span class="text-muted">' . h($dateFormatted) . '</span>';
                    } elseif ($diff === 0) {
                        $dateBadge = '<span class="badge text-bg-danger">本日 ' . h($dateFormatted) . '</span>';
                    } elseif ($diff <= 7) {
                        $dateBadge = '<span class="badge text-bg-warning text-dark">まもなく ' . h($dateFormatted) . '</span>';
                    } else {
                        $dateBadge = '<span class="fw-semibold">' . h($dateFormatted) . '</span>';
                    }

                    $dtCreate = $schedule->createdAt
                        ? (new \DateTime($schedule->createdAt))->format('Y/m/d H:i')
                        : '-';

                    $cancelMessage = sprintf(
                        '%s の異動予約（→ %s）をキャンセルしますか？',
                        $schedule->userName ?? '',
                        $schedule->roomToName ?? ''
                    );
                    $cancelUrl = $this->Url->build(['action' => 'cancel', $schedule->id]);
                ?>
                <tr>
                    <td><?= h($schedule->userName ?? '-') ?></td>
                    <td>
                        <?php if ($schedule->roomFromName !== null): ?>
                            <?= h($schedule->roomFromName) ?>
                        <?php else: ?>
                            <span class="text-muted fst-italic">新規配属</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= h($schedule->roomToName ?? '-') ?></td>
                    <td class="text-nowrap"><?= $dateBadge ?></td>
                    <td>
                        <span class="badge rounded-pill <?= $statusInfo['class'] ?>">
                            <i class="bi <?= $statusInfo['icon'] ?> me-1"></i><?= h($statusInfo['label']) ?>
                        </span>
                        <?php if ($schedule->status === 3 && $schedule->failReason !== null): ?>
                            <div class="small text-danger mt-1"><?= h($schedule->failReason) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($schedule->createdBy) ?></td>
                    <td class="text-muted small text-nowrap"><?= h($dtCreate) ?></td>
                    <td>
                        <?php if ($schedule->status === 3): ?>
                            <!-- やり直し用フォーム（予約中に戻し、次のバッチで適用する。#945） -->
                            <?= $this->Form->create(null, [
                                'url'    => ['action' => 'retry', $schedule->id],
                                'method' => 'post',
                                'id'     => 'retry-form-' . $schedule->id,
                                'style'  => 'display:none',
                            ]) ?>
                            <?= $this->Form->end() ?>

                            <button type="button"
                                    class="btn btn-sm btn-outline-primary rts-retry-btn mb-1"
                                    data-form-id="retry-form-<?= $schedule->id ?>"
                                    data-message="<?= h(sprintf(
                                        '%s の異動予約（→ %s）を予約中に戻し、次のバッチで適用し直しますか？%s失敗の理由を直してから実行してください。',
                                        $schedule->userName ?? '',
                                        $schedule->roomToName ?? '',
                                        $schedule->effectiveDate < $today->format('Y-m-d') ? '有効開始日は過ぎているので、今日にして適用し直します。' : ''
                                    )) ?>">
                                <i class="bi bi-arrow-repeat"></i> やり直す
                            </button>
                        <?php endif; ?>
                        <?php if ($schedule->status === 0 || $schedule->status === 3): ?>
                            <!-- キャンセル用フォーム（非表示） -->
                            <?= $this->Form->create(null, [
                                'url'    => ['action' => 'cancel', $schedule->id],
                                'method' => 'post',
                                'id'     => 'cancel-form-' . $schedule->id,
                                'style'  => 'display:none',
                            ]) ?>
                            <?= $this->Form->end() ?>

                            <button type="button"
                                    class="btn btn-sm btn-outline-danger rts-cancel-btn"
                                    data-form-id="cancel-form-<?= $schedule->id ?>"
                                    data-message="<?= h($cancelMessage) ?>">
                                <i class="bi bi-x-circle"></i> キャンセル
                            </button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                        登録されている異動予約はありません。
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.rts-cancel-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const ok = await window.ConfirmPopup.show(btn.dataset.message, {
                okLabel:  'キャンセルする',
                okColor:  'danger',
                cancelLabel: '戻る',
            });
            if (ok) {
                document.getElementById(btn.dataset.formId)?.submit();
            }
        });
    });
    document.querySelectorAll('.rts-retry-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const ok = await window.ConfirmPopup.show(btn.dataset.message, {
                okLabel:  'やり直す',
                okColor:  'primary',
                cancelLabel: '戻る',
            });
            if (ok) {
                document.getElementById(btn.dataset.formId)?.submit();
            }
        });
    });
});
</script>
