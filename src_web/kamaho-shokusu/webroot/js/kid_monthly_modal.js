/* eslint-disable no-console */
/**
 * kid_monthly_modal.js
 * 子ども向け「1か月分まとめて登録」モーダルの操作
 *
 * 依存: Bootstrap 5, kid_monthly_modal.php が含むHTML要素
 * 送信先: TReservationInfo::bulkAddSubmit (reservation_type=personal)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modalEl       = document.getElementById('kidMonthlyModal');
        var roomSelect    = document.getElementById('kidMonthlyRoomSelect');
        var kidRoomSelect = document.getElementById('kid-room-select'); // メイン画面の部屋選択
        var submitBtn     = document.getElementById('kidMonthlySubmitBtn');
        var spinner       = document.getElementById('kidMonthlySpinner');
        var summaryEl     = document.getElementById('kidMonthlySummary');
        var calGrid       = document.getElementById('kidMonthlyCalGrid');
        var checkAllBtn   = document.getElementById('kidMonthlyCheckAll');
        var uncheckAllBtn = document.getElementById('kidMonthlyUncheckAll');
        var bulkUrl       = (document.getElementById('kidMonthlyBulkSubmitUrl') || {}).value || '';
        var csrfToken     = (document.getElementById('kidMonthlyCsrfToken') || {}).value || '';

        if (!modalEl) return;

        // 食事アイコンマップ
        var mealEmoji = { 1: '☀️', 2: '🌞', 3: '🌙', 4: '🍱' };
        var mealLabel = { 1: '朝食', 2: '昼食', 3: '夕食', 4: '弁当' };

        // ──────────────────────────────────────────
        // モーダルが開いたとき: メイン画面の部屋をコピー
        // ──────────────────────────────────────────
        modalEl.addEventListener('show.bs.modal', function () {
            if (kidRoomSelect && roomSelect) {
                roomSelect.value = kidRoomSelect.value || '';
            }
            syncMealIcons();
            updateSummary();
        });

        // ──────────────────────────────────────────
        // すべて選択 / すべて解除
        // ──────────────────────────────────────────
        if (checkAllBtn) {
            checkAllBtn.addEventListener('click', function () {
                getDateCheckboxes().forEach(function (chk) {
                    chk.checked = true;
                    updateCellStyle(chk);
                });
                syncMealIcons();
                updateSummary();
            });
        }
        if (uncheckAllBtn) {
            uncheckAllBtn.addEventListener('click', function () {
                getDateCheckboxes().forEach(function (chk) {
                    chk.checked = false;
                    updateCellStyle(chk);
                });
                syncMealIcons();
                updateSummary();
            });
        }

        // ──────────────────────────────────────────
        // 日付セル クリックトグル
        // ──────────────────────────────────────────
        if (calGrid) {
            calGrid.addEventListener('click', function (e) {
                var label = e.target.closest('label.kid-monthly-cell');
                if (!label) return;
                // label のクリックで checkbox が自動トグルされる
                // → nextTick で状態を読む
                setTimeout(function () {
                    var chk = label.querySelector('input.kid-monthly-date-chk');
                    if (chk) updateCellStyle(chk);
                    syncMealIcons();
                    updateSummary();
                }, 0);
            });
        }

        // ──────────────────────────────────────────
        // 食事チェックボックス 変更
        // ──────────────────────────────────────────
        document.querySelectorAll('.kid-monthly-meal-chk').forEach(function (chk) {
            chk.addEventListener('change', function () {
                validateMealConflict();
                syncMealIcons();
                updateSummary();
            });
        });

        // ──────────────────────────────────────────
        // 部屋選択変更
        // ──────────────────────────────────────────
        if (roomSelect) {
            roomSelect.addEventListener('change', updateSummary);
        }

        // ──────────────────────────────────────────
        // 送信ボタン
        // ──────────────────────────────────────────
        if (submitBtn) {
            submitBtn.addEventListener('click', doSubmit);
        }

        // ══════════════════════════════════════════
        // ヘルパー関数
        // ══════════════════════════════════════════

        function getDateCheckboxes() {
            return Array.from(document.querySelectorAll('.kid-monthly-date-chk'));
        }

        function getSelectedDates() {
            return getDateCheckboxes()
                .filter(function (c) { return c.checked; })
                .map(function (c) { return c.value; });
        }

        function getSelectedMealTypes() {
            return Array.from(document.querySelectorAll('.kid-monthly-meal-chk'))
                .filter(function (c) { return c.checked; })
                .map(function (c) { return parseInt(c.value, 10); });
        }

        function updateCellStyle(chk) {
            var label = chk.closest('label.kid-monthly-cell');
            if (!label) return;
            if (chk.checked) {
                label.classList.add('cell-selected');
            } else {
                label.classList.remove('cell-selected');
            }
        }

        /**
         * 昼食(2)と弁当(4)の同時選択を検証して警告表示
         * @returns {boolean} true = 問題なし
         */
        function validateMealConflict() {
            var meals = getSelectedMealTypes();
            var hasLunch = meals.indexOf(2) !== -1;
            var hasBento = meals.indexOf(4) !== -1;
            var errEl = document.getElementById('kidMonthlyLunchBentoError');
            if (hasLunch && hasBento) {
                if (errEl) errEl.style.display = '';
                return false;
            }
            if (errEl) errEl.style.display = 'none';
            return true;
        }

        /** 選択済み日付セルに食事アイコンを描画 */
        function syncMealIcons() {
            var meals = getSelectedMealTypes();
            var icons = meals.map(function (mt) { return mealEmoji[mt] || ''; }).join('');
            document.querySelectorAll('.kid-monthly-meal-icons').forEach(function (span) {
                var dateKey = span.getAttribute('data-date');
                var chk = document.getElementById('kidMonthlyDate_' + dateKey);
                span.textContent = (chk && chk.checked) ? icons : '';
            });
        }

        /** サマリー表示 + 送信ボタン有効/無効 */
        function updateSummary() {
            var room   = roomSelect ? roomSelect.value : '';
            var dates  = getSelectedDates();
            var meals  = getSelectedMealTypes();
            var mealOk = validateMealConflict();

            var ok = room && dates.length > 0 && meals.length > 0 && mealOk;

            if (submitBtn) submitBtn.disabled = !ok;

            if (!summaryEl) return;
            if (!room) {
                summaryEl.textContent = '部屋を選択してください。';
                return;
            }
            if (meals.length === 0) {
                summaryEl.textContent = '食事の種類を1つ以上選んでください。';
                return;
            }
            if (!mealOk) {
                summaryEl.textContent = '昼食と弁当は同時に選べません。';
                return;
            }
            if (dates.length === 0) {
                summaryEl.textContent = '日付を1日以上選んでください。';
                return;
            }
            var mealNames = meals.map(function (mt) { return mealLabel[mt]; }).join('・');
            summaryEl.textContent =
                dates.length + '日分 ×「' + mealNames + '」を登録します。';
        }

        // ──────────────────────────────────────────
        // 送信処理
        // ──────────────────────────────────────────
        function doSubmit() {
            // バリデーション
            var room  = roomSelect ? roomSelect.value : '';
            var dates = getSelectedDates();
            var meals = getSelectedMealTypes();

            var dateErrEl = document.getElementById('kidMonthlyDateError');
            var mealErrEl = document.getElementById('kidMonthlyMealError');

            if (dateErrEl) dateErrEl.style.display = (dates.length === 0) ? '' : 'none';
            if (mealErrEl) mealErrEl.style.display  = (meals.length === 0) ? '' : 'none';

            if (!room || dates.length === 0 || meals.length === 0) return;
            if (!validateMealConflict()) return;

            // フォームデータ組み立て（bulkAddSubmit の personal 型に合わせる）
            var formData = new FormData();
            formData.append('_csrfToken', csrfToken);
            formData.append('reservation_type', 'personal');

            // dates[YYYY-MM-DD] = "1"
            dates.forEach(function (d) {
                formData.append('dates[' + d + ']', '1');
            });

            // meals[mealType][roomId] = "1"
            meals.forEach(function (mt) {
                formData.append('meals[' + mt + '][' + room + ']', '1');
            });

            // UI: 送信中
            if (spinner) spinner.classList.remove('d-none');
            if (submitBtn) submitBtn.disabled = true;

            fetch(bulkUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData,
                credentials: 'same-origin',
            })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.status === 'success' || json.ok) {
                    showResult(true, json.message || 'まとめ登録が完了しました。');
                    // メイン画面を1秒後にリロード
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    showResult(false, json.message || '登録に失敗しました。再度お試しください。');
                }
            })
            .catch(function (err) {
                console.error('kid_monthly_modal: fetch error', err);
                showResult(false, '通信エラーが発生しました。ページを更新してから再試行してください。');
            })
            .finally(function () {
                if (spinner) spinner.classList.add('d-none');
                if (submitBtn) submitBtn.disabled = false;
            });
        }

        /** 結果をサマリー欄に表示 */
        function showResult(ok, message) {
            if (!summaryEl) return;
            summaryEl.className = ok
                ? 'alert alert-success py-2 small mt-2'
                : 'alert alert-danger py-2 small mt-2';
            summaryEl.textContent = message;
        }

        // ──────────────────────────────────────────
        // 初期化: 既存セルのスタイルを合わせる
        // ──────────────────────────────────────────
        getDateCheckboxes().forEach(function (chk) { updateCellStyle(chk); });
        updateSummary();
    });
}());