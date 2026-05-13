<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $schedules
 * @var string|null $statusFilter
 */

$this->assign('title', '部屋異動予約一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

$statusLabels = [
    0 => ['label' => '予約中',   'class' => 'badge-warning'],
    1 => ['label' => '適用済み', 'class' => 'badge-success'],
    2 => ['label' => 'キャンセル', 'class' => 'badge-secondary'],
];
?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
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
    <div class="mb-3">
        <?= $this->Html->link('すべて', ['action' => 'index'], [
            'class' => 'btn btn-sm ' . ($statusFilter === null ? 'btn-primary' : 'btn-outline-primary'),
        ]) ?>
        <?= $this->Html->link('予約中', ['action' => 'index', '?' => ['status' => '0']], [
            'class' => 'btn btn-sm ' . ($statusFilter === '0' ? 'btn-warning' : 'btn-outline-warning'),
        ]) ?>
        <?= $this->Html->link('適用済み', ['action' => 'index', '?' => ['status' => '1']], [
            'class' => 'btn btn-sm ' . ($statusFilter === '1' ? 'btn-success' : 'btn-outline-success'),
        ]) ?>
        <?= $this->Html->link('キャンセル', ['action' => 'index', '?' => ['status' => '2']], [
            'class' => 'btn btn-sm ' . ($statusFilter === '2' ? 'btn-secondary' : 'btn-outline-secondary'),
        ]) ?>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>ID</th>
                    <th>対象ユーザー</th>
                    <th>異動元部屋</th>
                    <th>異動先部屋</th>
                    <th>有効開始日</th>
                    <th>ステータス</th>
                    <th>登録者</th>
                    <th>登録日時</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $schedule): ?>
                <?php
                    $statusInfo = $statusLabels[(int)$schedule->i_status] ?? ['label' => '不明', 'class' => 'badge-dark'];
                ?>
                <tr>
                    <td><?= h($schedule->i_id) ?></td>
                    <td><?= h($schedule->m_user_info->c_user_name ?? '-') ?></td>
                    <td><?= $schedule->room_from ? h($schedule->room_from->c_room_name) : '<span class="text-muted">（新規配属）</span>' ?></td>
                    <td><?= h($schedule->room_to->c_room_name ?? '-') ?></td>
                    <td><?= h($schedule->d_effective_date) ?></td>
                    <td>
                        <span class="badge <?= $statusInfo['class'] ?>">
                            <?= h($statusInfo['label']) ?>
                        </span>
                    </td>
                    <td><?= h($schedule->c_create_user) ?></td>
                    <td><?= h($schedule->dt_create) ?></td>
                    <td>
                        <?php if ((int)$schedule->i_status === 0): ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-x-circle"></i> キャンセル',
                                ['action' => 'cancel', $schedule->i_id],
                                [
                                    'class'   => 'btn btn-sm btn-danger',
                                    'escape'  => false,
                                    'confirm' => sprintf(
                                        'ID=%d の異動予約をキャンセルしますか？',
                                        $schedule->i_id
                                    ),
                                ]
                            ) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty(iterator_to_array($schedules))): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">登録されている異動予約はありません。</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
