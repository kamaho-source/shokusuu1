<?php
/**
 * 自分のパスワードを変更する
 *
 * @var \App\View\AppView $this
 */
$this->assign('title', 'パスワードの変更');
$this->Html->css('pages/user_screens.css', ['block' => 'css']);
?>
<div class="u-shell u-shell--narrow">

    <div class="u-head">
        <div>
            <div class="u-eyebrow">自分の設定</div>
            <h1 class="u-title">パスワードの変更</h1>
            <p class="u-lead">新しいパスワードを2回入力してください。4文字以上で設定できます。</p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'general_password_reset']]) ?>
    <section class="u-card">
        <div class="u-field">
            <?= $this->Form->control('new_password', [
                'type' => 'password',
                'label' => '新しいパスワード',
                'required' => true,
                'class' => 'u-input',
                'minlength' => 4,
                'autocomplete' => 'new-password',
            ]) ?>
        </div>
        <div class="u-field">
            <?= $this->Form->control('confirm_password', [
                'type' => 'password',
                'label' => '新しいパスワード（確認のためもう一度）',
                'required' => true,
                'class' => 'u-input',
                'minlength' => 4,
                'autocomplete' => 'new-password',
            ]) ?>
            <span class="u-field__help">打ち間違いを防ぐため、同じものを入力してください。</span>
        </div>
    </section>

    <div class="u-actions">
        <?= $this->Form->button('変更する', ['class' => 'u-btn u-btn--primary']) ?>
        <?= $this->Html->link('やめる', ['controller' => 'TReservationInfo', 'action' => 'index'], ['class' => 'u-btn u-btn--quiet']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
