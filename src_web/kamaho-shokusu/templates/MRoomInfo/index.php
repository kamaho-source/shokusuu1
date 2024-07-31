<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MRoomInfo> $mRoomInfo
 */
?>
<div class="mRoomInfo index content">
    <?= $this->Html->link(__('New M Room Info'), ['action' => 'add'], ['class' => 'button float-right']) ?>
    <h3><?= __('M Room Info') ?></h3>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('i_id_room') ?></th>
                    <th><?= $this->Paginator->sort('c_room_name') ?></th>
                    <th><?= $this->Paginator->sort('i_disp_no') ?></th>
                    <th><?= $this->Paginator->sort('i_enable') ?></th>
                    <th><?= $this->Paginator->sort('i_del_flg') ?></th>
                    <th><?= $this->Paginator->sort('dt_create') ?></th>
                    <th><?= $this->Paginator->sort('c_create_user') ?></th>
                    <th><?= $this->Paginator->sort('dt_update') ?></th>
                    <th><?= $this->Paginator->sort('c_update_user') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mRoomInfo as $mRoomInfo): ?>
                <tr>
                    <td><?= $this->Number->format($mRoomInfo->i_id_room) ?></td>
                    <td><?= h($mRoomInfo->c_room_name) ?></td>
                    <td><?= $mRoomInfo->i_disp_no === null ? '' : $this->Number->format($mRoomInfo->i_disp_no) ?></td>
                    <td><?= $mRoomInfo->i_enable === null ? '' : $this->Number->format($mRoomInfo->i_enable) ?></td>
                    <td><?= $mRoomInfo->i_del_flg === null ? '' : $this->Number->format($mRoomInfo->i_del_flg) ?></td>
                    <td><?= h($mRoomInfo->dt_create) ?></td>
                    <td><?= h($mRoomInfo->c_create_user) ?></td>
                    <td><?= h($mRoomInfo->dt_update) ?></td>
                    <td><?= h($mRoomInfo->c_update_user) ?></td>
                    <td class="actions">
                        <?= $this->Html->link(__('View'), ['action' => 'view', $mRoomInfo->i_id_room]) ?>
                        <?= $this->Html->link(__('Edit'), ['action' => 'edit', $mRoomInfo->i_id_room]) ?>
                        <?= $this->Form->postLink(__('Delete'), ['action' => 'delete', $mRoomInfo->i_id_room], ['confirm' => __('Are you sure you want to delete # {0}?', $mRoomInfo->i_id_room)]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
