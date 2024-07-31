<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */
?>
<div class="row">
    <aside class="column">
        <div class="side-nav">
            <h4 class="heading"><?= __('Actions') ?></h4>
            <?= $this->Form->postLink(
                __('Delete'),
                ['action' => 'delete', $mUserInfo->i_id_user],
                ['confirm' => __('Are you sure you want to delete # {0}?', $mUserInfo->i_id_user), 'class' => 'side-nav-item']
            ) ?>
            <?= $this->Html->link(__('List M User Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="mUserInfo form content">
            <?= $this->Form->create($mUserInfo) ?>
            <fieldset>
                <legend><?= __('Edit M User Info') ?></legend>
                <?php
                    echo $this->Form->control('c_login_account');
                    echo $this->Form->control('c_login_passwd');
                    echo $this->Form->control('c__user_name');
                    echo $this->Form->control('i_admin');
                    echo $this->Form->control('i_disp__no');
                    echo $this->Form->control('i_enable');
                    echo $this->Form->control('i_del_flag');
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
