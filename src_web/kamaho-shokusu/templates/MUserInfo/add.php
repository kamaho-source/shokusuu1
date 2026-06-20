</fieldset>

<!-- =========================================================
     ここから試験的ユーザー情報フォーム
========================================================= -->
<?php
$this->assign('title', 'ユーザー情報の追加');
$this->Html->css('bootstrap-icons.css', ['block' => true]);
$this->Html->script('bootstrap.bundle.min.js', ['block' => true]);
$this->Html->script('realtime-validation.js', ['block' => true]);
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
                <?= $this->Form->create($mUserInfo ?? null, [
                    'class'      => 'needs-validation',
                    'novalidate' => true,
                    'id'         => 'reservation-form'
                ]) ?>
                <p class="text-muted small mb-3"><span class="text-danger" aria-hidden="true">*</span> は必須項目です</p>
                <fieldset>
                    <!-- ログインID -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label'             => ['text' => 'ログインID <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'id'                => 'c_login_account',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => 'ログインIDを入力してください。',
                        ]) ?>
                        <div id="login-id-error" class="invalid-feedback"></div>
                    </div>

                    <!-- 生年月日 -->
                    <div class="mb-3">
                        <?= $this->Form->control('birth_date', [
                            'type'        => 'text',
                            'label'       => ['text' => '生年月日', 'class' => 'form-label'],
                            'class'       => 'form-control',
                            'id'          => 'birthDate',
                            'placeholder' => '例: 1990-04-01',
                            'pattern'     => '\d{4}-\d{2}-\d{2}',
                            'inputmode'   => 'numeric'
                        ]) ?>
                    </div>

                    <!-- パスワード -->
                    <div class="mb-3">
                        <?= $this->Form->label('c_login_passwd', 'パスワード <span class="text-danger" aria-hidden="true">*</span>', [
                            'class'  => 'form-label',
                            'for'    => 'inputPassword',
                            'escape' => false,
                        ]) ?>
                        <div class="position-relative">
                            <?= $this->Form->control('c_login_passwd', [
                                'type'              => 'password',
                                'class'             => 'form-control pe-5',
                                'id'                => 'inputPassword',
                                'label'             => false,
                                'div'               => false,
                                'aria-required'     => 'true',
                                'data-validate'     => 'required',
                                'data-msg-required' => 'パスワードを入力してください。',
                            ]) ?>
                            <img src="<?= $this->Html->Url->image('eye-slash.svg') ?>"
                                 id="eyeIcon"
                                 alt="パスワード非表示"
                                 class="position-absolute"
                                 style="cursor:pointer; top:50%; right:10px; transform:translateY(-50%);" />
                        </div>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- ユーザー名 -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_user_name', [
                            'label'             => ['text' => 'ユーザー名 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => 'ユーザー名を入力してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 性別 -->
                    <div class="mb-3">
                        <?= $this->Form->control('i_user_gender', [
                            'type'              => 'select',
                            'options'           => [1 => '男性', 2 => '女性'],
                            'label'             => ['text' => '性別 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '性別を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- どの年代が食べたか -->
                    <div class="mb-3">
                        <?= $this->Form->control('age_group', [
                            'type'              => 'select',
                            'options'           => [
                                1 => '3~5才',
                                2 => '低学年',
                                3 => '中学年',
                                4 => '高学年',
                                5 => '中学生',
                                6 => '高校生',
                                7 => '大人'
                            ],
                            'label'             => ['text' => '年代選択 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '年代を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 年齢 -->
                    <div class="mb-3">
                        <label for="ageSelect" class="form-label">年齢 <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="ageSelect" name="age" class="form-control"
                                aria-required="true"
                                data-validate="required"
                                data-msg-required="年齢を選択してください。">
                            <option value="">選択してください</option>
                            <?php for ($i = 1; $i <= 80; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?>歳</option>
                            <?php endfor; ?>
                        </select>
                        <div class="invalid-feedback">年齢を選択してください。</div>
                    </div>

                    <!-- 役職 -->
                    <div class="mb-3">
                        <?= $this->Form->control('role', [
                            'type'              => 'select',
                            'options'           => [0 => '職員', 1 => '児童', 3 => 'その他'],
                            'label'             => ['text' => '役職 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '役職を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 職員ID入力フィールド -->
                    <div id="staff-id-field" class="mb-3" style="display:none;">
                        <?= $this->Form->control('staff_id', [
                            'label'       => ['text' => '職員ID', 'class' => 'form-label'],
                            'class'       => 'form-control',
                            'type'        => 'text',
                            'placeholder' => '職員IDを入力してください'
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 部屋情報チェックボックス -->
                    <div class="mb-3">
                        <label><?= __('所属する部屋') ?></label>
                        <?php if (!empty($rooms)): ?>
                            <?php foreach ($rooms as $roomId => $roomName): ?>
                                <div class="form-check">
                                    <?= $this->Form->checkbox('MUserGroup.' . $roomId . '.i_id_room', [
                                        'value' => $roomId,
                                        'class' => 'form-check-input',
                                        'id'    => 'MUserGroup-' . $roomId . '-i_id_room'
                                    ]) ?>
                                    <label class="form-check-label" for="MUserGroup-<?= $roomId ?>-i_id_room"><?= h($roomName) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?= __('表示できる部屋がありません') ?></p>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary', 'id' => 'submit-button', 'disabled' => true]) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- ユーザー情報フォーム用 JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form       = document.getElementById('reservation-form');
        const submitBtn  = document.getElementById('submit-button');
        const roleSelect = document.querySelector('[name="role"]');

        /* ==================================================================
           生年月日選択 → 年齢自動設定
        ================================================================== */
        const birthDateInput = document.getElementById('birthDate');
        const ageSelect      = document.getElementById('ageSelect');

        const calcAge = (value) => {
            if (!value) return null;
            const [year, month, day] = value.split('-').map(Number);
            const today = new Date();
            let age = today.getFullYear() - year;
            if (today.getMonth() + 1 < month || (today.getMonth() + 1 === month && today.getDate() < day)) {
                age--;
            }
            return age;
        };

        const setAge = () => {
            const age = calcAge(birthDateInput.value.trim());
            if (Number.isInteger(age) && age >= 1 && age <= 80) {
                ageSelect.value = String(age);
                setTimeout(() => { ageSelect.value = String(age); }, 10);
            } else {
                ageSelect.value = '';
            }
        };

        ['input', 'change'].forEach(ev => birthDateInput.addEventListener(ev, setAge));

        /* ==================================================================
           役職 = 職員 のときだけ職員ID入力欄を表示
        ================================================================== */
        const staffIdFieldDiv = document.getElementById('staff-id-field');
        const staffInput      = form.querySelector('[name="staff_id"]');
        const staffFeedback   = staffIdFieldDiv.querySelector('.invalid-feedback');

        const toggleStaffIdField = (val) => {
            staffIdFieldDiv.style.display = (val === '0') ? 'block' : 'none';
            if (val !== '0' && staffInput) {
                staffInput.classList.remove('is-invalid', 'is-valid');
                if (staffFeedback) staffFeedback.style.display = 'none';
            }
            checkAllFields();
        };
        toggleStaffIdField(roleSelect.value);
        roleSelect.addEventListener('change', e => toggleStaffIdField(e.target.value));

        /* ==================================================================
           パスワード表示切替
        ================================================================== */
        const eyeIcon       = document.getElementById('eyeIcon');
        const passwordInput = document.getElementById('inputPassword');
        eyeIcon.addEventListener('click', () => {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.src        = '<?= $this->Html->Url->image('eye.svg') ?>';
                eyeIcon.alt        = 'パスワード表示';
            } else {
                passwordInput.type = 'password';
                eyeIcon.src        = '<?= $this->Html->Url->image('eye-slash.svg') ?>';
                eyeIcon.alt        = 'パスワード非表示';
            }
        });

        /* ==================================================================
           ログインID重複チェック (AJAX)
        ================================================================== */
        const loginIdField    = document.getElementById('c_login_account');
        const loginIdFeedback = document.getElementById('login-id-error');

        loginIdField.addEventListener('blur', () => {
            const loginId = loginIdField.value.trim();
            if (!loginId) return;

            fetch('/m-user-info/check-unique-login-id', {
                method : 'POST',
                headers: {
                    'Content-Type' : 'application/json',
                    'X-CSRF-Token' : document.querySelector('input[name="_csrfToken"]').value
                },
                body: JSON.stringify({ c_login_account: loginId })
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.unique) {
                        setInvalid(loginIdField, loginIdFeedback, 'このログインIDは既に使用されています。');
                    } else {
                        setValid(loginIdField, loginIdFeedback);
                    }
                    checkAllFields();
                });
        });

        /* ==================================================================
           職員IDリアルタイムバリデーション
        ================================================================== */
        if (staffInput) {
            ['input', 'change', 'blur'].forEach(ev => staffInput.addEventListener(ev, () => {
                if (roleSelect.value !== '0') return;
                if (!staffInput.value.trim()) {
                    setInvalid(staffInput, staffFeedback, '職員IDを入力してください。');
                } else {
                    setValid(staffInput, staffFeedback);
                }
                checkAllFields();
            }));
        }

        /* ==================================================================
           部屋チェックボックス変更時に状態更新
        ================================================================== */
        form.querySelectorAll('input[type="checkbox"][name^="MUserGroup"]')
            .forEach(cb => cb.addEventListener('change', checkAllFields));

        /* ==================================================================
           リアルタイムバリデーション初期化（data-validate フィールド）
        ================================================================== */
        initRealtimeValidation('reservation-form', 'submit-button');

        /* ==================================================================
           全体の送信ボタン状態チェック（追加条件を含む）
        ================================================================== */
        function checkAllFields() {
            const fields = form.querySelectorAll('[data-validate]');
            if (!Array.from(fields).every(f => validateOk(f))) {
                submitBtn.disabled = true;
                return;
            }
            if (roleSelect.value === '0') {
                if (!staffInput?.value.trim() || staffInput?.classList.contains('is-invalid')) {
                    submitBtn.disabled = true;
                    return;
                }
            }
            const roomCheckboxes = form.querySelectorAll('input[type="checkbox"][name^="MUserGroup"]');
            if (!Array.from(roomCheckboxes).some(cb => cb.checked)) {
                submitBtn.disabled = true;
                return;
            }
            submitBtn.disabled = false;
        }

        // data-validate フィールドのイベント後に追加条件もチェック
        form.addEventListener('input',  checkAllFields);
        form.addEventListener('change', checkAllFields);
        checkAllFields();

        /* ==================================================================
           送信前最終バリデーション
        ================================================================== */
        form.addEventListener('submit', (e) => {
            form.querySelectorAll('[data-validate]').forEach(f => validateField(f));

            if (roleSelect.value === '0' && !staffInput?.value.trim()) {
                if (staffInput) setInvalid(staffInput, staffFeedback, '職員IDを入力してください。');
                e.preventDefault();
                return;
            }

            const roomCheckboxes = form.querySelectorAll('input[type="checkbox"][name^="MUserGroup"]');
            if (!Array.from(roomCheckboxes).some(cb => cb.checked)) {
                alert('所属する部屋を1つ以上選択してください。');
                e.preventDefault();
            }
        });
    });
</script>
<!-- =========================================================
     ユーザー情報フォームここまで
========================================================= -->
