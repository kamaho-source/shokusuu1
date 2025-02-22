<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MRoomInfo> $mRoomInfo
 */

// 管理者権限の確認
$isAdmin = $user->get('i_admin') === 1;
?>
<div class="mRoomInfo index content">
    <?= $this->Html->link(__('新しい部屋情報を追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
    <h3><?= __('部屋情報一覧') ?></h3>
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead class="thead-dark">
            <tr>
                <th><?= $this->Paginator->sort('i_id_room', '部屋ID') ?></th>
                <th><?= $this->Paginator->sort('c_room_name', '部屋名') ?></th>
                <th><?= $this->Paginator->sort('i_disp_no','表示順')?></th>
                <th class="text-center"><?= __('アクション') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mRoomInfo as $room): ?>
                <tr>
                    <td><?= $this->Number->format($room->i_id_room) ?></td>
                    <td><?= h($room->c_room_name) ?></td>
                    <td><?= $this->Number->format($room->i_disp_no) ?></td>
                    <td class="text-center">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $room->i_id_room], ['class' => 'btn btn-info btn-sm']) ?>
                        <?php if ($isAdmin): // 管理者のみが編集と削除を行える ?>
                            <?= $this->Html->link(__('編集'), ['action' => 'edit', $room->i_id_room], ['class' => 'btn btn-warning btn-sm']) ?>
                            <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $room->i_id_room], ['confirm' => __('本当に削除してもよろしいですか？', $room->i_id_room), 'class' => 'btn btn-danger btn-sm']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination" style="">
            <?= $this->Paginator->first('<< ' . __('最初')) ?>&nbsp;
            <?= $this->Paginator->prev('< ' . __('前へ')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('次へ') . ' >') ?>
            <?= $this->Paginator->last(__('最後') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('ページ {{page}} / {{pages}}, 合計 {{count}} 件')) ?></p>
    </div>
</div>
