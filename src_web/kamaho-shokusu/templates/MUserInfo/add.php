<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $rooms
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
                <h4><?= __('ユーザー情報の追加') ?></h4>
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
                        <?= $this->Form->control('c_login_passwd', [
                            'label' => 'ログインパスワード',
                            'type' => 'password',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                    <div class="mb-3">
                        <?= $this->Form->control('c__user_name', [
                            'label' => 'ユーザ名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- 部屋情報のチェックボックス -->
                    <div class="mb-3">
                        <label><?= __('部屋の所属') ?></label>
                        <?php foreach ($rooms as $roomId => $roomName): ?>
                            <div class="form-check">
                                <?= $this->Form->checkbox("i_id_room[]", [
                                    'value' => $roomId,
                                    'class' => 'form-check-input',
                                    'id' => 'room-' . $roomId
                                ]) ?>
                                <label class="form-check-label" for="room-<?= $roomId ?>">
                                    <?= h($roomName) ?>
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
