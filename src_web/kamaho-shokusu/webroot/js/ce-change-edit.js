/* global bootstrap */
/* eslint-env browser */
(function(){
    function esc(s){
        s = (s == null ? '' : String(s));
        return s.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
    }

    // ---- ユーザー判定:isStaffプロパティを優先
    function isStaffUser(u){
        if (u && typeof u.isStaff === 'boolean') {
            return u.isStaff;
        }
        // フォールバック
        return Number((u && (u.i_user_level != null ? u.i_user_level : u.userLevel))) === 0;
    }

    // ---- mealType の取得（複数フォールバック）
    function resolveMealType(container){
        var hidden = container.querySelector('#ce-mealtype-hidden');
        if (hidden && hidden.value) return String(hidden.value);

        var root = container.querySelector('#ce-root') || container;
        var dt = root && root.getAttribute('data-mealtype');
        if (dt) return String(dt);

        if (window.mealEditParams && window.mealEditParams.mealType != null) {
            return String(window.mealEditParams.mealType);
        }

        try {
            var usp = new URLSearchParams(location.search);
            var q = usp.get('mealType');
            if (q != null && q !== '') return String(q);
        } catch(_e){/* nop */}

        return ''; // 未取得
    }

    // ---- API URL（ご指定のパス形式）
    //   TReservationInfo/change-edit/{roomId}/{date}/{mealType}
    function apiUrl(base, roomId, dateStr, mealType){
        base = base || '/';
        if (base.slice(-1) !== '/') base += '/';
        return base
            + 'TReservationInfo/change-edit/'
            + encodeURIComponent(roomId) + '/'
            + encodeURIComponent(dateStr) + '/'
            + encodeURIComponent(mealType);
    }

    function showLoading(tbody){
        tbody.innerHTML =
            '<tr>' +
            '  <td colspan="5" class="text-center">' +
            '    <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>' +
            '    <span class="ms-2">読み込み中...</span>' +
            '  </td>' +
            '</tr>';
    }
    function showMsg(tbody, msg, isError){
        tbody.innerHTML = '<tr><td colspan="5" class="text-center ' + (isError?'text-danger':'text-muted') + '">' + esc(msg) + '</td></tr>';
    }

    // ---- 行HTML
    function createRowHTML(user, flagsByType){
        var uId   = esc(user.id);
        var uName = esc(user.name);
        var allow = !!user.allowEdit;
        var isStaff = isStaffUser(user);

        var cells = '';
        for (var t=1; t<=4; t++){
            var f = flagsByType[t] || {};
            var initiallyOn = Number(f.i_change_flag || 0) === 1;
            var eatFlag = Number(f.eat_flag || 0);
            var checked  = initiallyOn ? ' checked' : '';
            // allowEditがfalseの場合は常にdisabled
            var disabled = allow ? '' : ' disabled';
            var initAttr = initiallyOn ? ' data-initial-checked="1"' : '';
            var eatAttr = eatFlag === 1 ? ' data-eat-flag="1"' : '';

            cells += '' +
                '<td class="text-center">' +
                '  <input type="checkbox"' +
                '         name="users['+uId+']['+t+']"' +
                '         class="meal-checkbox"' +
                '         data-reservation-type="'+t+'"' +
                '         data-user-id="'+uId+'"' +
                '         value="1"'+checked+disabled+initAttr+eatAttr+'>' +
                '</td>';
        }
        var staffAttr = isStaff ? ' data-is-staff="1"' : '';
        return '<tr data-user-id="'+uId+'"' + staffAttr + '><td>'+uName+'</td>' + cells + '</tr>';
    }

    // ---- 列一括切替
    function toggleColumn(container, reservationType, checked){
        var tbody = container.querySelector('#ce-tbody');
        if (!tbody) return;

        tbody.querySelectorAll('input.meal-checkbox[data-reservation-type="'+reservationType+'"]').forEach(function(cb){
            if (cb.disabled || cb.dataset.locked === '1') return;
            cb.checked = !!checked;

            var tr = cb.closest('tr');
            if (!tr) return;
            if (reservationType === 2 && checked) {
                var bento = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
                if (bento && !bento.disabled && bento.dataset.locked !== '1') bento.checked = false;
            }
            if (reservationType === 4 && checked) {
                var lunch = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                if (lunch && !lunch.disabled && lunch.dataset.locked !== '1') lunch.checked = false;
            }
        });
    }

    // ---- 列ヘッダ
    function bindHeaderChecks(container){
        ['select-all-1','select-all-2','select-all-3','select-all-4'].forEach(function(id){
            var h = container.querySelector('#'+id);
            if (!h) return;
            var clone = h.cloneNode(true);
            h.parentNode.replaceChild(clone, h);
        });

        var h1 = container.querySelector('#select-all-1');
        if (h1) h1.addEventListener('change', function(e){ toggleColumn(container, 1, !!e.target.checked); });

        var h2 = container.querySelector('#select-all-2');
        if (h2) h2.addEventListener('change', function(e){
            toggleColumn(container, 2, !!e.target.checked);
            var h4 = container.querySelector('#select-all-4');
            if (e.target.checked && h4) h4.checked = false;
        });

        var h3 = container.querySelector('#select-all-3');
        if (h3) h3.addEventListener('change', function(e){ toggleColumn(container, 3, !!e.target.checked); });

        var h4 = container.querySelector('#select-all-4');
        if (h4) h4.addEventListener('change', function(e){
            toggleColumn(container, 4, !!e.target.checked);
            var h2b = container.querySelector('#select-all-2');
            if (e.target.checked && h2b) h2b.checked = false;
        });
    }

    // ---- UIガード: 職員の予約済みチェックは解除させない
    function installUncheckGuards(tbody) {
        if (!tbody || !(tbody instanceof HTMLElement)) return;

        // 職員ユーザーの予約済み(i_change_flag=1)チェックボックスをロック
        const lockTargetList = tbody.querySelectorAll('tr[data-is-staff="1"] input.meal-checkbox[data-initial-checked="1"]');

        console.log('ロック対象の職員CB数:', lockTargetList.length);

        lockTargetList.forEach(cb => {
            // allowEdit=falseで既にdisabledの場合もあるが、そうでなければロックする
            if (cb.checked && !cb.disabled) {
                cb.disabled = true;
                cb.dataset.locked = '1';
                if (!cb.title) cb.title = '職員の予約は直前変更画面からは解除できません。';
                cb.classList.add('deletion-blocked');
            }
        });
    }

    // ---- 一覧取得＆描画
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

        if (!roomId) { showMsg(tbody, '部屋が選択されていません。', true); return; }
        if (!date)   { showMsg(tbody, '日付が不正です。', true); return; }
        if (!meal)   { showMsg(tbody, '食種(mealType)が不正です。', true); return; }

        showLoading(tbody);

        // タイムアウト付きフェッチ
        var ctrl = new AbortController();
        var to = setTimeout(function(){ try{ ctrl.abort(); }catch(_e){} }, 12000);

        fetch(apiUrl(base, roomId, date, meal), {
            method: 'GET',
            headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: ctrl.signal
        })
            .then(function(res){
                return res.json().then(function(j){ return { ok: res.ok, j:j }; });
            })
            .then(function(pair){
                var ok = pair.ok, json = pair.j;

                if (!ok || !json || json.status !== 'success' || !json.data) {
                    showMsg(tbody, (json && json.message) || '一覧取得に失敗しました。', true); return;
                }

                var users = Array.isArray(json.data.users) ? json.data.users : [];
                var flags = json.data.userReservations || {};

                if (users.length === 0) { showMsg(tbody, '該当する利用者がいません。'); return; }

                var html = '';
                users.forEach(function(u) {
                    html += createRowHTML(u, flags[String(u.id)] || {});
                });
                tbody.innerHTML = html;

                // UIガード（職員の予約解除をブロック）
                installUncheckGuards(tbody);

                // ヘッダ全選択/解除
                bindHeaderChecks(container);

                // 保存(JSON POST)
                var csrfMeta  = document.querySelector('meta[name="csrfToken"]');
                var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : null;
                if (form && form.dataset.submitBound !== '1') {
                    form.dataset.submitBound = '1';
                    form.addEventListener('submit', function(e){
                        e.preventDefault();

                        var rId = (roomSelect && roomSelect.value) || (roomHidden && roomHidden.value);
                        var d   = (dateHidden && dateHidden.value);
                        var m   = resolveMealType(container);

                        if (!rId || !d) { alert('部屋または日付が不正です。'); return; }
                        if (!m)         { alert('食種(mealType)が不正です。'); return; }

                        var usersPayload = {};
                        var trs = tbody.querySelectorAll('tr[data-user-id]');
                        Array.prototype.forEach.call(trs, function(tr){
                            var uid = tr.getAttribute('data-user-id');
                            if (!uid) return;
                            var obj = {};
                            var hasChange = false;
                            for (var t=1; t<=4; t++){
                                var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="'+t+'"]');
                                if (!cb) continue;

                                var isChecked = !!cb.checked;
                                var wasChecked = cb.getAttribute('data-initial-checked') === '1';
                                var changeFlag = 0; // 0:変更なし, 1:食べる, 2:食べない

                                if (isChecked && !wasChecked) {
                                    changeFlag = 1; // 新規予約
                                } else if (!isChecked && wasChecked) {
                                    changeFlag = 2; // 予約取消
                                }

                                if (changeFlag > 0) {
                                    obj[String(t)] = { i_change_flag: changeFlag };
                                    hasChange = true;
                                }
                            }
                            if (hasChange) {
                                usersPayload[String(uid)] = obj;
                            }
                        });

                        if (Object.keys(usersPayload).length === 0) {
                            alert('変更された項目がありません。');
                            return;
                        }

                        var headers = { 'Content-Type':'application/json', 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' };
                        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                        fetch(apiUrl(base, rId, d, m), {
                            method:'POST', headers: headers, credentials:'same-origin',
                            body: JSON.stringify({ users: usersPayload })
                        })
                            .then(function(res2){ return res2.json().then(function(j){ return { ok: res2.ok, j:j }; }); })
                            .then(function(pair2){
                                var ok2 = pair2.ok, json2 = pair2.j;
                                if (!ok2 || !json2 || json2.status !== 'success') {
                                    alert((json2 && json2.message) || '直前予約の更新に失敗しました。'); return;
                                }

                                // ---- 成功時の処理 ----
                                alert('直前予約を更新しました。');

                                // モーダルを閉じる
                                var modalEl = container.closest('.modal');
                                if (modalEl) {
                                    var modalInstance = bootstrap.Modal.getInstance(modalEl);
                                    if (modalInstance) {
                                        modalInstance.hide();
                                    }
                                }

                                // ページをリロードして変更を反映
                                window.location.reload();
                            })
                            .catch(function(){ alert('保存リクエスト送信に失敗しました。'); });
                    });
                }

                // 部屋変更→再取得
                if (roomSelect && roomSelect.dataset.changeBound !== '1') {
                    roomSelect.dataset.changeBound = '1';
                    roomSelect.addEventListener('change', function(){ fetchAndRender(container); });
                }
            })
            .catch(function(err){
                console.error('一覧取得エラー:', err);
                showMsg(tbody, '一覧取得に失敗しました。', true);
            })
            .finally(function(){ clearTimeout(to); });
    }

    function init(scope){
        var container = scope || document;
        var form = container.querySelector('#change-edit-form');
        if (!form || form.dataset.ceBooted === '1') return;

        var sel = container.querySelector('#ce-room-select');
        if (sel && !sel.value && sel.options.length > 0) sel.value = sel.options[0].value;

        form.dataset.ceBooted = '1';
        fetchAndRender(container);
    }

    // グローバル公開
    window.CE_CHANGE_EDIT = { init: init };

    // 直描画時
    document.addEventListener('DOMContentLoaded', function(){
        var root = document.getElementById('ce-root');
        if (root) init(document);
    });

    // モーダル表示時
    document.addEventListener('shown.bs.modal', function(ev){
        var modal = ev.target;
        if (!modal) return;
        if (modal.querySelector && modal.querySelector('#ce-root')) window.CE_CHANGE_EDIT.init(modal);
    });
})();
