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
                    echo $this->Form->control('c_login_account',['label'=>'ログインID']);
                    echo $this->Form->control('c__user_name', ['label'=>'ユーザ名']);
                ?>
            </fieldset>
            <?= $this->Form->button(__('Submit')) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
