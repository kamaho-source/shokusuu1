
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
            <?= $this->Html->link(__('List M User Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="mUserInfo form content">
            <?= $this->Form->create($mUserInfo, ['class' => 'form-horizontal']) ?>
            <fieldset>
                <legend><?= __('Add M User Info') ?></legend>
                <div class="form-group">
                    <?= $this->Form->control('c_login_account', ['label' => 'ログインアカウント', 'class' => 'form-control']); ?>
                </div>
                <div class="form-group">
                    <?= $this->Form->control('c_login_passwd', ['label' => 'ログインパスワード','type'=>'password', 'class' => 'form-control']); ?>
                </div>
                <div class="form-group">
                    <?= $this->Form->control('c__user_name', ['label' => 'ユーザー名', 'class' => 'form-control']); ?>
                </div>
            </fieldset>
            <div class="form-group">
                <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
