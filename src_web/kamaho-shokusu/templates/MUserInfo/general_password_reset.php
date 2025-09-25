<?php
$this->assign('title', 'パスワード変更');
?>
<div class="container my-4">
    <?= $this->Flash->render() ?>
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <span class="fw-bold">パスワード変更</span>
                </div>
                <div class="card-body">
                    <?= $this->Form->create(null, ['url' => ['action' => 'general_password_reset']]) ?>
                    <div class="mb-3">
                        <?= $this->Form->label('new_password', '新しいパスワード', ['class' => 'form-label']) ?>
                        <?= $this->Form->control('new_password', [
                            'type' => 'password',
                            'label' => false,
                            'required' => true,
                            'class' => 'form-control',
                            'minlength' => 4, // ★ 4文字以上
                            'autocomplete' => 'new-password'
                        ]) ?>
                    </div>
                    <div class="mb-4">
                        <?= $this->Form->label('confirm_password', '新しいパスワード（確認）', ['class' => 'form-label']) ?>
                        <?= $this->Form->control('confirm_password', [
                            'type' => 'password',
                            'label' => false,
                            'required' => true,
                            'class' => 'form-control',
                            'minlength' => 4,
                            'autocomplete' => 'new-password'
                        ]) ?>
                    </div>
                    <div class="d-grid gap-2 d-sm-flex">
                        <?= $this->Form->button('変更する',['class' => 'btn btn-primary px-4']) ?>
                        <?= $this->Html->link('キャンセル', ['controller'=>'TReservationInfo','action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div>
