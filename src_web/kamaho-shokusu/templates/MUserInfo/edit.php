<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $rooms
 * @var array $selectedRooms
 * @var \App\Model\Entity\User $user
 * @var bool $canChangeRole 役職を変更できるか（管理者が他のユーザーを編集するとき）
 * @var bool $canEditRooms 所属部屋を変更できるか
 */
$this->assign('title', 'ユーザー情報の編集');
// $rooms は部屋情報の配列としてコントローラから渡されることを想定しています。
// $selectedRooms はユーザーが現在所属している部屋のID配列です。
$isAdmin = in_array((int)$user->get('i_admin'), [1, 3]);
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
                            'class' => 'form-control',
                            // 職員・ブロック長が児童を編集するときは変更できない（認証情報のため）
                            'readonly' => !$canEditRooms,
                        ]) ?>
                    </div>
                    <div class="mb-3">
                        <?= $this->Form->control('c_user_name', [
                            'label' => 'ユーザ名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                    <?php if ($canChangeRole): ?>
                        <div class="mb-3">
                            <?= $this->Form->control('i_user_level', [
                                'type'    => 'select',
                                'options' => [0 => '職員', 1 => '児童', 3 => 'その他'],
                                'label'   => '役職',
                                'class'   => 'form-control',
                                'id'      => 'edit-role',
                            ]) ?>
                        </div>
                        <div class="mb-3" id="edit-staff-id-field">
                            <?= $this->Form->control('i_id_staff', [
                                'type'  => 'text',
                                'label' => '職員ID（役職が職員のとき必須）',
                                'class' => 'form-control',
                                'id'    => 'edit-staff-id',
                            ]) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($isAdmin && (int)($mUserInfo->i_del_flag ?? 1) === 0): ?>
                        <div class="mb-3">
                            <?= $this->Html->link(
                                __('🔑 パスワード変更'),
                                ['action' => 'adminChangePassword', '?' => ['user_id' => $mUserInfo->i_id_user]],
                                ['class' => 'btn btn-outline-primary btn-sm']
                            ) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($canEditRooms): ?>
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
                    <?php endif; ?>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
                <?php if ($canChangeRole): ?>
                <script>
                    // 役職が職員のときだけ職員IDの入力欄を表示する
                    (function () {
                        const role = document.getElementById('edit-role');
                        const field = document.getElementById('edit-staff-id-field');
                        if (!role || !field) { return; }
                        const sync = function () { field.style.display = role.value === '0' ? '' : 'none'; };
                        role.addEventListener('change', sync);
                        sync();
                    })();
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
