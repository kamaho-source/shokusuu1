/**
 * 食数カレンダー ユーザー一覧モーダルモジュール
 * カレンダーの食数イベントクリックで開くモーダルに、ユーザーごとの
 * アコーディオン展開＋インライン予約編集機能を提供する。
 */
(function () {
    'use strict';

    var MEAL_LABELS = { 1: '朝食', 2: '昼食', 3: '夕食', 4: '弁当' };
    var MEAL_SHORT  = { 1: '朝', 2: '昼', 3: '夕', 4: '弁' };
    var MEAL_COLORS = { 1: '#17a2b8', 2: '#28a745', 3: '#6610f2', 4: '#fd7e14' };

    function getUsersByRoomUrl(roomId, date) {
        var tpl = window.GET_USERS_BY_ROOM_TPL || '';
        if (!tpl) return null;
        return tpl.replace('__RID__', encodeURIComponent(roomId)) + '?date=' + encodeURIComponent(date);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildBadges(meals) {
        return [1, 2, 3, 4].map(function (mt) {
            var active = !!meals[mt];
            return '<span class="meal-cal-badge' + (active ? ' active' : '') + '" style="background:' + (active ? MEAL_COLORS[mt] : '#adb5bd') + '"'
                + ' aria-label="' + escHtml(MEAL_LABELS[mt]) + (active ? '：あり' : '：なし') + '">'
                + escHtml(MEAL_SHORT[mt]) + (active ? '✓' : '－') + '</span>';
        }).join('');
    }

    function buildUserRow(u, date, roomId) {
        var uid    = u.id;
        var meals  = { 1: !!u.morning, 2: !!u.noon, 3: !!u.night, 4: !!u.bento };
        var bodyId = 'mcu-body-' + uid;

        var html = '<div class="meal-cal-acc-item" data-user-id="' + uid + '">';

        // クリッカブルヘッダー行
        html += '<button type="button" class="meal-cal-acc-header" aria-expanded="false" aria-controls="' + bodyId + '">'
            + '<span class="meal-cal-acc-name">' + escHtml(u.name || '') + '</span>'
            + '<span class="meal-cal-acc-badges">' + buildBadges(meals) + '</span>'
            + '<span class="meal-cal-acc-arrow" aria-hidden="true">▶</span>'
            + '</button>';

        // 展開エリア
        html += '<div class="meal-cal-acc-body" id="' + bodyId + '" hidden>';
        html += '<div class="meal-cal-edit-row">';
        [1, 2, 3, 4].forEach(function (mt) {
            html += '<label class="meal-cal-check-label">'
                + '<input type="checkbox" class="form-check-input meal-cal-check" data-meal="' + mt + '"'
                + (meals[mt] ? ' checked' : '') + '>'
                + '<span class="meal-cal-check-text" style="color:' + MEAL_COLORS[mt] + '">' + escHtml(MEAL_LABELS[mt]) + '</span>'
                + '</label>';
        });
        html += '<button type="button" class="btn btn-sm btn-primary meal-cal-save-btn"'
            + ' data-uid="' + uid + '" data-date="' + escHtml(date) + '" data-room="' + escHtml(String(roomId || '')) + '">保存</button>';
        html += '<span class="meal-cal-save-status"></span>';
        html += '</div>';

        html += '</div></div>';
        return html;
    }

    function buildModalContent(usersByRoom, date, roomId) {
        if (!usersByRoom.length) {
            return '<p class="text-muted py-2"><i class="bi bi-person-x me-1"></i>この日の予約者はいません。</p>';
        }
        var html = '<div class="meal-cal-accordion">';
        usersByRoom.forEach(function (u) {
            html += buildUserRow(u, date, roomId);
        });
        html += '</div>';
        return html;
    }

    function attachHandlers(contentEl) {
        // アコーディオン開閉
        contentEl.querySelectorAll('.meal-cal-acc-header').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', String(!expanded));
                var body = document.getElementById(btn.getAttribute('aria-controls'));
                if (body) body.hidden = expanded;
                var arrow = btn.querySelector('.meal-cal-acc-arrow');
                if (arrow) arrow.textContent = expanded ? '▶' : '▼';
            });
        });

        // 保存ボタン
        contentEl.querySelectorAll('.meal-cal-save-btn').forEach(function (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var item     = saveBtn.closest('.meal-cal-acc-item');
                var uid      = saveBtn.dataset.uid;
                var d        = saveBtn.dataset.date;
                var rid      = saveBtn.dataset.room;
                var statusEl = item ? item.querySelector('.meal-cal-save-status') : null;
                var checks   = item ? Array.from(item.querySelectorAll('.meal-cal-check')) : [];

                if (!rid) {
                    if (statusEl) { statusEl.textContent = '部屋が特定できません'; statusEl.className = 'meal-cal-save-status text-danger'; }
                    return;
                }

                var formData = new FormData();
                formData.append('i_id_room', rid);
                checks.forEach(function (c) {
                    formData.append('day_users[' + d + '][' + uid + '][' + c.dataset.meal + ']', c.checked ? '1' : '0');
                });

                saveBtn.disabled = true;
                if (statusEl) { statusEl.textContent = '保存中...'; statusEl.className = 'meal-cal-save-status text-muted'; }

                fetch((window.__BASE_PATH || '') + '/TReservationInfo/bulk-change-edit-submit', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-Token': window.__csrfToken || '' },
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        saveBtn.disabled = false;
                        var ok = data.ok === true || data.status === 'success';
                        if (statusEl) {
                            statusEl.textContent = ok ? '✓ 保存しました' : ('✗ ' + (data.message || '保存失敗'));
                            statusEl.className = 'meal-cal-save-status ' + (ok ? 'text-success' : 'text-danger');
                            setTimeout(function () { statusEl.textContent = ''; }, 2500);
                        }
                        // 保存成功時にヘッダーバッジを更新
                        if (ok && item) {
                            var badges = Array.from(item.querySelectorAll('.meal-cal-badge'));
                            checks.forEach(function (c, i) {
                                var mt = parseInt(c.dataset.meal, 10);
                                var active = c.checked;
                                if (badges[i]) {
                                    badges[i].style.background = active ? MEAL_COLORS[mt] : '#adb5bd';
                                    badges[i].className = 'meal-cal-badge' + (active ? ' active' : '');
                                    badges[i].textContent = MEAL_SHORT[mt] + (active ? '✓' : '－');
                                }
                            });
                        }
                    })
                    .catch(function () {
                        saveBtn.disabled = false;
                        if (statusEl) { statusEl.textContent = '✗ 通信エラー'; statusEl.className = 'meal-cal-save-status text-danger'; }
                    });
            });
        });
    }

    function openMealCalUserModal(date, roomId) {
        var modalEl   = document.getElementById('mealCalUserModal');
        if (!modalEl) return;
        var labelEl   = document.getElementById('mealCalModalDateLabel');
        var loadingEl = document.getElementById('mealCalModalLoading');
        var contentEl = document.getElementById('mealCalModalContent');

        if (labelEl)   labelEl.textContent = date + ' の食数詳細';
        if (loadingEl) loadingEl.classList.remove('d-none');
        if (contentEl) { contentEl.classList.add('d-none'); contentEl.innerHTML = ''; }

        var bsModal = window.bootstrap && window.bootstrap.Modal.getOrCreateInstance(modalEl);
        if (bsModal) bsModal.show();

        if (roomId == null || roomId === '') {
            if (loadingEl) loadingEl.classList.add('d-none');
            if (contentEl) {
                contentEl.innerHTML = '<p class="text-muted"><i class="bi bi-info-circle me-1"></i>部屋フィルタを選択すると利用者一覧を確認できます。</p>';
                contentEl.classList.remove('d-none');
            }
            return;
        }

        var url = getUsersByRoomUrl(roomId, date);
        if (!url) return;

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = (json && json.ok && json.data) ? json.data : json;
                var usersByRoom = data.usersByRoom || data.users || [];
                var html = buildModalContent(usersByRoom, date, roomId);
                if (contentEl) {
                    contentEl.innerHTML = html;
                    contentEl.classList.remove('d-none');
                    attachHandlers(contentEl);
                }
                if (loadingEl) loadingEl.classList.add('d-none');
            })
            .catch(function (err) {
                if (loadingEl) loadingEl.classList.add('d-none');
                if (contentEl) {
                    contentEl.innerHTML = '<p class="text-danger small">データの取得に失敗しました。</p>';
                    contentEl.classList.remove('d-none');
                }
                console.error('[mealCal] fetch error', err);
            });
    }

    window.openMealCalUserModal = openMealCalUserModal;
})();
