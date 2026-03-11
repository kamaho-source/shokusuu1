/* eslint-disable no-console */
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
    const basePath = window.__BASE_PATH || '/kamaho-shokusu';
    const form = document.getElementById('reservation-form');
    const roomSelect = document.getElementById('room-select');
    const roomHidden = document.getElementById('i_id_room');
    const userRows = document.getElementById('user-rows');
    const selectionInputs = document.getElementById('selection-inputs');
    const copyBtn = document.getElementById('copy-day-btn');
    const saveBtn = document.getElementById('save-btn');
    const dirtyBadge = document.getElementById('dirty-badge');
    const weekTabLinks = Array.from(document.querySelectorAll('.week-tabs a'));
    const pagerPrev = document.getElementById('pager-prev');
    const pagerNext = document.getElementById('pager-next');
    const pagerInfo = document.getElementById('pager-info');

    const dayButtons = Array.from(document.querySelectorAll('.tab-day .btn'));
    const firstEnabled = dayButtons.find((b) => b.dataset.disabled !== '1');
    let activeDate = firstEnabled?.dataset.date || dayButtons[0]?.dataset.date || window.__SELECTED_DATE || '';
    let activeLabel = firstEnabled?.textContent?.trim() || dayButtons[0]?.textContent?.trim() || '';

    // state: selectionsByRoom[roomId][date][userId][mealType] = true
    const selectionsByRoom = {};
    // locks: lockedByRoom[roomId][date][userId][mealType] = true (既存予約)
    const lockedByRoom = {};
    // server reservations: serverReservedByRoom[roomId][date][userId][mealType] = true
    const serverReservedByRoom = {};
    // other room locks: otherRoomLockedByRoom[roomId][date][userId][mealType] = true
    const otherRoomLockedByRoom = {};
    // users cache per room
    const usersByRoom = {};
    const userLevelsByRoom = {};
    const roomSnapshots = {};

    let currentPage = 1;
    let pageLimit = 100;
    let totalUsers = 0;

    const mealTypes = [1, 2, 3, 4];
    const mealLabels = {1: 'morning', 2: 'noon', 3: 'night', 4: 'bento'};
    const bulkToggles = Array.from(document.querySelectorAll('.bulk-toggle'));
    const userKey = window.__LOGIN_USER_ID ?? 'anon';
    const baseWeekKey = window.__BASE_WEEK ?? '';
    const storageKey = `bulk_add_form_state_v2:${userKey}`;
    const uiStateKey = `bulk_add_form_ui_v1:${userKey}`;
    let isDirty = false;
    let isSubmitting = false;
    // 職員/子供フィルタ: 'all' | 'staff' | 'child'
    let userFilter = 'all';

    // フィルタセレクトボックスのイベント登録
    const userFilterSelect = document.getElementById('user-filter-select');
    if (userFilterSelect) {
        userFilterSelect.addEventListener('change', () => {
            userFilter = userFilterSelect.value || 'all';
            applySearchFilter();
        });
    }

    function getRoomId() {
        return roomSelect?.value || '';
    }

    function ensureState(roomId, date) {
        if (!roomId) return;
        selectionsByRoom[roomId] = selectionsByRoom[roomId] || {};
        lockedByRoom[roomId] = lockedByRoom[roomId] || {};
        otherRoomLockedByRoom[roomId] = otherRoomLockedByRoom[roomId] || {};
        serverReservedByRoom[roomId] = serverReservedByRoom[roomId] || {};
        roomSnapshots[roomId] = roomSnapshots[roomId] || {};
        selectionsByRoom[roomId][date] = selectionsByRoom[roomId][date] || {};
        lockedByRoom[roomId][date] = lockedByRoom[roomId][date] || {};
        otherRoomLockedByRoom[roomId][date] = otherRoomLockedByRoom[roomId][date] || {};
        serverReservedByRoom[roomId][date] = serverReservedByRoom[roomId][date] || {};
    }

    function setActiveDate(date, label) {
        activeDate = date;
        activeLabel = label || '';
        document.getElementById('active-date-label').textContent = activeLabel;
        dayButtons.forEach((b) => b.classList.toggle('active', b.dataset.date === activeDate));
    }

    function markDirty() {
        isDirty = true;
        if (dirtyBadge) dirtyBadge.style.display = 'inline-flex';
    }

    function clearDirty() {
        isDirty = false;
        if (dirtyBadge) dirtyBadge.style.display = 'none';
    }

    function setSubmitting(submitting) {
        isSubmitting = !!submitting;
        if (!saveBtn) return;
        saveBtn.disabled = isSubmitting;
        saveBtn.textContent = isSubmitting ? '保存中...' : '保存する';
    }

    function showNotice(message, type) {
        const tone = type || 'info';
        if (window.pageToast) {
            window.pageToast(message, tone === 'error' ? 'danger' : tone);
            return;
        }
        let container = document.getElementById('bulk-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'bulk-toast-container';
            container.style.position = 'fixed';
            container.style.top = '12px';
            container.style.right = '12px';
            container.style.zIndex = '9999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '8px';
            document.body.appendChild(container);
        }
        const colorMap = {
            success: '#198754',
            error: '#dc3545',
            warning: '#fd7e14',
            info: '#0d6efd',
        };
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.background = colorMap[tone] || colorMap.info;
        toast.style.color = '#fff';
        toast.style.padding = '10px 12px';
        toast.style.borderRadius = '8px';
        toast.style.boxShadow = '0 6px 14px rgba(0, 0, 0, 0.18)';
        toast.style.fontSize = '0.92rem';
        container.appendChild(toast);
        setTimeout(() => {
            toast.remove();
        }, 2800);
    }

    function loadState() {
        try {
            const raw = sessionStorage.getItem(storageKey) || localStorage.getItem(storageKey);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (data && typeof data === 'object') {
                Object.assign(selectionsByRoom, data.selectionsByRoom || {});
            }
        } catch (e) {
            console.warn('failed to load state', e);
        }
    }

    function saveState() {
        try {
            const payload = JSON.stringify({ selectionsByRoom });
            sessionStorage.setItem(storageKey, payload);
            localStorage.setItem(storageKey, payload);
        } catch (e) {
            console.warn('failed to save state', e);
        }
    }

    let saveTimer = null;
    function scheduleSaveState() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveState, 200);
    }

    let countTimer = null;
    function scheduleUpdateCounts() {
        if (countTimer) clearTimeout(countTimer);
        countTimer = setTimeout(updateCounts, 100);
    }

    function clearStateStorage() {
        try {
            sessionStorage.removeItem(storageKey);
            localStorage.removeItem(storageKey);
            sessionStorage.removeItem(uiStateKey);
            localStorage.removeItem(uiStateKey);
        } catch (e) {
            console.warn('failed to clear state', e);
        }
    }

    function loadUiState() {
        try {
            const raw = sessionStorage.getItem(uiStateKey) || localStorage.getItem(uiStateKey);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (!data || typeof data !== 'object') return;
            if (!window.__ROOM_ID && data.roomId && roomSelect) {
                roomSelect.value = data.roomId;
                roomHidden.value = data.roomId;
            }
            if (data.activeDate) {
                const exists = dayButtons.find((b) => b.dataset.date === data.activeDate && b.dataset.disabled !== '1');
                if (exists) {
                    setActiveDate(data.activeDate, exists.textContent.trim());
                }
            }
            // 検索テキストを復元
            if (data.searchText != null) {
                const searchInp = document.querySelector('.excel-header input[type="search"]');
                if (searchInp) searchInp.value = data.searchText;
            }
            // 職員/子供フィルタを復元
            if (data.userFilter && userFilterSelect) {
                userFilterSelect.value = data.userFilter;
                userFilter = data.userFilter;
            }
        } catch (e) {
            console.warn('failed to load ui state', e);
        }
    }

    function saveUiState() {
        try {
            const searchInp = document.querySelector('.excel-header input[type="search"]');
            const payload = JSON.stringify({
                baseWeek: baseWeekKey,
                roomId: getRoomId(),
                activeDate,
                searchText: searchInp?.value ?? '',
                userFilter,
            });
            sessionStorage.setItem(uiStateKey, payload);
            localStorage.setItem(uiStateKey, payload);
        } catch (e) {
            console.warn('failed to save ui state', e);
        }
    }

    function updateRoomValidation() {
        const help = document.getElementById('room-select-help');
        if (!roomSelect) return;
        if (!roomSelect.value) {
            roomSelect.classList.add('is-invalid');
            if (help) help.style.display = 'block';
            roomSelect.focus();
        } else {
            roomSelect.classList.remove('is-invalid');
            if (help) help.style.display = 'none';
        }
    }

    function updateWeekTabRoom(roomId) {
        if (!weekTabLinks.length) return;
        weekTabLinks.forEach((a) => {
            const href = a.getAttribute('href');
            if (!href || href === '#' || a.classList.contains('disabled')) return;
            const url = new URL(href, window.location.origin);
            if (roomId) {
                url.searchParams.set('room_id', roomId);
            } else {
                url.searchParams.delete('room_id');
            }
            a.setAttribute('href', url.pathname + url.search);
        });
    }

    function updateCounts() {
        const roomId = getRoomId();
        const counts = {1: 0, 2: 0, 3: 0, 4: 0};
        const dateData = (roomId && selectionsByRoom[roomId]?.[activeDate]) || {};
        Object.keys(dateData).forEach((uid) => {
            mealTypes.forEach((t) => {
                if (dateData[uid]?.[t]) counts[t] += 1;
            });
        });
        document.getElementById('count-morning').textContent = counts[1];
        document.getElementById('count-noon').textContent = counts[2];
        document.getElementById('count-night').textContent = counts[3];
        document.getElementById('count-bento').textContent = counts[4];
    }

    function updateBulkToggleState() {
        const roomId = getRoomId();
        const currentUsers = usersByRoom[roomId] || [];
        bulkToggles.forEach((toggle) => {
            const type = Number(toggle.dataset.type);
            if (!roomId || currentUsers.length === 0 || isActiveDisabled()) {
                toggle.checked = false;
                toggle.indeterminate = false;
                toggle.disabled = !roomId || isActiveDisabled();
                return;
            }
            let selectable = 0;
            let checked = 0;
            currentUsers.forEach((u) => {
                const uid = u.id;
                const isLocked = !!(lockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
                const isOtherRoomLocked = !!(otherRoomLockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
                if (isLocked || isOtherRoomLocked) return;
                selectable += 1;
                if (selectionsByRoom[roomId]?.[activeDate]?.[uid]?.[type]) checked += 1;
            });
            if (selectable === 0) {
                toggle.checked = false;
                toggle.indeterminate = false;
                toggle.disabled = true;
                return;
            }
            toggle.disabled = false;
            toggle.checked = (checked === selectable);
            toggle.indeterminate = (checked > 0 && checked < selectable);
        });
    }

    function isActiveDisabled() {
        const btn = dayButtons.find((b) => b.dataset.date === activeDate);
        return btn?.dataset.disabled === '1';
    }

    function buildMealCell(roomId, uid, type) {
        const isChecked = !!(selectionsByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
        const isLocked = !!(lockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
        const isOtherRoomLocked = !!(otherRoomLockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
        const disabledByDate = isActiveDisabled();

        // 弁当(4)と朝昼夜(1,2,3)の排他：
        // 「既存予約済み」または「UIでチェック済み」のどちらかがあれば相手を無効化
        const reservedForUser  = serverReservedByRoom[roomId]?.[activeDate]?.[uid] || {};
        const selectedForUser  = selectionsByRoom[roomId]?.[activeDate]?.[uid]    || {};

        const hasMealReserved  = !!(reservedForUser[1]  || reservedForUser[2]  || reservedForUser[3]);
        const hasMealSelected  = !!(selectedForUser[1]  || selectedForUser[2]  || selectedForUser[3]);
        const hasBentoReserved = !!(reservedForUser[4]);
        const hasBentoSelected = !!(selectedForUser[4]);

        // 弁当セル: 朝/昼/夜が予約済み or 選択済みなら無効
        const mealBlocksBento  = (type === 4) && (hasMealReserved || hasMealSelected);
        // 朝/昼/夜セル: 弁当が予約済み or 選択済みなら無効
        const bentoBlocksMeal  = (type !== 4) && (hasBentoReserved || hasBentoSelected);
        const isExcluded = mealBlocksBento || bentoBlocksMeal;

        let disabledReason = '';
        if (isOtherRoomLocked)   disabledReason = '他の部屋で予約されています。';
        if (!disabledReason && mealBlocksBento)   disabledReason = '朝・昼・夜が登録（選択）済みのため弁当は予約できません。';
        if (!disabledReason && bentoBlocksMeal)   disabledReason = '弁当が登録（選択）済みのためこの食事は予約できません。';

        const id = `cb-${activeDate}-${uid}-${type}`;
        const lockedClass = isLocked ? 'locked' : '';
        const isDisabled = isLocked || isOtherRoomLocked || disabledByDate || isExcluded;
        return `
            <label class="d-inline-flex align-items-center justify-content-center">
                <input class="meal-toggle" type="checkbox" id="${id}" data-uid="${uid}" data-type="${type}"
                       ${isChecked ? 'checked' : ''} ${isDisabled ? 'disabled' : ''}>
                <span class="meal-btn ${lockedClass} ${disabledReason ? 'has-tooltip' : ''}" ${disabledReason ? `data-tooltip="${disabledReason}"` : ''}>✓</span>
            </label>
        `;
    }

    function renderTable() {
        if (!userRows) return;
        const roomId = getRoomId();
        if (!roomId) {
            userRows.innerHTML = '<tr><td colspan="7" class="text-center text-muted">部屋を選択してください。</td></tr>';
            return;
        }
        const currentUsers = usersByRoom[roomId] || [];
        if (!currentUsers.length) {
            userRows.innerHTML = '<tr><td colspan="7" class="text-center text-muted">利用者がいません。</td></tr>';
            return;
        }
        const rows = currentUsers.map((u, idx) => {
            const uid = u.id;
            const isStaff = u.is_staff ? '1' : '0';
            const fullMeal = mealTypes.every((t) => selectionsByRoom[roomId]?.[activeDate]?.[uid]?.[t]);
            return `
                <tr data-is-staff="${isStaff}">
                    <td>${idx + 1}</td>
                    <td><strong>${u.name}</strong></td>
                    <td class="text-center">${buildMealCell(roomId, uid, 1)}</td>
                    <td class="text-center">${buildMealCell(roomId, uid, 2)}</td>
                    <td class="text-center">${buildMealCell(roomId, uid, 3)}</td>
                    <td class="text-center">${buildMealCell(roomId, uid, 4)}</td>
                    <td class="text-center">${fullMeal ? '<span class="status-pill">FULL MEAL</span>' : ''}</td>
                </tr>
            `;
        }).join('');
        userRows.innerHTML = rows;

        userRows.querySelectorAll('.meal-toggle').forEach((cb) => {
            cb.addEventListener('change', (e) => {
                if (isActiveDisabled()) {
                    e.target.checked = false;
                    return;
                }
                const uid = e.target.dataset.uid;
                const type = Number(e.target.dataset.type);
                ensureState(roomId, activeDate);
                selectionsByRoom[roomId][activeDate][uid] = selectionsByRoom[roomId][activeDate][uid] || {};
                if (e.target.checked) {
                    selectionsByRoom[roomId][activeDate][uid][type] = true;
                    // 昼(2)と弁当(4)は排他（選択状態を削除）
                    if (type === 2 || type === 4) {
                        const counterpart = type === 2 ? 4 : 2;
                        if (!lockedByRoom[roomId]?.[activeDate]?.[uid]?.[counterpart]) {
                            delete selectionsByRoom[roomId][activeDate][uid][counterpart];
                        }
                    }
                    // 朝(1)/夜(3)を選んだとき弁当の選択状態も削除
                    if (type === 1 || type === 3) {
                        if (!lockedByRoom[roomId]?.[activeDate]?.[uid]?.[4]) {
                            delete selectionsByRoom[roomId][activeDate][uid][4];
                        }
                    }
                    // 弁当(4)を選んだとき朝(1)/夜(3)の選択状態も削除
                    if (type === 4) {
                        [1, 3].forEach((mt) => {
                            if (!lockedByRoom[roomId]?.[activeDate]?.[uid]?.[mt]) {
                                delete selectionsByRoom[roomId][activeDate][uid][mt];
                            }
                        });
                    }
                } else {
                    if (serverReservedByRoom[roomId]?.[activeDate]?.[uid]?.[type]) {
                        selectionsByRoom[roomId][activeDate][uid][type] = false;
                    } else {
                        delete selectionsByRoom[roomId][activeDate][uid][type];
                    }
                }
                // テーブル再描画で排他セルを即座にdisabled化
                renderTable();
                applySearchFilter();
                scheduleUpdateCounts();
                updateBulkToggleState();
                markDirty();
                scheduleSaveState();
            });
        });

        scheduleUpdateCounts();
        updateBulkToggleState();
        scheduleSaveState();
    }

    function updatePager() {
        if (!pagerInfo || !pagerPrev || !pagerNext) return;
        const totalPages = Math.max(1, Math.ceil(totalUsers / pageLimit));
        pagerInfo.textContent = `ページ ${currentPage} / ${totalPages}（${totalUsers}件）`;
        pagerPrev.disabled = currentPage <= 1;
        pagerNext.disabled = currentPage >= totalPages;
    }

    function fetchSnapshots(roomId, dates) {
        if (!roomId || !dates.length) return Promise.resolve({});
        return fetch(`${basePath}/TReservationInfo/getReservationSnapshots`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({ room_id: roomId, dates }),
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data || data.ok !== true) {
                    throw new Error(data?.message || 'snapshot fetch failed');
                }
                return data.data?.snapshots || {};
            });
    }

    function goToConflictDate(dateStr) {
        if (!dateStr) return;
        const inWeekBtn = dayButtons.find((b) => b.dataset.date === dateStr && b.dataset.disabled !== '1');
        if (inWeekBtn) {
            setActiveDate(dateStr, inWeekBtn.textContent?.trim() || activeLabel);
            saveUiState();
            currentPage = 1;
            const roomId = getRoomId();
            if (roomId) {
                fetchUsers(roomId, dateStr);
            } else {
                renderTable();
                applySearchFilter();
            }
            showNotice(`対象日 ${dateStr} を表示しました。`, 'info');
            return;
        }
        const params = new URLSearchParams(window.location.search);
        params.set('date', dateStr);
        if (window.__BASE_WEEK) {
            params.set('base_week', window.__BASE_WEEK);
        }
        const roomId = getRoomId();
        if (roomId) params.set('room_id', roomId);
        window.location.href = `${window.location.pathname}?${params.toString()}`;
    }

    function showConflictDateDialog(message, conflictDate) {
        if (typeof HTMLDialogElement === 'undefined') {
            return Promise.resolve(window.confirm(`${message}\n対象日: ${conflictDate}\n該当日に移動して確認しますか？`));
        }
        let dialog = document.getElementById('bulk-conflict-date-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'bulk-conflict-date-dialog';
            dialog.style.maxWidth = '32rem';
            dialog.style.width = 'calc(100% - 2rem)';
            dialog.style.border = '1px solid #d0d7de';
            dialog.style.borderRadius = '12px';
            dialog.style.padding = '0';
            dialog.innerHTML = [
                '<div style="padding:16px 16px 8px;font-weight:700;">予約競合が発生しました</div>',
                '<div style="padding:0 16px 12px;">',
                '<p id="bulk-conflict-date-dialog-message" style="margin:0 0 8px;"></p>',
                '<p id="bulk-conflict-date-dialog-date" style="margin:0;color:#555;font-size:0.95rem;"></p>',
                '</div>',
                '<div style="display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #eee;">',
                '<button type="button" id="bulk-conflict-date-dialog-cancel" class="btn btn-outline-secondary btn-sm">このまま閉じる</button>',
                '<button type="button" id="bulk-conflict-date-dialog-move" class="btn btn-primary btn-sm">対象日に移動する</button>',
                '</div>',
            ].join('');
            document.body.appendChild(dialog);
        }

        const msgEl = document.getElementById('bulk-conflict-date-dialog-message');
        const dateEl = document.getElementById('bulk-conflict-date-dialog-date');
        const cancelBtn = document.getElementById('bulk-conflict-date-dialog-cancel');
        const moveBtn = document.getElementById('bulk-conflict-date-dialog-move');
        if (msgEl) msgEl.textContent = message || '処理に失敗しました。';
        if (dateEl) dateEl.textContent = `対象日: ${conflictDate}`;

        return new Promise((resolve) => {
            const closeWith = (go) => {
                if (cancelBtn) cancelBtn.onclick = null;
                if (moveBtn) moveBtn.onclick = null;
                dialog.oncancel = null;
                if (dialog.open) dialog.close();
                resolve(go);
            };
            if (cancelBtn) cancelBtn.onclick = () => closeWith(false);
            if (moveBtn) moveBtn.onclick = () => closeWith(true);
            dialog.oncancel = (ev) => {
                ev.preventDefault();
                closeWith(false);
            };
            dialog.showModal();
        });
    }

    function applySearchFilter() {
        if (!userRows) return;
        const input = document.querySelector('.excel-header input[type="search"]');
        const q = input ? input.value.trim().toLowerCase() : '';
        let visible = 0;
        userRows.querySelectorAll('tr').forEach((tr) => {
            if (tr.dataset.empty === '1') return;
            const nameCell = tr.querySelector('td:nth-child(2)');
            const text = nameCell?.textContent?.toLowerCase() || '';
            const isStaff = tr.dataset.isStaff === '1';
            const passSearch = !q || text.includes(q);
            const passFilter =
                userFilter === 'all' ||
                (userFilter === 'staff' && isStaff) ||
                (userFilter === 'child' && !isStaff);
            const show = passSearch && passFilter;
            tr.style.display = show ? '' : 'none';
            if (show) visible += 1;
        });
        let emptyRow = userRows.querySelector('tr[data-empty="1"]');
        if (!visible) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.dataset.empty = '1';
                emptyRow.innerHTML = '<td colspan="7" class="text-center text-muted">該当する利用者がいません。</td>';
                userRows.appendChild(emptyRow);
            }
        } else if (emptyRow) {
            emptyRow.remove();
        }
    }

    function applyLocks(roomId, resMap) {
        ensureState(roomId, activeDate);
        lockedByRoom[roomId][activeDate] = {};
        serverReservedByRoom[roomId][activeDate] = {};
        Object.keys(resMap || {}).forEach((uid) => {
            lockedByRoom[roomId][activeDate][uid] = lockedByRoom[roomId][activeDate][uid] || {};
            serverReservedByRoom[roomId][activeDate][uid] = serverReservedByRoom[roomId][activeDate][uid] || {};
            Object.keys(resMap[uid]).forEach((type) => {
                // 直前編集で職員(0)はキャンセル不可のため、既存分はロック
                const isStaff = window.__CHANGE_EDIT && userLevelsByRoom[roomId]?.[uid] === 0;
                if (isStaff) {
                    lockedByRoom[roomId][activeDate][uid][Number(type)] = true;
                }
                selectionsByRoom[roomId][activeDate][uid] = selectionsByRoom[roomId][activeDate][uid] || {};
                serverReservedByRoom[roomId][activeDate][uid][Number(type)] = true;
                if (selectionsByRoom[roomId][activeDate][uid][Number(type)] !== false) {
                    selectionsByRoom[roomId][activeDate][uid][Number(type)] = true;
                }
            });
        });
    }

    function applyOtherRoomLocks(roomId, otherMap) {
        ensureState(roomId, activeDate);
        otherRoomLockedByRoom[roomId][activeDate] = {};
        Object.keys(otherMap || {}).forEach((uid) => {
            otherRoomLockedByRoom[roomId][activeDate][uid] = otherRoomLockedByRoom[roomId][activeDate][uid] || {};
            Object.keys(otherMap[uid]).forEach((type) => {
                otherRoomLockedByRoom[roomId][activeDate][uid][Number(type)] = true;
                if (selectionsByRoom[roomId]?.[activeDate]?.[uid]?.[Number(type)] != null) {
                    delete selectionsByRoom[roomId][activeDate][uid][Number(type)];
                }
            });
        });
    }

    function fetchUsers(roomId, date) {
        const useDate = date || window.__SELECTED_DATE || '';
        const url = `${basePath}/TReservationInfo/getUsersByRoomForBulk/${roomId}?date=${encodeURIComponent(useDate)}&page=${currentPage}&limit=${pageLimit}`;
        return fetch(url)
            .then((res) => res.json())
            .then((data) => {
                const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                usersByRoom[roomId] = payload.users || [];
                totalUsers = Number(payload.total || 0);
                pageLimit = Number(payload.limit || pageLimit);
                currentPage = Number(payload.page || currentPage);
                userLevelsByRoom[roomId] = userLevelsByRoom[roomId] || {};
                usersByRoom[roomId].forEach((u) => {
                    userLevelsByRoom[roomId][u.id] = Number(u.i_user_level ?? 0);
                });
                ensureState(roomId, activeDate);
                roomSnapshots[roomId][activeDate] = payload.reservation_snapshot || roomSnapshots[roomId][activeDate] || '';
                applyLocks(roomId, payload.reservations || {});
                applyOtherRoomLocks(roomId, payload.other_room_reservations || {});
                renderTable();
                applySearchFilter();
                updatePager();
                scheduleSaveState();
            })
            .catch((err) => {
                console.error(err);
                usersByRoom[roomId] = [];
                renderTable();
                applySearchFilter();
                updatePager();
            });
    }

    function applyBulkToggle(type, checked) {
        const roomId = getRoomId();
        if (!roomId || isActiveDisabled()) return;
        const currentUsers = usersByRoom[roomId] || [];
        ensureState(roomId, activeDate);
        currentUsers.forEach((u) => {
            const uid = u.id;
            const isLocked = !!(lockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
            const isOtherRoomLocked = !!(otherRoomLockedByRoom[roomId]?.[activeDate]?.[uid]?.[type]);
            if (isLocked || isOtherRoomLocked) return;
            selectionsByRoom[roomId][activeDate][uid] = selectionsByRoom[roomId][activeDate][uid] || {};
            if (checked) {
                selectionsByRoom[roomId][activeDate][uid][type] = true;
                if (type === 2 || type === 4) {
                    const counterpart = type === 2 ? 4 : 2;
                    const counterpartLocked = !!(lockedByRoom[roomId]?.[activeDate]?.[uid]?.[counterpart]);
                    if (!counterpartLocked) {
                        delete selectionsByRoom[roomId][activeDate][uid][counterpart];
                    }
                }
            } else {
                delete selectionsByRoom[roomId][activeDate][uid][type];
            }
        });
        renderTable();
        applySearchFilter();
        markDirty();
        scheduleSaveState();
    }

    bulkToggles.forEach((toggle) => {
        toggle.addEventListener('change', (e) => {
            const type = Number(e.target.dataset.type);
            applyBulkToggle(type, e.target.checked);
        });
    });

    function onDayClick(btn) {
        if (btn.dataset.disabled === '1') return;
        setActiveDate(btn.dataset.date, btn.textContent.trim());
        saveUiState();
        currentPage = 1;
        const roomId = getRoomId();
        if (roomId) {
            fetchUsers(roomId, activeDate);
        } else {
            renderTable();
        }
    }

    dayButtons.forEach((btn) => {
        btn.addEventListener('click', () => onDayClick(btn));
    });

    if (roomSelect) {
        roomSelect.addEventListener('change', () => {
            const roomId = roomSelect.value;
            roomHidden.value = roomId;
            updateWeekTabRoom(roomId);
            saveUiState();
            updateRoomValidation();
            currentPage = 1;
            if (roomId) {
                if (usersByRoom[roomId]) {
                    renderTable();
                } else {
                    fetchUsers(roomId, activeDate);
                }
            } else {
                renderTable();
            }
            applySearchFilter();
        });
    }

    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            // 選択中の曜日の設定を他曜日へコピー
            const sourceDate = activeDate || dayButtons[0]?.dataset.date;
            if (!sourceDate) return;
            const roomId = getRoomId();
            if (!roomId) return;
            ensureState(roomId, sourceDate);
            dayButtons.forEach((b) => {
                const d = b.dataset.date;
                if (!d || d === sourceDate || b.dataset.disabled === '1') return;
                ensureState(roomId, d);
                selectionsByRoom[roomId][d] = JSON.parse(JSON.stringify(selectionsByRoom[roomId][sourceDate] || {}));
            });
            scheduleUpdateCounts();
            renderTable();
            applySearchFilter();
            markDirty();
            scheduleSaveState();
        });
    }

    async function handleSaveClick() {
            if (isSubmitting) return;
            if (!form) return;
            if (!roomSelect?.value) {
                showNotice('部屋を選択してください。', 'warning');
                updateRoomValidation();
                return;
            }
            // hidden inputs 再生成
            selectionInputs.innerHTML = '';
            const roomId = getRoomId();
            const allDates = dayButtons.map((b) => b.dataset.date).filter(Boolean);
            const storedDates = Object.keys(selectionsByRoom[roomId] || {});
            const dateSet = new Set([...allDates, ...storedDates]);
            const dateList = Array.from(dateSet);

            const missingDates = dateList.filter((d) => !roomSnapshots[roomId]?.[d]);
            if (missingDates.length) {
                try {
                    const snapMap = await fetchSnapshots(roomId, missingDates);
                    ensureState(roomId, activeDate);
                    Object.keys(snapMap).forEach((d) => {
                        roomSnapshots[roomId][d] = snapMap[d];
                    });
                } catch (e) {
                    showNotice('競合チェック用の最新情報を取得できませんでした。再度お試しください。', 'error');
                    return;
                }
            }

            dateList.forEach((date) => {
                const users = (roomId && selectionsByRoom[roomId]?.[date]) || {};
                Object.keys(users).forEach((uid) => {
                    const meals = users[uid] || {};
                    mealTypes.forEach((type) => {
                        const isChecked = !!meals[type];
                        if (window.__CHANGE_EDIT) {
                            // 子供は 0/1 を送信（キャンセル可）
                            if (userLevelsByRoom[roomId]?.[uid] === 1) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = `day_users[${date}][${uid}][${type}]`;
                                input.value = isChecked ? '1' : '0';
                                selectionInputs.appendChild(input);
                            } else if (isChecked) {
                                // 職員は追加のみ
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = `day_users[${date}][${uid}][${type}]`;
                                input.value = '1';
                                selectionInputs.appendChild(input);
                            }
                        } else if (isChecked) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = `day_users[${date}][${uid}][${type}]`;
                            input.value = '1';
                            selectionInputs.appendChild(input);
                        }
                    });
                });
                const snap = roomSnapshots[roomId]?.[date];
                if (snap) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `reservation_snapshot[${date}]`;
                    input.value = snap;
                    selectionInputs.appendChild(input);
                }
            });
            form.requestSubmit();
    }
    if (saveBtn) saveBtn.addEventListener('click', handleSaveClick);

    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (isSubmitting) return;
            setSubmitting(true);
            const formData = new FormData(form);
            const submitUrl = window.__CHANGE_EDIT
                ? `${basePath}/TReservationInfo/bulk-change-edit-submit`
                : `${basePath}/TReservationInfo/bulk-add-submit`;
            fetch(submitUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': csrfToken },
            })
                .then((res) => res.json())
                .then((data) => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.status === 'success' || payload.ok) {
                        showNotice('保存が完了しました。', 'success');
                        clearDirty();
                        clearStateStorage();
                        if (payload.redirect_url || payload.redirect) {
                            window.location.href = payload.redirect_url || payload.redirect;
                            return;
                        }
                        setSubmitting(false);
                    } else {
                        setSubmitting(false);
                        const msg = payload.message || '処理に失敗しました。';
                        if (payload.conflict_date) {
                            showConflictDateDialog(msg, payload.conflict_date).then((shouldMove) => {
                                if (!shouldMove) return;
                                goToConflictDate(payload.conflict_date);
                            });
                            return;
                        }
                        showNotice(`エラー: ${msg}`, 'error');
                    }
                })
                .catch((err) => {
                    setSubmitting(false);
                    console.error(err);
                    showNotice('エラーが発生しました。再度お試しください。', 'error');
                });
        });
    }

    // 初期状態
    if (dayButtons.length === 0) {
        setActiveDate(activeDate, activeLabel);
        renderTable();
        updatePager();
        return;
    }
    if (!firstEnabled) {
        if (userRows) {
            userRows.innerHTML = '<tr><td colspan="7" class="text-center text-muted">編集可能な日付がありません。</td></tr>';
        }
        updatePager();
        return;
    }
    setActiveDate(activeDate, activeLabel);
    loadState();
    loadUiState();
    updateRoomValidation();
    if (roomSelect?.value) {
        updateWeekTabRoom(roomSelect.value);
    }
    if (roomSelect && window.__ROOM_ID) {
        roomSelect.value = window.__ROOM_ID;
        roomHidden.value = window.__ROOM_ID;
        updateWeekTabRoom(window.__ROOM_ID);
        updateRoomValidation();
        fetchUsers(window.__ROOM_ID, activeDate);
    } else {
        if (roomSelect?.value) {
            updateWeekTabRoom(roomSelect.value);
            updateRoomValidation();
            fetchUsers(roomSelect.value, activeDate);
        }
        renderTable();
        applySearchFilter();
    }
    saveUiState();
    updatePager();

    const searchInput = document.querySelector('.excel-header input[type="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            applySearchFilter();
        });
    }

    // 週切替：同期的に保存してから移動（scheduleSaveState では遷移前に保存されないため）
    weekTabLinks.forEach((a) => {
        a.addEventListener('click', (e) => {
            saveState();
            saveUiState();
        });
    });

    if (pagerPrev && pagerNext) {
        pagerPrev.addEventListener('click', () => {
            if (currentPage <= 1) return;
            currentPage -= 1;
            const roomId = getRoomId();
            if (roomId) fetchUsers(roomId, activeDate);
        });
        pagerNext.addEventListener('click', () => {
            const totalPages = Math.max(1, Math.ceil(totalUsers / pageLimit));
            if (currentPage >= totalPages) return;
            currentPage += 1;
            const roomId = getRoomId();
            if (roomId) fetchUsers(roomId, activeDate);
        });
    }

    // タップでもツールチップ表示
    if (userRows) {
        userRows.addEventListener('click', (e) => {
            const btn = e.target.closest('.meal-btn.has-tooltip');
            if (!btn) return;
            e.stopPropagation();
            btn.classList.toggle('show-tooltip');
        });
        document.addEventListener('click', () => {
            userRows.querySelectorAll('.meal-btn.has-tooltip.show-tooltip').forEach((el) => {
                el.classList.remove('show-tooltip');
            });
        });
    }
});