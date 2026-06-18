/**
 * 昼食・弁当排他制御モジュール
 * enforceMealLimit / setupLunchBentoPair / applyLunchBentoExclusion を提供する。
 * treservation_toast.js の後にロードすること。
 */
(function () {
    // 予約チェックボックスのセレクタ
    var mealSelectors = [
        'input[type="checkbox"][name*="breakfast"]',
        'input[type="checkbox"][name*="lunch"]',
        'input[type="checkbox"][name*="dinner"]',
        'input[type="checkbox"][name*="bento"]'
    ];

    function enforceMealLimit(scope) {
        var root = scope || document;
        var cbs = mealSelectors.map(function (sel) {
            return Array.from(root.querySelectorAll(sel));
        }).reduce(function (acc, arr) { return acc.concat(arr); }, []);
        var checked = cbs.filter(function (cb) { return cb.checked; });

        if (checked.length >= 3) {
            cbs.forEach(function (cb) {
                if (!cb.checked) {
                    cb.disabled = true;
                    cb.title = '最大3つまで選択できます';
                }
            });
        } else {
            cbs.forEach(function (cb) {
                cb.disabled = false;
                cb.title = '';
            });
        }

        var lunchCbs = Array.from(root.querySelectorAll(
            'input[type="checkbox"][name*="lunch"],input[type="checkbox"][name$="[lunch]"]'
        ));
        var bentoCbs = Array.from(root.querySelectorAll(
            'input[type="checkbox"][name*="bento"],input[type="checkbox"][name$="[bento]"]'
        ));

        lunchCbs.forEach(function (lunchCb, idx) {
            var bentoCb = null;
            if (lunchCb.name && lunchCb.name.includes('reservation')) {
                bentoCb = root.querySelector('input[type="checkbox"][name="reservation[弁当]"]');
            } else if (lunchCb.name && lunchCb.name.startsWith('users[')) {
                var userId = lunchCb.name.match(/^users\[(\d+)\]\[lunch\]$/);
                if (userId) {
                    bentoCb = root.querySelector('input[type="checkbox"][name="users[' + userId[1] + '][bento]"]');
                }
            }
            if (!bentoCb && bentoCbs[idx]) bentoCb = bentoCbs[idx];

            if (lunchCb.checked) {
                if (bentoCb) {
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に予約できません';
                }
            } else if (bentoCb && bentoCb.checked) {
                lunchCb.disabled = true;
                lunchCb.title = '昼食と弁当は同時に予約できません';
            } else {
                lunchCb.disabled = false;
                lunchCb.title = '';
                if (bentoCb) {
                    bentoCb.disabled = false;
                    bentoCb.title = '';
                }
            }
        });
    }

    // 既存チェックボックスにイベントを登録（スクリプト読み込み時に即時実行）
    mealSelectors.forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (cb) {
            cb.addEventListener('change', function () { enforceMealLimit(cb.closest('form')); });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(function (f) { enforceMealLimit(f); });
    });

    // -------------------------------------------------------
    // 個人/集団予約フォームでのペアワイズ排他制御
    // -------------------------------------------------------
    function setupLunchBentoPair(lunchSelector, bentoSelector) {
        var lunchCbs = document.querySelectorAll(lunchSelector);
        var bentoCbs = document.querySelectorAll(bentoSelector);

        Array.prototype.forEach.call(lunchCbs, function (lunchCb, idx) {
            var bentoCb = bentoCbs[idx];
            if (!lunchCb || !bentoCb) return;
            if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

            if (lunchCb.checked) {
                bentoCb.disabled = true;
                bentoCb.title = '昼食と弁当は同時に選択できません';
            } else if (bentoCb.checked) {
                lunchCb.disabled = true;
                lunchCb.title = '昼食と弁当は同時に選択できません';
            }

            lunchCb.addEventListener('change', function () {
                if (lunchCb.checked) {
                    bentoCb.checked = false;
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に選択できません';
                } else {
                    bentoCb.disabled = false;
                    bentoCb.title = '';
                }
            });

            bentoCb.addEventListener('change', function () {
                if (bentoCb.checked) {
                    lunchCb.checked = false;
                    lunchCb.disabled = true;
                    lunchCb.title = '昼食と弁当は同時に選択できません';
                } else {
                    lunchCb.disabled = false;
                    lunchCb.title = '';
                }
            });

            lunchCb.dataset._paired = '1';
            bentoCb.dataset._paired = '1';
        });
    }

    // -------------------------------------------------------
    // モーダル描画後の排他制御（スコープ対応）
    // -------------------------------------------------------
    function applyLunchBentoExclusion(scope) {
        var root = scope || document;

        // 個人予約（add フォーム）: meals[2][roomId]=昼食, meals[4][roomId]=弁当
        var addLunchCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name^="meals[2]"]'));
        var addBentoCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name^="meals[4]"]'));
        if (addLunchCbs.length > 0 || addBentoCbs.length > 0) {
            addLunchCbs.forEach(function (lunchCb) {
                if (lunchCb.dataset._mealPaired) return;
                lunchCb.addEventListener('change', function () {
                    if (lunchCb.checked && !lunchCb.disabled) {
                        root.querySelectorAll('input[type="checkbox"][name^="meals[4]"]').forEach(function (b) {
                            if (!b.disabled) b.checked = false;
                        });
                    }
                });
                lunchCb.dataset._mealPaired = '1';
            });
            addBentoCbs.forEach(function (bentoCb) {
                if (bentoCb.dataset._mealPaired) return;
                bentoCb.addEventListener('change', function () {
                    if (bentoCb.checked && !bentoCb.disabled) {
                        root.querySelectorAll('input[type="checkbox"][name^="meals[2]"]').forEach(function (l) {
                            if (!l.disabled) l.checked = false;
                        });
                    }
                });
                bentoCb.dataset._mealPaired = '1';
            });
            var anyLunch = addLunchCbs.some(function (cb) { return cb.checked; });
            var anyBento = addBentoCbs.some(function (cb) { return cb.checked; });
            if (anyLunch && anyBento) {
                addBentoCbs.forEach(function (cb) { cb.checked = false; });
            }
        }

        // 集団予約（利用者別）- users[userId][2] と users[userId][4]
        var groupRows = root.querySelectorAll('#user-checkboxes tr, tbody tr');
        groupRows.forEach(function (tr) {
            var lunchCb = tr.querySelector('input[type="checkbox"][name$="[2]"]');
            var bentoCb = tr.querySelector('input[type="checkbox"][name$="[4]"]');
            if (!lunchCb || !bentoCb) return;
            if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
            if (lunchCb.checked && bentoCb.checked) bentoCb.checked = false;
            lunchCb.addEventListener('change', function () {
                if (lunchCb.checked && !lunchCb.disabled) {
                    if (bentoCb && !bentoCb.disabled) {
                        bentoCb.checked = false;
                        bentoCb.dispatchEvent(new Event('change'));
                    }
                }
            });
            bentoCb.addEventListener('change', function () {
                if (bentoCb.checked && !bentoCb.disabled) {
                    if (lunchCb && !lunchCb.disabled) {
                        lunchCb.checked = false;
                        lunchCb.dispatchEvent(new Event('change'));
                    }
                }
            });
            lunchCb.dataset._paired = '1';
            bentoCb.dataset._paired = '1';
        });

        // 直前編集モーダル（change_edit.php）: data-reservation-type 属性使用
        var changeEditRows = root.querySelectorAll('#ce-tbody tr[data-user-id], tbody tr[data-user-id]');
        changeEditRows.forEach(function (tr) {
            var lunchCb = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
            var bentoCb = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
            if (!lunchCb || !bentoCb) return;
            if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
            if (lunchCb.checked && bentoCb.checked && !lunchCb.disabled && !bentoCb.disabled) {
                bentoCb.checked = false;
            }
            lunchCb.addEventListener('change', function () {
                if (lunchCb.checked && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                    if (bentoCb && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                        bentoCb.checked = false;
                        bentoCb.dispatchEvent(new Event('change'));
                    }
                }
            });
            bentoCb.addEventListener('change', function () {
                if (bentoCb.checked && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                    if (lunchCb && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                        lunchCb.checked = false;
                        lunchCb.dispatchEvent(new Event('change'));
                    }
                }
            });
            lunchCb.dataset._paired = '1';
            bentoCb.dataset._paired = '1';
        });
    }

    window.applyLunchBentoExclusion = applyLunchBentoExclusion;
    window.setupLunchBentoPair = setupLunchBentoPair;

    // ページロード時に全体へ適用
    document.addEventListener('DOMContentLoaded', function () {
        setupLunchBentoPair(
            'input[type="checkbox"][name*="lunch"]',
            'input[type="checkbox"][name*="bento"]'
        );
        setupLunchBentoPair(
            'input[type="checkbox"][name$="[lunch]"]',
            'input[type="checkbox"][name$="[bento]"]'
        );
        applyLunchBentoExclusion(document);
    });

    // モーダル表示時にも適用
    document.addEventListener('shown.bs.modal', function (ev) {
        var modal = ev.target;
        if (modal) {
            setTimeout(function () { applyLunchBentoExclusion(modal); }, 100);
        }
    });
})();
