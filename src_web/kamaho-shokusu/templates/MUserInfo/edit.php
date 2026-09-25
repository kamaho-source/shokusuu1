<?php
/**
 * ユーザー情報の編集
 *
 * 詳細画面から「この人の情報を変更する」で来る。
 * 権限の変更は誤操作の影響が大きいため、この画面ではなく詳細画面に置いている。
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $rooms         部屋ID => 部屋名
 * @var array $selectedRooms 現在所属している部屋IDの配列
 */
$this->assign('title', 'ユーザー情報の変更');
$this->Html->css('pages/user_screens.css', ['block' => 'css']);
?>
<div class="u-shell u-shell--narrow">

    <div class="u-head">
        <div>
            <div class="u-eyebrow">ユーザー情報の変更</div>
            <h1 class="u-title"><?= h($mUserInfo->c_user_name) ?></h1>
        </div>
        <a class="u-back" href="<?= $this->Url->build(['action' => 'view', $mUserInfo->i_id_user]) ?>">
            <span aria-hidden="true">←</span> 戻る
        </a>
    </div>

    <?= $this->Form->create($mUserInfo) ?>

    <section class="u-card">
        <h2 class="u-card__title">基本情報</h2>

        <div class="u-field">
            <?= $this->Form->control('c_login_account', [
                'label' => 'ログインID',
                'class' => 'u-input',
            ]) ?>
            <span class="u-field__help">ログインするときに入力する ID です。</span>
        </div>

        <div class="u-field">
            <?= $this->Form->control('c_user_name', [
                'label' => '名前',
                'class' => 'u-input',
            ]) ?>
            <span class="u-field__help">一覧や食数の画面に表示されます。</span>
        </div>
    </section>

    <section class="u-card">
        <h2 class="u-card__title">所属部屋</h2>
        <p class="u-card__note">この人が食数を入力する部屋を選びます。いくつでも選べます。</p>
        <div class="u-checks">
            <?php foreach ($rooms as $id => $name): ?>
                <label class="u-check" for="rooms-<?= h($id) ?>">
                    <?= $this->Form->checkbox("rooms[$id]", [
                        'id'      => "rooms-$id",
                        'value'   => $id === 0 ? 0 : 1,
                        'checked' => in_array($id, $selectedRooms),
                        'hiddenField' => false,
                    ]) ?>
                    <span><?= h($name) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="u-actions">
        <?= $this->Form->button('変更を保存する', ['class' => 'u-btn u-btn--primary']) ?>
        <?= $this->Html->link('やめる', ['action' => 'view', $mUserInfo->i_id_user], ['class' => 'u-btn u-btn--quiet']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>
