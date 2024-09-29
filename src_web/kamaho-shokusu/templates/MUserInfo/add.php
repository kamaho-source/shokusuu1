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
                <?= $this->Form->create($mUserInfo, ['class' => 'needs-validation', 'novalidate' => true]) ?>
                <fieldset>
                    <!-- ログインID -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label' => ['text' => 'ログインID', 'class' => 'form-label'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- パスワード -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_passwd', [
                            'label' => ['text' => 'パスワード', 'class' => 'form-label'],
                            'type' => 'password',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- ユーザー名 -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_user_name', [
                            'label' => ['text' => 'ユーザー名', 'class' => 'form-label'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- 年齢 -->
                    <div class="mb-3">
                        <?= $this->Form->control('age', [
                            'type' => 'select',
                            'options' => range(1, 80),
                            'label' => ['text' => '年齢', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'empty' => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 役職 -->
                    <div class="mb-3">
                        <?= $this->Form->control('role', [
                            'type' => 'select',
                            'options' => [0 => '職員', 1 => '児童', 3 => 'その他'],
                            'label' => ['text' => '役職', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'empty' => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 部屋情報のチェックボックス -->
                    <div class="mb-3">
                        <label><?= __('所属する部屋') ?></label>
                        <?php if (!empty($rooms)): ?>
                            <?php foreach ($rooms as $roomId => $roomName): ?>
                                <div class="form-check">
                                    <?= $this->Form->checkbox('MUserGroup.' . $roomId . '.i_id_room', [
                                        'value' => $roomId,
                                        'class' => 'form-check-input',
                                        'id' => 'MUserGroup-' . $roomId . '-i_id_room'
                                    ]) ?>
                                    <label class="form-check-label" for="MUserGroup-<?= $roomId ?>-i_id_room"><?= h($roomName) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?= __('表示できる部屋がありません') ?></p>
                        <?php endif; ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
