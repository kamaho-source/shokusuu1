<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 */
?>
<div class="row">
    <aside class="column">
        <div class="side-nav">
            <h4 class="heading"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('Edit M Room Info'), ['action' => 'edit', $mRoomInfo->i_id_room], ['class' => 'side-nav-item']) ?>
            <?= $this->Form->postLink(__('Delete M Room Info'), ['action' => 'delete', $mRoomInfo->i_id_room], ['confirm' => __('Are you sure you want to delete # {0}?', $mRoomInfo->i_id_room), 'class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('List M Room Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('New M Room Info'), ['action' => 'add'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="mRoomInfo view content">
            <h3><?= h($mRoomInfo->i_id_room) ?></h3>
            <table>
                <tr>
                    <th><?= __('C Room Name') ?></th>
                    <td><?= h($mRoomInfo->c_room_name) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Create User') ?></th>
                    <td><?= h($mRoomInfo->c_create_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Update User') ?></th>
                    <td><?= h($mRoomInfo->c_update_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Id Room') ?></th>
                    <td><?= $this->Number->format($mRoomInfo->i_id_room) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Disp No') ?></th>
                    <td><?= $mRoomInfo->i_disp_no === null ? '' : $this->Number->format($mRoomInfo->i_disp_no) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Enable') ?></th>
                    <td><?= $mRoomInfo->i_enable === null ? '' : $this->Number->format($mRoomInfo->i_enable) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Del Flg') ?></th>
                    <td><?= $mRoomInfo->i_del_flg === null ? '' : $this->Number->format($mRoomInfo->i_del_flg) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Create') ?></th>
                    <td><?= h($mRoomInfo->dt_create) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Update') ?></th>
                    <td><?= h($mRoomInfo->dt_update) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
