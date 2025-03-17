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
    <div class="mb-3 position-relative">
        <?= $this->Form->control('new_password', [
            'type' => 'password',
            'label' => ['text' => '新しいパスワード', 'class' => 'form-label'],
            'required' => true,
            'class' => 'form-control password-field pe-5', // 右端余白を保持
            'div' => false // 自動ラッパーを除外
        ]) ?>
        <img src="<?= $this->Html->Url->image('eye-slash.svg') ?>"
             alt="パスワード非表示"
             class="eye-icon position-absolute"
             style="cursor: pointer; top: 70%; right: 10px; transform: translateY(-50%);"
        />
    </div>

    <!-- 新しいパスワード確認 -->
    <div class="mb-3 position-relative">
        <?= $this->Form->control('confirm_password', [
            'type' => 'password',
            'label' => ['text' => '新しいパスワード (確認)', 'class' => 'form-label'],
            'required' => true,
            'class' => 'form-control password-field pe-5', // 右端余白を保持
            'div' => false // 自動ラッパーを除外
        ]) ?>
        <img src="<?= $this->Html->Url->image('eye-slash.svg') ?>"
             alt="パスワード非表示"
             class="eye-icon position-absolute"
             style="cursor: pointer; top: 70%; right: 10px; transform: translateY(-50%);"
        />
    </div>

    <!-- 送信ボタン -->
    <div class="mb-3">
        <?= $this->Form->button('パスワードを変更', ['class' => 'btn btn-primary']) ?>
    </div>
</fieldset>
<?= $this->Form->end() ?>

<script>
    // パスワード表示/非表示切り替え
    document.querySelectorAll('.eye-icon').forEach(icon => {
        icon.addEventListener('click', function () {
            // アイコンに隣接した password input を取得
            const input = this.closest('div').querySelector('.password-field');
            if (input.type === 'password') {
                input.type = 'text';
                this.src = "<?= $this->Html->Url->image('eye.svg') ?>"; // 表示アイコンに切り替え
            } else {
                input.type = 'password';
                this.src = "<?= $this->Html->Url->image('eye-slash.svg') ?>"; // 非表示アイコンに切り替え
            }
        });
    });
</script>
