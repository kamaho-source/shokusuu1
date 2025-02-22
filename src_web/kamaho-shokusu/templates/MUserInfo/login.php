<!-- in templates/Users/login.php -->
<?php
$this->assign('title', 'ログイン');
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-6 offset-md-3">
           <?= $this->Html->image('logo_top.svg', ['class' => 'img-fluid']) ?>
            <h1 class="text-center">鎌倉児童ホーム食数管理システム</h1>
            <h2 class="text-center">ログイン</h2>
            <?= $this->Flash->render() ?>
            <?= $this->Form->create() ?>

            <div class="form-group">
                <?= $this->Form->control('c_login_account', [
                    'type' => 'text',
                    'required' => true,
                    'class' => 'form-control',
                    'label' =>'ログインID',
                ]) ?>
            </div>

            <div class="form-group">
                <?= $this->Form->control('c_login_passwd', [
                    'type' => 'password',
                    'required' => true,
                    'class' => 'form-control',
                    'label' => 'パスワード',
                ]) ?>
            </div>

            <div class="d-flex justify-content-center mt-3">
                <?= $this->Form->submit('ログイン', ['class' => 'btn btn-primary']) ?>
            </div>

            <div class="d-flex justify-content-center mt-3">
                <?= $this->Html->link('新規登録', ['action' => 'add'], ['class' => 'btn btn-primary']) ?>
            </div>




            <?= $this->Form->end() ?>

        </div>
    </div>
</div>
