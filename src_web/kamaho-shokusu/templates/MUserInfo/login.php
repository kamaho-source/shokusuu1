<!-- in templates/Users/login.php -->
<?php


$this->start('styles'); // Add extra styles to the layout
?>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
<?php
$this->end();

$this->assign('title', 'Login'); // Set the page title
?>
<div class="container mt-5">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <h1 class="text-center">Login</h1>
            <?= $this->Form->create() ?>
            <div class="form-group">
                <?= $this->Form->control('ログインID', [
                    'required' => true,
                    'class' => 'form-control',
                    'placeholder' => 'ログインID',
                ]) ?>
            </div>
            <div class="form-group">
                <?= $this->Form->control('パスワード', [
                    'required' => true,
                    'class' => 'form-control',
                    'placeholder' => 'パスワード',
                    'type' => 'password',
                ]) ?>
            </div>
            <div class="d-flex justify-content-center mt-3">
                <?= $this->Form->submit('Login', ['class' => 'btn btn-primary']) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
