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
                        <?= $this->Form->control('age', [
                            'type'    => 'select',
                            'options' => range(1, 80),
                            'label'   => ['text' => '年齢', 'class' => 'form-label'],
                            'class'   => 'form-control',
                            'empty'   => '選択してください',
                            'id'      => 'ageSelect'
                        ]) ?>
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
           生年月日を選択したら年齢セレクトを自動設定
        ================================================================== */
        const birthDateInput = document.getElementById('birthDate');
        const ageSelect      = document.getElementById('ageSelect');

        const calcAge = (birthStr) => {
            const bd = new Date(birthStr);
            if (Number.isNaN(bd.getTime())) return null;

            const today = new Date();
            let age = today.getFullYear() - bd.getFullYear();
            const m = today.getMonth() - bd.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) {
                age--;
            }
            return age;
        };

        birthDateInput.addEventListener('change', () => {
            const age = calcAge(birthDateInput.value);
            if (age && age >= 1 && age <= 80) {
                ageSelect.value = age.toString();
            } else {
                ageSelect.value = '';
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
        $('#eyeIcon').on('click', function() {
            const input = $('#inputPassword');
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                $(this).attr('src', '<?= $this->Html->Url->image('eye.svg') ?>');
            } else {
                input.attr('type', 'password');
                $(this).attr('src', '<?= $this->Html->Url->image('eye-slash.svg') ?>');
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
             //   { id: 'birthDate',       label: '生年月日'    }, // 追加
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