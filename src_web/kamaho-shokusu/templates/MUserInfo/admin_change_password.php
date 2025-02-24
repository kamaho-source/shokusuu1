<?= $this->Form->create(null, ['url' => ['action' => 'adminChangePassword'], 'class' => 'needs-validation']) ?>
    <fieldset>
        <legend class="mb-4">ユーザーのパスワード変更</legend>
        <?= $this->Flash->render() ?>

        <!-- ユーザー選択 -->
        <div class="mb-3">
            <?= $this->Form->control('user_id', [
                'type' => 'select',
                'options' => $users,
                'empty' => 'ユーザーを選択してください',
                'label' => ['text' => 'ユーザー選択', 'class' => 'form-label'],
                'required' => true,
                'class' => 'form-select'
            ]) ?>
        </div>

        <!-- 新しいパスワード入力 -->
        <div class="mb-3">
            <?= $this->Form->control('new_password', [
                'type' => 'password',
                'label' => ['text' => '新しいパスワード', 'class' => 'form-label'],
                'required' => true,
                'class' => 'form-control'
            ]) ?>
        </div>

        <!-- 新しいパスワード確認 -->
        <div class="mb-3">
            <?= $this->Form->control('confirm_password', [
                'type' => 'password',
                'label' => ['text' => '新しいパスワード (確認)', 'class' => 'form-label'],
                'required' => true,
                'class' => 'form-control'
            ]) ?>
        </div>

        <!-- 送信ボタン -->
        <div class="mb-3">
            <?= $this->Form->button('パスワードを変更', ['class' => 'btn btn-primary']) ?>
        </div>
    </fieldset>
<?= $this->Form->end() ?>
