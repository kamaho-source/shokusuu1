/**
 * realtime-validation.js
 *
 * フォームのリアルタイム・バリデーション共通ユーティリティ。
 *
 * 使い方:
 *   1. フォームに id="validated-form"（またはカスタム id）を付与
 *   2. 入力欄に data-validate="rule1 rule2 ..." を付与
 *   3. 入力欄の直後に <div class="invalid-feedback">メッセージ</div> を配置
 *   4. 送信ボタンに id="submit-btn"（またはカスタム id）を付与
 *   5. initRealtimeValidation(formId, submitBtnId) を呼び出す
 *
 * サポートするルール:
 *   required     - 必須入力（空白のみも NG）
 *   integer      - 整数のみ（小数・文字列 NG）
 *   positive     - 1 以上の整数
 *   non-negative - 0 以上の整数
 *   year         - 西暦年として妥当な整数（1900 〜 2100）
 *   maxlength:N  - 最大 N 文字
 *   date-range   - data-range-end="#endFieldId" と組み合わせて開始日 ≤ 終了日を検証
 */

/**
 * 単一フィールドにバリデーションを実行し、Bootstrap の is-invalid / is-valid クラスを付与する。
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|HTMLSelectElement} field - 検証対象要素
 * @returns {boolean} バリデーション通過なら true
 */
function validateField(field) {
    const rules   = (field.dataset.validate || '').split(/\s+/).filter(Boolean);
    const value   = field.value;
    const trimmed = value.trim();

    let errorMessage = '';

    for (const rule of rules) {
        if (rule === 'required') {
            if (!trimmed) {
                errorMessage = field.dataset.msgRequired || '入力必須です。';
                break;
            }
        } else if (rule === 'integer') {
            if (trimmed && !/^-?\d+$/.test(trimmed)) {
                errorMessage = field.dataset.msgInteger || '整数を入力してください。';
                break;
            }
        } else if (rule === 'positive') {
            const n = parseInt(trimmed, 10);
            if (trimmed && (!/^\d+$/.test(trimmed) || n < 1)) {
                errorMessage = field.dataset.msgPositive || '1 以上の整数を入力してください。';
                break;
            }
        } else if (rule === 'non-negative') {
            const n = parseInt(trimmed, 10);
            if (trimmed && (!/^\d+$/.test(trimmed) || n < 0)) {
                errorMessage = field.dataset.msgNonNegative || '0 以上の整数を入力してください。';
                break;
            }
        } else if (rule === 'year') {
            const n = parseInt(trimmed, 10);
            if (trimmed && (!/^\d{4}$/.test(trimmed) || n < 1900 || n > 2100)) {
                errorMessage = field.dataset.msgYear || '正しい年度（1900〜2100）を入力してください。';
                break;
            }
        } else if (rule.startsWith('maxlength:')) {
            const max = parseInt(rule.split(':')[1], 10);
            if (trimmed.length > max) {
                errorMessage = field.dataset.msgMaxlength || `${max} 文字以内で入力してください。`;
                break;
            }
        } else if (rule === 'date-range') {
            const endFieldId = field.dataset.rangeEnd;
            if (endFieldId) {
                const endField = document.getElementById(endFieldId);
                if (endField && field.value && endField.value) {
                    if (endField.value < field.value) {
                        const endFeedback = endField.nextElementSibling;
                        setInvalid(endField, endFeedback, '終了日は開始日以降の日付を入力してください。');
                        errorMessage = '開始日は終了日以前の日付を入力してください。';
                        break;
                    } else {
                        const endFeedback = endField.nextElementSibling;
                        setValid(endField, endFeedback);
                    }
                }
            }
        }
    }

    const feedback = field.nextElementSibling?.classList.contains('invalid-feedback')
        ? field.nextElementSibling
        : (field.closest('.mb-3, .col-md-6, .col-sm-6')?.querySelector('.invalid-feedback') ?? null);

    if (errorMessage) {
        setInvalid(field, feedback, errorMessage);
        return false;
    }

    setValid(field, feedback);
    return true;
}

function setInvalid(field, feedbackEl, message) {
    field.classList.add('is-invalid');
    field.classList.remove('is-valid');
    if (feedbackEl) {
        feedbackEl.textContent = message;
        feedbackEl.style.display = 'block';
    }
}

function setValid(field, feedbackEl) {
    field.classList.remove('is-invalid');
    if (feedbackEl) {
        feedbackEl.style.display = 'none';
    }
}

/**
 * フォーム全体のバリデーション状態を確認して送信ボタンの有効/無効を切り替える。
 *
 * @param {HTMLFormElement} form
 * @param {HTMLButtonElement|HTMLInputElement} submitBtn
 */
function updateSubmitState(form, submitBtn) {
    const fields = form.querySelectorAll('[data-validate]');
    const allValid = Array.from(fields).every(f => !f.classList.contains('is-invalid') && validateOk(f));
    submitBtn.disabled = !allValid;
}

/**
 * フィールドが現在バリデーション通過状態かを確認する（is-invalid でなければ OK とみなす）。
 * 未入力で required でない場合は通過扱い。
 */
function validateOk(field) {
    if (field.classList.contains('is-invalid')) return false;
    const rules = (field.dataset.validate || '').split(/\s+/).filter(Boolean);
    if (rules.includes('required') && !field.value.trim()) return false;
    return true;
}

/**
 * リアルタイム・バリデーションを初期化する。
 *
 * @param {string} formId      - フォーム要素の id
 * @param {string} submitBtnId - 送信ボタン要素の id
 */
function initRealtimeValidation(formId, submitBtnId) {
    const form      = document.getElementById(formId);
    const submitBtn = document.getElementById(submitBtnId);
    if (!form || !submitBtn) return;

    const fields = form.querySelectorAll('[data-validate]');

    const onInput = (field) => {
        validateField(field);
        updateSubmitState(form, submitBtn);
    };

    fields.forEach(field => {
        field.addEventListener('input',  () => onInput(field));
        field.addEventListener('change', () => onInput(field));
        field.addEventListener('blur',   () => onInput(field));
    });

    // 初期状態チェック（既存値がある編集フォーム向け）
    fields.forEach(field => {
        if (field.value.trim()) {
            validateField(field);
        }
    });
    updateSubmitState(form, submitBtn);
}
