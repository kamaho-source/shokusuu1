<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('操作') ?></h4>
            <?= $this->Html->link(__('ユーザー情報一覧'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4><?= __('Mユーザー情報の編集') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mUserInfo) ?>
                <fieldset>
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label' => 'ログインID',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                    <div class="mb-3">
                        <?= $this->Form->control('c__user_name', [
                            'label' => 'ユーザ名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
