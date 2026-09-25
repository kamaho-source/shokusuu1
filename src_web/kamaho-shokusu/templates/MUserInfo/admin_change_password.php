<?php
/**
 * 管理者が他の人のパスワードを変更する
 *
 * 他人の資格情報を変えるため、誰のパスワードを変えるのかを
 * 画面上で必ず選ばせる（一覧から直接飛ばさない）。
 *
 * @var \App\View\AppView $this
 * @var array $users ユーザーID => 名前
 */
$this->assign('title', 'パスワードの再設定');
$this->Html->css('pages/user_screens.css', ['block' => 'css']);
?>
<div class="u-shell u-shell--narrow">

    <div class="u-head">
        <div>
            <div class="u-eyebrow">管理者の操作</div>
            <h1 class="u-title">パスワードの再設定</h1>
            <p class="u-lead">パスワードが分からなくなった人の代わりに、新しいパスワードを設定します。</p>
        </div>
        <a class="u-back" href="<?= $this->Url->build(['action' => 'index']) ?>">
            <span aria-hidden="true">←</span> ユーザー一覧へ戻る
        </a>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'adminChangePassword']]) ?>
    <section class="u-card">
        <div class="u-field">
            <?= $this->Form->control('user_id', [
                'type' => 'select',
                'label' => '誰のパスワードを変えますか',
                'options' => $users ?? [],
                'empty' => '選んでください',
                'required' => true,
                'class' => 'u-select',
            ]) ?>
            <span class="u-field__help">選んだ人の今のパスワードは使えなくなります。</span>
        </div>
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
        </div>
    </section>

    <div class="u-actions">
        <?= $this->Form->button('この内容で再設定する', ['class' => 'u-btn u-btn--primary']) ?>
        <?= $this->Html->link('やめる', ['action' => 'index'], ['class' => 'u-btn u-btn--quiet']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
