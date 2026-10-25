<?php
/**
 * @var \App\View\AppView $this
 * @var mixed $rooms
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */
use App\Domain\ValueObject\UserProfileOptions;
?>
</fieldset>

<!-- =========================================================
     ここから試験的ユーザー情報フォーム
========================================================= -->
<?php
$this->assign('title', 'ユーザー情報の追加');
$this->Html->script('bootstrap.bundle.min.js', ['block' => true]);
$this->Html->script('realtime-validation.js', ['block' => true]);
$this->Html->script('birth-date-age.js', ['block' => true]);
?>
<style>
    /* パスワード欄の伏せ字（type=password を使わないため。Chrome・Safari・Edge・Firefox で効く） */
    .masked-password { -webkit-text-security: disc; }
</style>
<div class="row">
    <?= $this->element('side_actions', [
        'items' => [
            ['tone' => 'back', 'label' => 'ユーザー一覧へ戻る', 'url' => ['action' => 'index']],
        ],
    ]) ?>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4><?= __('ユーザー情報の追加') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mUserInfo ?? null, [
                    'class'      => 'needs-validation',
                    'novalidate' => true,
                    'id'         => 'reservation-form',
                    // 新しいユーザーを作る画面なので、ブラウザに保存された（ログイン中の人の）ID・パスワードを入れさせない
                    'autocomplete' => 'off',
                ]) ?>
                <p class="text-muted small mb-3"><span class="text-danger" aria-hidden="true">*</span> は必須項目です</p>
                <fieldset>
                    <!-- ログインID -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label'             => ['text' => 'ログインID <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'id'                => 'c_login_account',
                            'autocomplete'      => 'off',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => 'ログインIDを入力してください。',
                        ]) ?>
                        <div id="login-id-error" class="invalid-feedback"></div>
                    </div>

                    <!-- 生年月日（保存しない。年齢・年代を自動で選ぶためだけに使う） -->
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 mb-1"><?= __('生年月日') ?></legend>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <input type="text" id="birthYear" name="birth_year" class="form-control" style="max-width:7rem"
                                   inputmode="numeric" maxlength="4" placeholder="例: 2015" aria-label="生まれた年（西暦）"
                                   value="<?= h((string)$this->request->getData('birth_year')) ?>">
                            <span>年</span>
                            <select id="birthMonth" name="birth_month" class="form-select" style="max-width:6rem" aria-label="生まれた月">
                                <option value="">--</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= (string)$this->request->getData('birth_month') === (string)$m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>月</span>
                            <select id="birthDay" name="birth_day" class="form-select" style="max-width:6rem" aria-label="生まれた日"
                                    data-selected="<?= h((string)$this->request->getData('birth_day')) ?>">
                                <option value="">--</option>
                            </select>
                            <span>日</span>
                        </div>
                        <div id="birthDateFeedback" class="invalid-feedback d-block" aria-live="polite"></div>
                        <div class="form-text"><?= __('入力すると年齢と年代（4月2日区切りの学年）が自動で選ばれます（生年月日は保存されません）。選んだ後も変更できます。') ?></div>
                    </fieldset>

                    <!-- パスワード -->
                    <div class="mb-3">
                        <?= $this->Form->label('c_login_passwd', 'パスワード <span class="text-danger" aria-hidden="true">*</span>', [
                            'class'  => 'form-label',
                            'for'    => 'inputPassword',
                            'escape' => false,
                        ]) ?>
                        <div class="position-relative">
                            <?= $this->Form->control('c_login_passwd', [
                                // type=password にすると Chrome が保存済みのID・パスワードの候補を出す（autocomplete では止まらない）。
                                // 新しい人のパスワードを決める欄なので、文字欄を伏せ字で表示して候補を出させない。
                                'type'              => 'text',
                                'class'             => 'form-control pe-5 masked-password',
                                'id'                => 'inputPassword',
                                'autocomplete'      => 'off',
                                'autocapitalize'    => 'off',
                                'spellcheck'        => 'false',
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
                            'options'           => UserProfileOptions::GENDERS,
                            'label'             => ['text' => '性別 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '性別を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 年代 -->
                    <div class="mb-3">
                        <?= $this->Form->control('age_group', [
                            'type'              => 'select',
                            'options'           => UserProfileOptions::AGE_GROUPS,
                            'id'                => 'ageGroupSelect',
                            'label'             => ['text' => '年代選択 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '年代を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 年齢（入力エラーで画面が再表示されても選択が残るようフォームヘルパーで出す） -->
                    <div class="mb-3">
                        <?= $this->Form->control('age', [
                            'type'              => 'select',
                            'options'           => array_combine($ages, array_map(static fn(int $a) => $a . '歳', $ages)),
                            'id'                => 'ageSelect',
                            'label'             => ['text' => '年齢 <span class="text-danger" aria-hidden="true">*</span>', 'class' => 'form-label', 'escape' => false],
                            'class'             => 'form-control',
                            'empty'             => '選択してください',
                            'aria-required'     => 'true',
                            'data-validate'     => 'required',
                            'data-msg-required' => '年齢を選択してください。',
                        ]) ?>
                        <div class="invalid-feedback">年齢を選択してください。</div>
                    </div>

                    <!-- 役職 -->
                    <div class="mb-3">
                        <?= $this->Form->control('role', [
                            'type'              => 'select',
                            'options'           => $roles ?? UserProfileOptions::USER_LEVELS,
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
           生年月日（年は入力・月日は選択）→ 年齢・年代を自動で選ぶ
        ================================================================== */
        const birthYear     = document.getElementById('birthYear');
        const birthMonth    = document.getElementById('birthMonth');
        const birthDay      = document.getElementById('birthDay');
        const birthFeedback = document.getElementById('birthDateFeedback');
        const ageSelect     = document.getElementById('ageSelect');
        const ageGroupSelect = document.getElementById('ageGroupSelect');

        // 日の選択肢を、選んだ年・月の末日に合わせる
        const fillDays = () => {
            // 年が4桁そろうまでは、うるう年として 29 日まで出す（入力途中の「2」「20」で2月29日が消えないように）
            const y = /^\d{4}$/.test(birthYear.value.trim()) ? Number(birthYear.value.trim()) : 2000;
            const m = Number(birthMonth.value);
            const last = m ? BirthDateAge.daysInMonth(y, m) : 31;
            const keep = birthDay.value || birthDay.dataset.selected || '';
            birthDay.dataset.selected = '';
            birthDay.innerHTML = '<option value="">--</option>';
            for (let d = 1; d <= last; d++) {
                birthDay.add(new Option(String(d), String(d), false, String(d) === keep));
            }
        };

        // 自動で選んだ値。生年月日を直して自動で選べなくなったら、自動で入れた値だけを取り消す（手で選んだ値は残す）
        const auto = { age: null, ageGroup: null };
        const setAuto = (select, key, value) => {
            select.value = value === null ? '' : String(value);
            auto[key] = value === null ? null : String(value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const clearAuto = () => {
            if (auto.age !== null && ageSelect.value === auto.age) setAuto(ageSelect, 'age', null);
            if (auto.ageGroup !== null && ageGroupSelect.value === auto.ageGroup) setAuto(ageGroupSelect, 'ageGroup', null);
        };

        const applyBirthDate = () => {
            birthFeedback.textContent = '';
            const y = birthYear.value.trim();
            if (!y || !birthMonth.value || !birthDay.value) {   // そろうまでは何もしない
                clearAuto();
                return;
            }
            if (!/^\d{4}$/.test(y)) {
                birthFeedback.textContent = '年は西暦4桁の数字で入力してください。';
                clearAuto();
                return;
            }
            const r = BirthDateAge.fromBirthDate(Number(y), Number(birthMonth.value), Number(birthDay.value), new Date());
            if (r.error) {
                birthFeedback.textContent = r.error;
                clearAuto();
                return;
            }
            if (r.age >= <?= UserProfileOptions::MIN_AGE ?> && r.age <= <?= UserProfileOptions::MAX_AGE ?>) {
                setAuto(ageSelect, 'age', r.age);
            } else {
                birthFeedback.textContent = '年齢が選択肢の範囲外のため、年齢は手で選んでください。';
                clearAuto();
            }
            setAuto(ageGroupSelect, 'ageGroup', r.ageGroup);
        };

        birthYear.addEventListener('input', () => { fillDays(); applyBirthDate(); });
        birthMonth.addEventListener('change', () => { fillDays(); applyBirthDate(); });
        birthDay.addEventListener('change', applyBirthDate);
        fillDays();

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
            const masked = passwordInput.classList.toggle('masked-password');
            eyeIcon.src  = masked ? '<?= $this->Html->Url->image('eye-slash.svg') ?>' : '<?= $this->Html->Url->image('eye.svg') ?>';
            eyeIcon.alt  = masked ? 'パスワード非表示' : 'パスワード表示';
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
