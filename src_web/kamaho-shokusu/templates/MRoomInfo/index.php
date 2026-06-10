<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MRoomInfo> $mRoomInfo
 */

$this->assign('title', __('部屋情報一覧'));
$this->Html->css('pages/m_room_info_index.css', ['block' => true]);

// 管理者権限の確認
$isAdmin = in_array((int)$user->get('i_admin'), [1, 3]);
?>
<div class="mRoomInfo index content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><?= __('部屋情報一覧') ?></h3>
        <?php if ($isAdmin): ?>
        <?= $this->Html->link(__('+ 新しい部屋を追加'), ['action' => 'add'], ['class' => 'btn btn-success']) ?>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead class="table-hover">
            <tr>
                <th class="d-none d-md-table-cell" style="width:5%;"><?= $this->Paginator->sort('i_id_room', 'No.') ?></th>
                <th><?= $this->Paginator->sort('c_room_name', '部屋名') ?></th>
                <th class="d-none d-md-table-cell" style="width:10%;"><?= $this->Paginator->sort('i_disp_no', '表示順') ?></th>
                <th class="text-center" style="width:20%;"><?= __('操作') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mRoomInfo as $room): ?>
                <tr>
                    <td class="d-none d-md-table-cell text-muted small"><?= $this->Number->format($room->i_id_room) ?></td>
                    <td><?= h($room->c_room_name) ?></td>
                    <td class="d-none d-md-table-cell text-center"><?= $this->Number->format($room->i_disp_no) ?></td>
                    <td class="text-center">
                        <?= $this->Html->link(__('詳細'), ['action' => 'view', $room->i_id_room], ['class' => 'btn btn-info btn-sm']) ?>
                        <?php if ($isAdmin): ?>
                            <?= $this->Html->link(__('編集'), ['action' => 'edit', $room->i_id_room], ['class' => 'btn btn-warning btn-sm ms-1']) ?>
                            <?= $this->Form->postLink(__('🗑 削除'), ['action' => 'delete', $room->i_id_room], [
                                'confirm' => __('「{0}」を削除してもよろしいですか？', $room->c_room_name),
                                'class'   => 'btn btn-danger btn-sm ms-3',
                            ]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination justify-content-center custom-pagination">
            <?= $this->Paginator->first('<< 最初', ['class' => 'page-item', 'linkClass' => 'page-link']) ?>
            <?= $this->Paginator->prev('< 前',    ['class' => 'page-item', 'linkClass' => 'page-link']) ?>
            <?= $this->Paginator->numbers(['class' => 'page-item', 'linkClass' => 'page-link']) ?>
            <?= $this->Paginator->next('次 >',    ['class' => 'page-item', 'linkClass' => 'page-link']) ?>
            <?= $this->Paginator->last('最後 >>', ['class' => 'page-item', 'linkClass' => 'page-link']) ?>
        </ul>
        <p class="text-muted text-center">
            <?= $this->Paginator->counter('ページ {{page}}/{{pages}} (全{{count}}件中 {{current}}件を表示)') ?>
        </p>
    </div>
</div>