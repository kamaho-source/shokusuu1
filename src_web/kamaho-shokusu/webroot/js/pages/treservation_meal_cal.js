/**
 * 食数カレンダー ユーザー一覧モーダルモジュール
 * FullCalendar の eventClick から openMealCalUserModal を呼び出せるよう公開する。
 */
(function () {
    'use strict';

    var MEAL_LABELS = { 1: '朝食', 2: '昼食', 3: '夕食', 4: '弁当' };
    var MEAL_COLORS = { 1: '#17a2b8', 2: '#28a745', 3: '#6610f2', 4: '#fd7e14' };

    function getUsersByRoomUrl(roomId, date) {
        var tpl = window.GET_USERS_BY_ROOM_TPL || '';
        if (!tpl) return null;
        var url = tpl.replace('__RID__', encodeURIComponent(roomId));
        return url + '?date=' + encodeURIComponent(date);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function canEditReservation() {
        var ui = window.__USER_INFO || {};
        return !!(ui.isAdmin || ui.isStaff);
    }

    function buildModalContent(usersByRoom, date, canEdit) {
        var html = '';
        var mealKeys = { 1: 'morning', 2: 'noon', 3: 'night', 4: 'bento' };
        [1, 2, 3, 4].forEach(function (mt) {
            var key = mealKeys[mt];
            var users = usersByRoom.filter(function (u) { return !!u[key]; });
            var color = MEAL_COLORS[mt];
            html += '<div class="meal-cal-modal-section">';
            html += '<div class="meal-cal-modal-section-title" style="border-color:' + color + ';color:' + color + ';">'
                + escHtml(MEAL_LABELS[mt]) + '（' + users.length + '名）</div>';
            if (users.length === 0) {
                html += '<p class="text-muted small mb-0">なし</p>';
            } else {
                html += '<div class="meal-cal-user-list">';
                users.forEach(function (u) {
                    if (canEdit) {
                        html += '<button type="button" class="meal-cal-user-chip meal-cal-user-chip--editable" data-date="'
                            + escHtml(date) + '">' + escHtml(u.name || '') + '</button>';
                    } else {
                        html += '<span class="meal-cal-user-chip">' + escHtml(u.name || '') + '</span>';
                    }
                });
                html += '</div>';
            }
            html += '</div>';
        });
        return html;
    }

    function openMealCalUserModal(date, roomId) {
        var modalEl   = document.getElementById('mealCalUserModal');
        if (!modalEl) return;
        var labelEl   = document.getElementById('mealCalModalDateLabel');
        var loadingEl = document.getElementById('mealCalModalLoading');
        var contentEl = document.getElementById('mealCalModalContent');
        var editBtn   = document.getElementById('mealCalModalEditBtn');
        if (labelEl)   labelEl.textContent = date + ' の食数詳細';
        if (loadingEl) loadingEl.classList.remove('d-none');
        if (contentEl) { contentEl.classList.add('d-none'); contentEl.innerHTML = ''; }

        var canEdit = canEditReservation();

        if (editBtn) {
            if (canEdit) {
                editBtn.classList.remove('d-none');
                editBtn.onclick = function () {
                    var bsModalInstance = window.bootstrap && window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModalInstance) bsModalInstance.hide();
                    if (typeof window.quickOpenDayModal === 'function') {
                        window.quickOpenDayModal(date);
                    }
                };
            } else {
                editBtn.classList.add('d-none');
                editBtn.onclick = null;
            }
        }

        var bsModal = window.bootstrap && window.bootstrap.Modal.getOrCreateInstance(modalEl);
        if (bsModal) bsModal.show();

        if (roomId == null || roomId === '') {
            if (loadingEl) loadingEl.classList.add('d-none');
            if (contentEl) {
                contentEl.innerHTML = '<p class="text-muted"><i class="bi bi-info-circle"></i> 部屋フィルタを選択すると利用者一覧を確認できます。</p>';
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
                var html = buildModalContent(usersByRoom, date, canEdit);
                if (contentEl) {
                    contentEl.innerHTML = html;
                    contentEl.classList.remove('d-none');
                    if (canEdit) {
                        contentEl.querySelectorAll('.meal-cal-user-chip--editable').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var bsModalInstance = window.bootstrap && window.bootstrap.Modal.getInstance(modalEl);
                                if (bsModalInstance) bsModalInstance.hide();
                                var d = btn.dataset.date || date;
                                if (typeof window.quickOpenDayModal === 'function') {
                                    window.quickOpenDayModal(d);
                                }
                            });
                        });
                    }
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
