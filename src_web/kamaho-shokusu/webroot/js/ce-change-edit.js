/* global bootstrap */
/* eslint-env browser */
(function(){
    function esc(s){
        s = (s == null ? '' : String(s));
        return s.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
    }

    // ---- ユーザー判定
    function isStaffUser(u){
        if (u && typeof u.isStaff === 'boolean') return u.isStaff;
        return Number((u && (u.i_user_level != null ? u.i_user_level : u.userLevel))) === 0;
    }

    // ---- mealType 取得
    function resolveMealType(container){
        var hidden = container.querySelector('#ce-mealtype-hidden');
        if (hidden && hidden.value) return String(hidden.value);
        var root = container.querySelector('#ce-root') || container;
        var dt = root && root.getAttribute('data-mealtype');
        if (dt) return String(dt);
        if (window.mealEditParams && window.mealEditParams.mealType != null) return String(window.mealEditParams.mealType);
        try {
            var q = new URLSearchParams(location.search).get('mealType');
            if (q) return String(q);
        } catch(_e){}
        return '';
    }

    // ---- API URL
    function apiUrl(base, roomId, dateStr, mealType){
        var rootBase = (typeof window.__BASE_PATH === 'string' && window.__BASE_PATH) ? window.__BASE_PATH : (base || '/');
        if (rootBase.slice(-1) !== '/') rootBase += '/';
        return rootBase + 'TReservationInfo/change-edit/' + encodeURIComponent(roomId) + '/' + encodeURIComponent(dateStr) + '/' + encodeURIComponent(mealType);
    }

    // ---- ローディング表示
    function showLoading(tbody){
        tbody.innerHTML =
            '<tr><td colspan="5" class="text-center py-4 text-muted">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>読み込み中...' +
            '</td></tr>';
    }

    function showMsg(tbody, msg, isError){
        tbody.innerHTML =
            '<tr><td colspan="5" class="text-center py-3 ' + (isError ? 'text-danger' : 'text-muted') + '">' +
            (isError ? '<i class="bi bi-exclamation-circle me-1"></i>' : '') + esc(msg) +
            '</td></tr>';
    }

    // ---- 食数サマリー更新
    function updateSummary(container){
        var counts = {1: 0, 2: 0, 3: 0, 4: 0};
        container.querySelectorAll('#ce-tbody tr[data-user-id]').forEach(function(tr){
            for (var t = 1; t <= 4; t++){
                var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="' + t + '"]');
                if (cb && cb.checked) counts[t]++;
            }
        });
        for (var t = 1; t <= 4; t++){
            var el = container.querySelector('[data-meal-summary="' + t + '"]');
            if (el) el.textContent = counts[t];
        }
    }

    // ---- 変更件数カウンター更新
    function updateChangeCount(container){
        var changed = 0;
        container.querySelectorAll('#ce-tbody tr[data-user-id]').forEach(function(tr){
            var hasChange = false;
            for (var t = 1; t <= 4; t++){
                var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="' + t + '"]');
                if (!cb) continue;
                if (cb.checked !== (cb.getAttribute('data-initial-checked') === '1')) { hasChange = true; break; }
            }
            if (hasChange){ changed++; tr.classList.add('ce-row-changed'); }
            else { tr.classList.remove('ce-row-changed'); }
        });
        var el = container.querySelector('#ce-change-count');
        if (el) el.textContent = changed > 0 ? changed + '件の変更があります' : '';
    }

    // ---- ヘッダーチェックボックスの状態を行の状態から再計算（indeterminate対応）
    function updateHeaderStates(container){
        for (var t = 1; t <= 4; t++){
            var header = container.querySelector('#select-all-' + t);
            if (!header) continue;
            // disabled / locked でないチェックボックスのみ対象
            var allCbs = Array.prototype.slice.call(
                container.querySelectorAll(
                    '#ce-tbody tr[data-user-id] input.meal-checkbox[data-reservation-type="' + t + '"]'
                )
            ).filter(function(cb){ return !cb.disabled && cb.dataset.locked !== '1'; });

            if (allCbs.length === 0){ header.checked = false; header.indeterminate = false; continue; }
            var checkedCount = allCbs.filter(function(cb){ return cb.checked; }).length;
            if (checkedCount === 0){
                header.checked = false; header.indeterminate = false;
            } else if (checkedCount === allCbs.length){
                header.checked = true; header.indeterminate = false;
            } else {
                header.checked = false; header.indeterminate = true;
            }
        }
    }

    // ---- まとめてUI更新
    function updateAll(container){
        updateSummary(container);
        updateChangeCount(container);
        updateHeaderStates(container);
    }

    // ---- 行 HTML 生成
    function createRowHTML(user, flagsByType){
        var uId     = esc(user.id);
        var uName   = esc(user.name);
        var allow   = !!user.allowEdit;
        var isStaff = isStaffUser(user);
        var userLevel = (user && (user.i_user_level != null ? user.i_user_level : user.userLevel));

        var cells = '';
        for (var t = 1; t <= 4; t++){
            var f = flagsByType[t] || {};
            var initiallyOn = Number(f.i_change_flag || 0) === 1;
            var eatFlag     = Number(f.eat_flag || 0);
            var checked     = initiallyOn ? ' checked' : '';
            var disabled    = allow ? '' : ' disabled';
            var initAttr    = initiallyOn ? ' data-initial-checked="1"' : '';
            var eatAttr     = eatFlag === 1 ? ' data-eat-flag="1"' : '';

            cells +=
                '<td class="text-center">' +
                '<input type="checkbox"' +
                ' name="users[' + uId + '][' + t + ']"' +
                ' class="meal-checkbox"' +
                ' data-reservation-type="' + t + '"' +
                ' data-user-id="' + uId + '"' +
                ' value="1"' + checked + disabled + initAttr + eatAttr + '>' +
                '</td>';
        }

        var staffAttr = isStaff ? ' data-is-staff="1"' : '';
        var levelAttr = (userLevel != null && userLevel !== '') ? ' data-user-level="' + esc(userLevel) + '"' : '';
        return '<tr data-user-id="' + uId + '"' + staffAttr + levelAttr + '>' +
            '<td>' + uName + '</td>' +
            cells +
            '</tr>';
    }

    // ---- 列一括切替（昼食⇔弁当排他制御）
    // イベントは発火せず直接値を書き換え、最後に updateAll で一括更新する
    function toggleColumn(container, reservationType, checked){
        var tbody = container.querySelector('#ce-tbody');
        if (!tbody) return;
        tbody.querySelectorAll('tr[data-user-id]').forEach(function(tr){
            var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="' + reservationType + '"]');
            if (!cb || cb.disabled || cb.dataset.locked === '1') return;
            cb.checked = !!checked;
            // 昼(2)をONにしたら弁当(4)をOFF
            if (reservationType === 2 && checked){
                var bento = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
                if (bento && !bento.disabled && bento.dataset.locked !== '1') bento.checked = false;
            }
            // 弁当(4)をONにしたら昼(2)をOFF
            if (reservationType === 4 && checked){
                var lunch = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                if (lunch && !lunch.disabled && lunch.dataset.locked !== '1') lunch.checked = false;
            }
        });
        updateAll(container);
    }

    // ---- ヘッダー全選択チェックボックスのバインド
    function bindHeaderChecks(container){
        [1, 2, 3, 4].forEach(function(t){
            var h = container.querySelector('#select-all-' + t);
            if (!h) return;
            // 既存のリスナーをリセット（cloneで置き換え）
            var clone = h.cloneNode(true);
            h.parentNode.replaceChild(clone, h);
            clone.addEventListener('change', function(e){
                // indeterminate状態でクリックするとchecked=trueになるのでそのまま利用
                toggleColumn(container, t, !!e.target.checked);
                // toggleColumn内でupdateAll済み（ヘッダー状態も含む）
            });
        });
    }

    // ---- 氏名検索バインド
    function bindNameSearch(container){
        var input = container.querySelector('#ce-name-search');
        if (!input || input.dataset.searchBound === '1') return;
        input.dataset.searchBound = '1';
        input.addEventListener('input', function(){
            var q = input.value.trim().toLowerCase();
            container.querySelectorAll('#ce-tbody tr[data-user-id]').forEach(function(tr){
                var name = (tr.querySelector('td:first-child') || {}).textContent || '';
                tr.classList.toggle('ce-row-hidden', q !== '' && name.toLowerCase().indexOf(q) === -1);
            });
        });
    }

    // ---- 職員の既存予約チェックボックスをロック
    function installUncheckGuards(tbody){
        if (!tbody || !(tbody instanceof HTMLElement)) return;
        tbody.querySelectorAll('tr[data-is-staff="1"] input.meal-checkbox[data-initial-checked="1"]').forEach(function(cb){
            if (cb.checked && !cb.disabled){
                cb.disabled = true;
                cb.dataset.locked = '1';
                cb.title = '職員の予約は直前変更画面からは解除できません。';
                cb.classList.add('deletion-blocked');
            }
        });
    }

    // ---- 一覧取得 & 描画
    function fetchAndRender(container){
        var root       = container.querySelector('#ce-root') || container;
        var base       = root.getAttribute('data-base') || '/';
        var form       = container.querySelector('#change-edit-form');
        var roomSelect = container.querySelector('#ce-room-select');
        var roomHidden = container.querySelector('#ce-room-hidden');
        var tbody      = container.querySelector('#ce-tbody');
        var dateHidden = container.querySelector('#ce-date-hidden');

        if (!tbody) return;

        var roomId = (roomSelect && roomSelect.value) || (roomHidden && roomHidden.value);
        var date   = (dateHidden && dateHidden.value);
        var meal   = resolveMealType(container);

        if (!roomId){ showMsg(tbody, '部屋が選択されていません。', true); return; }
        if (!date)  { showMsg(tbody, '日付が不正です。', true); return; }
        if (!meal)  { showMsg(tbody, '食種(mealType)が不正です。', true); return; }

        showLoading(tbody);

        var ctrl = null, signal, to = null;
        if (typeof AbortController !== 'undefined'){
            ctrl = new AbortController(); signal = ctrl.signal;
            to = setTimeout(function(){ try { ctrl.abort(); } catch(_e){} }, 12000);
        }

        fetch(apiUrl(base, roomId, date, meal), {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: signal
        })
        .then(function(res){ return res.json().then(function(j){ return { ok: res.ok, j: j }; }); })
        .then(function(pair){
            var ok = pair.ok, json = pair.j;
            if (!ok || !json || json.status !== 'success' || !json.data){
                showMsg(tbody, (json && json.message) || '一覧取得に失敗しました。', true); return;
            }

            var users = Array.isArray(json.data.users) ? json.data.users : [];
            var flags = json.data.userReservations || {};

            if (users.length === 0){ showMsg(tbody, '該当する利用者がいません。'); return; }

            var html = '';
            users.forEach(function(u){ html += createRowHTML(u, flags[String(u.id)] || {}); });
            tbody.innerHTML = html;

            // UIガード
            installUncheckGuards(tbody);

            // 行レベルのchangeイベント（排他制御 + UI一括更新）
            tbody.querySelectorAll('tr[data-user-id]').forEach(function(tr){
                var lunchCb = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                var bentoCb = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');

                // 朝・夕：変更があればUI更新のみ
                [1, 3].forEach(function(t){
                    var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="' + t + '"]');
                    if (cb) cb.addEventListener('change', function(){ updateAll(container); });
                });

                // 昼：ONにしたら弁当をOFF
                if (lunchCb) lunchCb.addEventListener('change', function(){
                    if (lunchCb.checked && !lunchCb.disabled && lunchCb.dataset.locked !== '1'){
                        if (bentoCb && !bentoCb.disabled && bentoCb.dataset.locked !== '1') bentoCb.checked = false;
                    }
                    updateAll(container);
                });

                // 弁当：ONにしたら昼をOFF
                if (bentoCb) bentoCb.addEventListener('change', function(){
                    if (bentoCb.checked && !bentoCb.disabled && bentoCb.dataset.locked !== '1'){
                        if (lunchCb && !lunchCb.disabled && lunchCb.dataset.locked !== '1') lunchCb.checked = false;
                    }
                    updateAll(container);
                });
            });

            bindHeaderChecks(container);
            bindNameSearch(container);
            updateAll(container);

            // フォーム送信
            var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
            var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : null;
            if (form && form.dataset.submitBound !== '1'){
                form.dataset.submitBound = '1';
                form.addEventListener('submit', function(e){
                    e.preventDefault();

                    var rId = (roomSelect && roomSelect.value) || (roomHidden && roomHidden.value);
                    var d   = (dateHidden && dateHidden.value);
                    var m   = resolveMealType(container);

                    if (!rId || !d){ alert('部屋または日付が不正です。'); return; }
                    if (!m)        { alert('食種(mealType)が不正です。'); return; }

                    var usersPayload = {};
                    tbody.querySelectorAll('tr[data-user-id]').forEach(function(tr){
                        var uid = tr.getAttribute('data-user-id');
                        if (!uid) return;
                        var obj = {}, hasChange = false;
                        for (var t = 1; t <= 4; t++){
                            var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="' + t + '"]');
                            if (!cb) continue;
                            var isChecked  = !!cb.checked;
                            var wasChecked = cb.getAttribute('data-initial-checked') === '1';
                            var flag = 0;
                            if (isChecked  && !wasChecked) flag = 1;
                            if (!isChecked && wasChecked)  flag = 2;
                            if (flag > 0){ obj[String(t)] = { i_change_flag: flag }; hasChange = true; }
                        }
                        if (hasChange) usersPayload[String(uid)] = obj;
                    });

                    if (Object.keys(usersPayload).length === 0){ alert('変更された項目がありません。'); return; }

                    var saveBtn    = container.querySelector('#ce-save-btn');
                    var saveSpinner = container.querySelector('#ce-save-spinner');
                    if (saveBtn)    { saveBtn.disabled = true; }
                    if (saveSpinner){ saveSpinner.style.display = 'inline-flex'; }

                    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                    fetch(apiUrl(base, rId, d, m), {
                        method: 'POST', headers: headers, credentials: 'same-origin',
                        body: JSON.stringify({ users: usersPayload })
                    })
                    .then(function(res2){ return res2.json().then(function(j){ return { ok: res2.ok, j: j }; }); })
                    .then(function(pair2){
                        var ok2 = pair2.ok, json2 = pair2.j;
                        if (!ok2 || !json2 || json2.status !== 'success'){
                            alert((json2 && json2.message) || '直前予約の更新に失敗しました。');
                            if (saveBtn)    saveBtn.disabled = false;
                            if (saveSpinner) saveSpinner.style.display = 'none';
                            return;
                        }
                        // 成功：モーダルを閉じてリロード
                        var modalEl = container.closest('.modal');
                        if (modalEl && window.bootstrap){
                            var inst = bootstrap.Modal.getInstance(modalEl);
                            if (inst) inst.hide();
                        }
                        window.location.reload();
                    })
                    .catch(function(){
                        alert('保存リクエスト送信に失敗しました。');
                        if (saveBtn)    saveBtn.disabled = false;
                        if (saveSpinner) saveSpinner.style.display = 'none';
                    });
                });
            }

            // 部屋変更 → 再取得
            if (roomSelect && roomSelect.dataset.changeBound !== '1'){
                roomSelect.dataset.changeBound = '1';
                roomSelect.addEventListener('change', function(){ fetchAndRender(container); });
            }
        })
        .catch(function(err){
            console.error('一覧取得エラー:', err);
            showMsg(tbody, '一覧取得に失敗しました。', true);
        })
        .finally(function(){ if (to) clearTimeout(to); });
    }

    // ---- 初期化
    function init(scope){
        var container = scope || document;
        var form = container.querySelector('#change-edit-form');
        if (!form || form.dataset.ceBooted === '1') return;

        var sel = container.querySelector('#ce-room-select');
        var hid = container.querySelector('#ce-room-hidden');
        if (sel){
            if (!sel.value && sel.options.length > 0) sel.value = sel.options[0].value;
        } else if (hid && !hid.value){
            console.warn('部屋IDが設定されていません');
        }

        form.dataset.ceBooted = '1';
        setTimeout(function(){ fetchAndRender(container); }, 100);
    }

    // グローバル公開
    window.CE_CHANGE_EDIT = { init: init };

    // 直描画時
    document.addEventListener('DOMContentLoaded', function(){
        if (document.getElementById('ce-root')) init(document);
    });

    // モーダル表示時（shown.bs.modal）
    document.addEventListener('shown.bs.modal', function(ev){
        var modal = ev.target;
        if (modal && modal.querySelector && modal.querySelector('#ce-root')) window.CE_CHANGE_EDIT.init(modal);
    });
})();


