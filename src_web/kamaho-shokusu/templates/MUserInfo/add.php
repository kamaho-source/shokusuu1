<?php
$this->assign('title', 'ユーザー情報の追加');
$this->Html->css('bootstrap-icons.css', ['block' => true]);
$this->Html->script('bootstrap.bundle.min.js', ['block' => true]);
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
                <fieldset>
                    <!-- ログインID -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label' => ['text' => 'ログインID', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'id'    => 'c_login_account'
                        ]) ?>
                        <!-- 重複エラーメッセージ（JS から制御） -->
                        <div id="login-id-error" class="invalid-feedback" style="display:none;">
                            <?= __('このログインIDは既に使用されています。') ?>
                        </div>
                    </div>

                    <!-- 生年月日（追加） -->
                    <div class="mb-3">
                        <?= $this->Form->control('birth_date', [
                            'type'  => 'date',
                            'label' => ['text' => '生年月日', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'id'    => 'birthDate'
                        ]) ?>
                    </div>

                    <!-- パスワード -->
                    <div class="mb-3">
                        <?= $this->Form->label('c_login_passwd', 'パスワード', [
                            'class' => 'form-label',
                            'for'   => 'inputPassword'
                        ]) ?>
                        <div class="position-relative">
                            <?= $this->Form->control('c_login_passwd', [
                                'type'  => 'password',
                                'class' => 'form-control pe-5',
                                'id'    => 'inputPassword',
                                'label' => false,
                                'div'   => false,
                            ]) ?>
                            <img src="<?= $this->Html->Url->image('eye-slash.svg') ?>"
                                 id="eyeIcon"
                                 alt="パスワード非表示"
                                 class="position-absolute"
                                 style="cursor:pointer; top:50%; right:10px; transform:translateY(-50%);" />
                        </div>
                    </div>

                    <!-- ユーザー名 -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_user_name', [
                            'label' => ['text' => 'ユーザー名', 'class' => 'form-label'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- 性別 -->
                    <div class="mb-3">
                        <?= $this->Form->control('i_user_gender', [
                            'type'    => 'select',
                            'options' => [1 => '男性', 2 => '女性'],
                            'label'   => ['text' => '性別', 'class' => 'form-label'],
                            'class'   => 'form-control',
                            'empty'   => '選択してください'
                        ]) ?>
                    </div>

                    <!-- どの年代が食べたか -->
                    <div class="mb-3">
                        <?= $this->Form->control('age_group', [
                            'type'    => 'select',
                            'options' => [
                                1 => '3~5才',
                                2 => '低学年',
                                3 => '中学年',
                                4 => '高学年',
                                5 => '中学生',
                                6 => '高校生',
                                7 => '大人'
                            ],
                            'label'  => ['text' => '年代選択', 'class' => 'form-label'],
                            'class'  => 'form-control',
                            'empty'  => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 年齢 -->
                    <div class="mb-3">
                        <label for="ageSelect" class="form-label">年齢</label>
                        <select id="ageSelect" name="age" class="form-control">
                            <option value="">選択してください</option>
                            <?php for ($i = 1; $i <= 80; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?>歳</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- 役職 -->
                    <div class="mb-3">
                        <?= $this->Form->control('role', [
                            'type'    => 'select',
                            'options' => [0 => '職員', 1 => '児童', 3 => 'その他'],
                            'label'   => ['text' => '役職', 'class' => 'form-label'],
                            'class'   => 'form-control',
                            'empty'   => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 職員ID入力フィールド（動的に表示） -->
                    <div id="staff-id-field" class="mb-3" style="display:none;">
                        <?= $this->Form->control('staff_id', [
                            'label'       => ['text' => '職員ID', 'class' => 'form-label'],
                            'class'       => 'form-control',
                            'type'        => 'text',
                            'placeholder' => '職員IDを入力してください'
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

                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary', 'id' => 'submit-button']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript部分 -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        /* ==================================================================
           生年月日を選択したら年齢セレクトを自動設定（タイムゾーン誤差対策済）
        ================================================================== */
        const birthDateInput = document.getElementById('birthDate');
        const ageSelect      = document.getElementById('ageSelect');

        /**
         * 年齢を計算する（ローカル時刻で正確に比較）
         * @param {string} value "YYYY-MM-DD" の日付文字列
         * @returns {number|null}
         */
        const calcAge = (value) => {
            if (!value) return null;

            const [year, month, day] = value.split('-').map(Number);
            if (!year || !month || !day) return null;

            const today = new Date();
            const todayYear = today.getFullYear();
            const todayMonth = today.getMonth() + 1; // getMonth()は0始まり
            const todayDay = today.getDate();

            console.log('--- 年齢計算ログ ---');
            console.log('入力値:', value);
            console.log('誕生日:', year, month, day);
            console.log('今日:', todayYear, todayMonth, todayDay);

            let age = todayYear - year;

            if (todayMonth < month || (todayMonth === month && todayDay < day)) {
                age--;
                console.log('今年の誕生日はまだ来ていない → 1歳引きます');
            } else {
                console.log('今年の誕生日はもう来ている → 年齢そのまま');
            }

            console.log('計算された年齢:', age);
            return age;
        };


        birthDateInput.addEventListener('change', () => {
            const inputValue = birthDateInput.value;
            console.log('生年月日選択:', inputValue);

            const age = calcAge(inputValue);
            if (Number.isInteger(age) && age >= 1 && age <= 80) {
                // まず即時設定
                ageSelect.value = String(age);
                console.log('セレクトに即時反映:', age);

                // DOM再描画後に再度強制反映（CakePHP自動再描画・他のJS上書き対策）
                setTimeout(() => {
                    ageSelect.value = String(age);
                    console.log('[setTimeout後] セレクトを再設定:', ageSelect.value);
                }, 10);
            } else {
                ageSelect.value = '';
                console.log('年齢が不正、セレクトを空に');
            }
        });


        /* ==================================================================
           ログインIDの重複チェック
        ================================================================== */
        const loginIdField  = document.getElementById('c_login_account');
        const loginIdError  = document.getElementById('login-id-error');
        const submitButton  = document.getElementById('submit-button');

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
                        loginIdError.style.display = 'block';
                        submitButton.disabled      = true;
                    } else {
                        loginIdError.style.display = 'none';
                        submitButton.disabled      = false;
                    }
                });
        });

        /* ==================================================================
           役職 = 職員 のときだけ職員ID入力欄を表示
        ================================================================== */
        const roleSelect      = document.querySelector('[name="role"]');
        const staffIdFieldDiv = document.getElementById('staff-id-field');

        const toggleStaffIdField = (roleVal) => {
            staffIdFieldDiv.style.display = (roleVal === '0') ? 'block' : 'none';
        };

        toggleStaffIdField(roleSelect.value);
        roleSelect.addEventListener('change', e => toggleStaffIdField(e.target.value));

        /* ==================================================================
           パスワード表示／非表示切替
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
           フロントエンド バリデーション
        ================================================================== */
        const form = document.getElementById('reservation-form');
        form.addEventListener('submit', (e) => {
            const requiredFields = [
                { id: 'c_login_account', label: 'ログインID' },
                { id: 'inputPassword',   label: 'パスワード'  },
                { name: 'c_user_name',   label: 'ユーザー名'  },
                { name: 'i_user_gender', label: '性別'       },
                { name: 'age_group',     label: '年代選択'   },
                { name: 'role',          label: '役職'       },
            ];

            for (const f of requiredFields) {
                const field = f.id
                    ? document.getElementById(f.id)
                    : document.querySelector(`[name="${f.name}"]`);
                if (!field || !field.value || field.value.trim() === '') {
                    alert(`${f.label}は必須入力です。`);
                    field?.focus();
                    e.preventDefault();
                    return;
                }
            }

            // 役職 = 職員 のとき staff_id 必須
            if (roleSelect.value === '0') {
                const staffInput = document.querySelector('[name="staff_id"]');
                if (!staffInput.value || staffInput.value.trim() === '') {
                    alert('職員IDは必須入力です。');
                    staffInput.focus();
                    e.preventDefault();
                    return;
                }
            }

            // 所属部屋チェック
            const roomCheckboxes = document.querySelectorAll('input[type="checkbox"][name^="MUserGroup"]');
            const anyChecked     = Array.from(roomCheckboxes).some(cb => cb.checked);
            if (!anyChecked) {
                alert('所属する部屋を1つ以上選択してください。');
                e.preventDefault();
            }
        });
    });
</script>
