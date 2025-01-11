<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $rooms
 * @var array $selectedRooms
 */
$this->assign('title', 'ユーザー情報の編集');
// $rooms は部屋情報の配列としてコントローラから渡されることを想定しています。
// $selectedRooms はユーザーが現在所属している部屋のID配列です。
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
                        <?= $this->Form->control('c_user_name', [
                            'label' => 'ユーザ名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
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