// ---- 食数予約：利用者一覧取得と描画
(function(){
    if (!window.GET_USERS_BY_ROOM_TPL) return;

    function buildUsersUrl(roomId){
        var tpl = window.GET_USERS_BY_ROOM_TPL;
        if (!tpl) return '';
        return tpl.includes('__RID__') ? tpl.replace('__RID__', encodeURIComponent(roomId)) : tpl;
    }

    function normalizeUser(u){
        return {
            id: u.id, name: u.name || '',
            breakfast: (u.breakfast ?? u.morning) || false,
            lunch: (u.lunch ?? u.noon) || false,
            dinner: (u.dinner ?? u.night) || false,
            bento: u.bento || false,
            room_name: u.room_name || u.roomName || ''
        };
    }

    function escapeHtml(s){
        return String(s || '').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
    }

    async function safeFetchJson(url){
        var res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        var text = await res.text();
        try { return JSON.parse(text); }
        catch(e){ console.error('JSON parse失敗', text.slice(0, 200)); return null; }
    }

    async function fetchUsers(roomId){
        var url = buildUsersUrl(roomId);
        if (!url) return [];
        var json = await safeFetchJson(url);
        if (!json) return [];
        var arr = Array.isArray(json.users) ? json.users
            : (json.data && Array.isArray(json.data.users)) ? json.data.users
            : Array.isArray(json.usersByRoom) ? json.usersByRoom : [];
        return arr.map(normalizeUser);
    }

    function renderUsers(users){
        var tbody = document.getElementById('user-checkboxes');
        if (!tbody) return;
        if (users.length === 0){ tbody.innerHTML = '<tr><td colspan="6">利用者が取得できませんでした。</td></tr>'; return; }
        tbody.innerHTML = users.map(function(u){
            return '<tr data-user-id="' + u.id + '">' +
                '<td>' + escapeHtml(u.name) + '</td>' +
                '<td>' + escapeHtml(u.room_name) + '</td>' +
                '<td class="text-center"><input type="checkbox" ' + (u.breakfast ? 'checked' : '') + ' disabled></td>' +
                '<td class="text-center"><input type="checkbox" ' + (u.lunch     ? 'checked' : '') + ' disabled></td>' +
                '<td class="text-center"><input type="checkbox" ' + (u.dinner    ? 'checked' : '') + ' disabled></td>' +
                '<td class="text-center"><input type="checkbox" ' + (u.bento     ? 'checked' : '') + ' disabled></td>' +
                '</tr>';
        }).join('');
    }

    async function init(){
        var rid = window.__PRIMARY_ROOM_ID || (window.__USER_INFO && window.__USER_INFO.roomId) || 1;
        renderUsers(await fetchUsers(rid));
    }

    document.addEventListener('DOMContentLoaded', init);
    window.refreshUsersByRoom = async function(roomId){ renderUsers(await fetchUsers(roomId)); };
})();
