<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $rooms
 * @var array $selectedRooms
 * @var \App\Model\Entity\User $user
 */
use App\Domain\ValueObject\UserProfileOptions;
$this->assign('title', 'ユーザー情報の編集');
// $rooms は部屋情報の配列としてコントローラから渡されることを想定しています。
// $selectedRooms はユーザーが現在所属している部屋のID配列です。
$isAdmin = in_array((int)$user->get('i_admin'), [1, 3]);
// 未設定や選択肢に無い値（古いデータの役職7など）は、選び直さない限りそのまま残す。
// 空の選択肢を出さないと、ブラウザが先頭の選択肢を選んだ状態になり、保存で黙って書き換わる。
// （空の選択肢を選んだまま送れるよう、テーブルの検証から付く required は外している）
$keepOption = static function (array $options, int|string|null $value): string|false {
    if ($value === null || $value === '') {
        return '未設定';
    }
    return isset($options[(int)$value]) ? false : UserProfileOptions::label($options, $value) . '（変更しない）';
};
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('操作') ?></h4>
            <?= $this->Html->link(__('ユーザー詳細を表示する'), ['action' => 'view', $mUserInfo->i_id_user], ['class' => 'list-group-item list-group-item-action']) ?>
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
                        <?= $this->Form->control('c_user_name', [
                            'label' => 'ユーザ名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                    <?php if ($isAdmin && (int)($mUserInfo->i_del_flag ?? 1) === 0): ?>
                        <div class="mb-3">
                            <?= $this->Html->link(
                                __('🔑 パスワード変更'),
                                ['action' => 'adminChangePassword', '?' => ['user_id' => $mUserInfo->i_id_user]],
                                ['class' => 'btn btn-outline-primary btn-sm']
                            ) ?>
                        </div>
                    <?php endif; ?>
                    <?php /* 新規登録と同じ並び。性別・年代・役職・職員IDは管理者だけ変更できる。年齢は全員表示だけ。 */ ?>
                    <?php if ($isAdmin): ?>
                        <div class="mb-3">
                            <?= $this->Form->control('i_user_gender', [
                                'type' => 'select', 'options' => UserProfileOptions::GENDERS, 'empty' => $keepOption(UserProfileOptions::GENDERS, $mUserInfo->i_user_gender),
                                'label' => '性別', 'class' => 'form-select', 'required' => false,
                            ]) ?>
                        </div>
                        <div class="mb-3">
                            <?= $this->Form->control('i_user_rank', [
                                'type' => 'select', 'options' => UserProfileOptions::AGE_GROUPS, 'empty' => $keepOption(UserProfileOptions::AGE_GROUPS, $mUserInfo->i_user_rank),
                                'label' => '年代', 'class' => 'form-select', 'required' => false,
                            ]) ?>
                        </div>
                    <?php else: ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-3 fw-normal text-muted"><?= __('性別') ?></dt>
                            <dd class="col-sm-9"><?= h(UserProfileOptions::label(UserProfileOptions::GENDERS, $mUserInfo->i_user_gender)) ?></dd>
                            <dt class="col-sm-3 fw-normal text-muted"><?= __('年代') ?></dt>
                            <dd class="col-sm-9"><?= h(UserProfileOptions::label(UserProfileOptions::AGE_GROUPS, $mUserInfo->i_user_rank)) ?></dd>
                        </dl>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="ageReadonly"><?= __('年齢') ?></label>
                        <input type="text" id="ageReadonly" class="form-control-plaintext" readonly
                               value="<?= $mUserInfo->i_user_age ? h($mUserInfo->i_user_age) . '歳' : __('未設定') ?>">
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="mb-3">
                            <?= $this->Form->control('i_user_level', [
                                'type' => 'select', 'options' => UserProfileOptions::USER_LEVELS,
                                'empty' => $keepOption(UserProfileOptions::USER_LEVELS, $mUserInfo->i_user_level),
                                'label' => '役職', 'class' => 'form-select', 'id' => 'userLevelSelect', 'required' => false,
                                'data-original' => (string)$mUserInfo->i_user_level,
                            ]) ?>
                        </div>
                        <div class="mb-3" id="staffIdField">
                            <?= $this->Form->control('i_id_staff', [
                                'type' => 'text', 'label' => '職員ID', 'class' => 'form-control',
                            ]) ?>
                        </div>
                    <?php else: ?>
                        <dl class="row">
                            <dt class="col-sm-3 fw-normal text-muted"><?= __('役職') ?></dt>
                            <dd class="col-sm-9"><?= h(UserProfileOptions::label(UserProfileOptions::USER_LEVELS, $mUserInfo->i_user_level)) ?></dd>
                            <?php if ($mUserInfo->i_id_staff): ?>
                                <dt class="col-sm-3 fw-normal text-muted"><?= __('職員ID') ?></dt>
                                <dd class="col-sm-9"><?= h($mUserInfo->i_id_staff) ?></dd>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label>所属部屋</label>
                        <?php foreach ($rooms as $id => $name): ?>
                            <div class="form-check">
                                <?= $this->Form->checkbox("rooms[$id]", [
                                    'value' => $id === 0 ? 0 : 1,
                                    'checked' => in_array($id, $selectedRooms),
                                    'class' => 'form-check-input'
                                ]) ?>
                                <label class="form-check-label" for="rooms-<?= $id ?>">
                                    <?= h($name) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
<?php if ($isAdmin): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const level = document.getElementById('userLevelSelect');
        const staffField = document.getElementById('staffIdField');
        const labels = <?= json_encode(UserProfileOptions::USER_LEVELS, JSON_UNESCAPED_UNICODE) ?>;
        // 職員ID は役職が職員のときだけ使う（新規登録と同じ）
        const toggleStaff = () => { staffField.style.display = level.value === '<?= UserProfileOptions::STAFF_LEVEL ?>' ? '' : 'none'; };
        level.addEventListener('change', toggleStaff);
        toggleStaff();
        // 役職は権限と食数集計（子ども・大人の区分）に影響するので、変えたときは保存前に確認する
        level.form.addEventListener('submit', (e) => {
            const before = level.dataset.original;
            // 「変更しない」（空）のまま、または元と同じなら確認しない
            if (level.value === '' || before === level.value) return;
            const msg = '役職を「' + (before === '' ? '未設定' : (labels[before] || '定義外の値（' + before + '）')) + '」から「' + labels[level.value] + '」に変更します。\n'
                + '権限や食数の集計（子ども・大人の区分）が変わります。よろしいですか？';
            if (!window.confirm(msg)) e.preventDefault();
        });
    });
</script>
<?php endif; ?>
