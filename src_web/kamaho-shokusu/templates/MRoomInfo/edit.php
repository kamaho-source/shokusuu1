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
            <?= $this->Form->postLink(
                __('Delete'),
                ['action' => 'delete', $mRoomInfo->i_id_room],
                ['confirm' => __('Are you sure you want to delete # {0}?', $mRoomInfo->i_id_room), 'class' => 'side-nav-item']
            ) ?>
            <?= $this->Html->link(__('List M Room Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="mRoomInfo form content">
            <?= $this->Form->create($mRoomInfo) ?>
            <fieldset>
                <legend><?= __('Edit M Room Info') ?></legend>
                <?php
                    echo $this->Form->control('c_room_name');
                    echo $this->Form->control('i_disp_no');
                    echo $this->Form->control('i_enable');
                    echo $this->Form->control('i_del_flg');
                    echo $this->Form->control('dt_create', ['empty' => true]);
                    echo $this->Form->control('c_create_user');
                    echo $this->Form->control('dt_update', ['empty' => true]);
                    echo $this->Form->control('c_update_user');
                ?>
            </fieldset>
            <?= $this->Form->button(__('Submit')) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
