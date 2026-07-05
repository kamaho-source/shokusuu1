<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TApprovalLog[]|\Cake\Collection\CollectionInterface $logs
 */
$this->assign('title', '承認履歴');

$statusLabels = [
    1 => '<span class="badge bg-primary">ブロック長承認</span>',
    2 => '<span class="badge bg-success">管理者承認</span>',
    3 => '<span class="badge bg-danger">差し戻し</span>',
];

$mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁'];
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">承認履歴</h1>
        <a href="<?= $this->Url->build(['action' => 'adminIndex']) ?>" class="btn btn-outline-secondary btn-sm">承認管理へ戻る</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>承認日時</th>
                            <th>ステータス</th>
                            <th>承認者</th>
                            <th>対象者</th>
                            <th>対象日</th>
                            <th>食種</th>
                            <th>理由/備考</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs->isEmpty()): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">承認履歴はありません。</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="text-nowrap"><?= h($log->dt_create->format('Y/m/d H:i')) ?></td>
                                    <td><?= $statusLabels[$log->i_approval_status] ?? h($log->i_approval_status) ?></td>
                                    <td class="text-nowrap"><?= h($log->approver->c_user_name ?? 'ID:'.$log->i_approver_id) ?></td>
                                    <td class="text-nowrap">
                                        <?= h($log->m_user_info->c_user_name ?? 'ID:'.$log->i_id_user) ?>
                                        <br><small class="text-muted"><?= h($log->m_room_info->c_room_name ?? '部屋ID:'.$log->i_id_room) ?></small>
                                    </td>
                                    <td class="text-nowrap"><?= h($log->d_reservation_date->format('Y/m/d')) ?></td>
                                    <td><?= $mealTypes[$log->i_reservation_type] ?? h($log->i_reservation_type) ?></td>
                                    <td>
                                        <?php if ($log->i_approval_status === 3 && $log->c_reject_reason): ?>
                                            <span class="text-danger small"><?= h($log->c_reject_reason) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!$logs->isEmpty()): ?>
            <div class="card-footer bg-white py-3">
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <?= $this->Paginator->prev('« 前') ?>
                        <?= $this->Paginator->numbers() ?>
                        <?= $this->Paginator->next('次 »') ?>
                    </ul>
                    <p class="text-center small text-muted mt-2 mb-0">
                        <?= $this->Paginator->counter('{{page}} / {{pages}} ページ（全 {{count}} 件）') ?>
                    </p>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.pagination a, .pagination span {
    padding: 0.5rem 0.75rem;
    border: 1px solid #dee2e6;
    margin-left: -1px;
    text-decoration: none;
    color: #0d6efd;
}
.pagination .active span {
    background-color: #0d6efd;
    color: white;
    border-color: #0d6efd;
}
.pagination .disabled span {
    color: #6c757d;
}
</style>