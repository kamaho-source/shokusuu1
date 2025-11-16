<?php
/**
 * 直前編集ビュー（モーダル対応・当日〜14日）
 */

// 部屋の選択状態を確認
$selectedRoomId = null;
$selectedRoomName = '';

if (isset($room) && is_object($room)) {
    $selectedRoomId = $room->i_id_room;
    $selectedRoomName = $room->c_room_name ?? '';
} elseif (is_array($rooms) && !empty($rooms)) {
    $selectedRoomId = array_key_first($rooms);
    $selectedRoomName = $rooms[$selectedRoomId] ?? '';
}

$basePath = $this->Url->build('/', ['fullBase' => false]);
$mealType = $this->request->getParam('mealType') ?? $this->request->getQuery('mealType') ?? 2;
?>
<div id="ce-root"
     data-base="<?= h($basePath) ?>"
     data-date="<?= h($date) ?>"
     data-mealtype="<?= h($mealType) ?>">
    <div class="row">
        <div class="col-md-9 offset-md-1">
            <div class="card">
                <div class="card-header bg-warning-subtle d-flex align-items-center justify-content-between">
                    <h3 class="card-title mb-0">直前予約の変更（当日〜14日）</h3>
                    <div class="small text-muted">対象日：<strong id="ce-date"><?= h($date) ?></strong></div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>ご注意：</strong>職員は直前編集で予約の追加はできますが、既に「食べる」で登録済みの食事を「食べない」に変更（キャンセル）することはできません。子供は追加・キャンセル両方可能です。
                    </div>

                    <?= $this->Form->create(null, ['id'=>'change-edit-form', 'url'=>['action'=>'changeEdit']]) ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">部屋</label>
                            <?php if (!empty($rooms)): ?>
                                <?php if (count($rooms) > 1): ?>
                                    <?= $this->Form->control('i_id_room', [
                                            'type'      => 'select',
                                            'label'     => false,
                                            'options'   => $rooms,
                                            'empty'     => false,
                                            'value'     => $selectedRoomId,
                                            'class'     => 'form-select',
                                            'required'  => true,
                                            'id'        => 'ce-room-select',
                                            'data-date' => $date,
                                    ]) ?>
                                <?php else: ?>
                                    <div class="form-control-plaintext"><?= h($selectedRoomName ?: '（部屋未設定）') ?></div>
                                    <?= $this->Form->hidden('i_id_room', ['value'=>$selectedRoomId, 'id'=>'ce-room-hidden']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">
                                    部屋が設定されていません。管理者に部屋の設定を依頼してください。
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">対象日</label>
                            <div><?= h($date) ?></div>
                            <?= $this->Form->hidden('d_reservation_date', ['value'=>$date, 'id'=>'ce-date-hidden']) ?>
                            <?= $this->Form->hidden('meal_type', ['value'=>$mealType, 'id'=>'ce-mealtype-hidden']) ?>
                        </div>
                    </div>

                    <fieldset>
                        <legend>利用者と予約情報（朝・昼・夜・弁当）</legend>
                        <div id="ce-table-wrap" class="table-responsive">
                            <table class="table table-bordered align-middle" id="ce-table">
                                <thead>
                                <tr>
                                    <th style="min-width:12rem;">利用者名</th>
                                    <th class="text-center">朝<br><input type="checkbox" id="select-all-1" aria-label="朝 全選択/解除"></th>
                                    <th class="text-center">昼<br><input type="checkbox" id="select-all-2" aria-label="昼 全選択/解除"></th>
                                    <th class="text-center">夜<br><input type="checkbox" id="select-all-3" aria-label="夜 全選択/解除"></th>
                                    <th class="text-center">弁当<br><input type="checkbox" id="select-all-4" aria-label="弁当 全選択/解除"></th>
                                </tr>
                                </thead>
                                <tbody id="ce-tbody">
                                <tr><td colspan="5" class="text-center text-muted">読み込み中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </fieldset>

                    <div class="mt-3 d-flex gap-2">
                        <?= $this->Form->button(__('保存'), ['class'=>'btn btn-primary']) ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">閉じる</button>
                    </div>

                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function(){
        'use strict';

        function esc(s){
            s = (s == null ? '' : String(s));
            return s.replace(/[&<>"']/g, function(m){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
            });
        }

        function isStaffUser(u){
            if (u && typeof u.isStaff === 'boolean') return u.isStaff;
            return Number((u && (u.i_user_level != null ? u.i_user_level : u.userLevel))) === 0;
        }

        function resolveMealType(container){
            var hidden = container.querySelector('#ce-mealtype-hidden');
            if (hidden && hidden.value) return String(hidden.value);
            var root = container.querySelector('#ce-root') || container;
            var dt = root && root.getAttribute('data-mealtype');
            if (dt) return String(dt);
            return '';
        }

        function apiUrl(base, roomId, dateStr, mealType){
            base = base || '/';
            if (base.slice(-1) !== '/') base += '/';
            var url = base + 'TReservationInfo/change-edit/' +
                encodeURIComponent(roomId) + '/' +
                encodeURIComponent(dateStr) + '/' +
                encodeURIComponent(mealType);
            console.log('API URL:', url);
            return url;
        }

        function showLoading(tbody){
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' +
                '<div class="spinner-border spinner-border-sm" role="status"></div>' +
                '<span class="ms-2">読み込み中...</span></td></tr>';
        }

        function showMsg(tbody, msg, isError){
            tbody.innerHTML = '<tr><td colspan="5" class="text-center ' +
                (isError?'text-danger':'text-muted') + '">' + esc(msg) + '</td></tr>';
        }

        function createRowHTML(user, flagsByType){
            var uId = esc(user.id);
            var uName = esc(user.name);
            var allow = !!user.allowEdit;
            var isStaff = isStaffUser(user);
            var cells = '';

            for (var t=1; t<=4; t++){
                var f = flagsByType[t] || {};
                var initiallyOn = Number(f.i_change_flag || 0) === 1;
                var checked = initiallyOn ? ' checked' : '';
                var disabled = allow ? '' : ' disabled';
                var initAttr = initiallyOn ? ' data-initial-checked="1"' : '';

                cells += '<td class="text-center">' +
                    '<input type="checkbox"' +
                    ' name="users['+uId+']['+t+']"' +
                    ' class="meal-checkbox"' +
                    ' data-reservation-type="'+t+'"' +
                    ' data-user-id="'+uId+'"' +
                    ' value="1"'+checked+disabled+initAttr+'></td>';
            }

            var staffAttr = isStaff ? ' data-is-staff="1"' : '';
            return '<tr data-user-id="'+uId+'"'+staffAttr+'><td>'+uName+'</td>'+cells+'</tr>';
        }

        function toggleColumn(container, reservationType, checked){
            var tbody = container.querySelector('#ce-tbody');
            if (!tbody) return;

            tbody.querySelectorAll('input.meal-checkbox[data-reservation-type="'+reservationType+'"]')
                .forEach(function(cb){
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

        function installUncheckGuards(tbody){
            if (!tbody) return;
            var lockList = tbody.querySelectorAll('tr[data-is-staff="1"] input.meal-checkbox[data-initial-checked="1"]');
            lockList.forEach(function(cb){
                if (cb.checked && !cb.disabled) {
                    cb.disabled = true;
                    cb.dataset.locked = '1';
                    cb.title = '職員の予約は直前変更画面からは解除できません。';
                    cb.classList.add('deletion-blocked');
                }
            });
        }

        function installLunchBentoExclusion(tbody){
            if (!tbody) return;
            var allCbs = tbody.querySelectorAll('input.meal-checkbox');
            allCbs.forEach(function(cb){
                if (cb.dataset.lunchBentoListenerAdded === '1') return;
                cb.addEventListener('change', function(e){
                    var type = e.target.getAttribute('data-reservation-type');
                    var tr = e.target.closest('tr');
                    if (!tr || e.target.disabled || e.target.dataset.locked === '1') return;

                    if (type === '2' && e.target.checked) {
                        var bento = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
                        if (bento && !bento.disabled && bento.dataset.locked !== '1') bento.checked = false;
                    }
                    if (type === '4' && e.target.checked) {
                        var lunch = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                        if (lunch && !lunch.disabled && lunch.dataset.locked !== '1') lunch.checked = false;
                    }
                });
                cb.dataset.lunchBentoListenerAdded = '1';
            });
        }

        function fetchAndRender(container){
            var root = container.querySelector('#ce-root') || container;
            var base = root.getAttribute('data-base') || '/';
            var form = container.querySelector('#change-edit-form');
            var roomSelect = container.querySelector('#ce-room-select');
            var roomHidden = container.querySelector('#ce-room-hidden');
            var tbody = container.querySelector('#ce-tbody');
            var dateHidden = container.querySelector('#ce-date-hidden');

            if (!tbody) return;

            var roomId = (roomSelect && roomSelect.value) || (roomHidden && roomHidden.value);
            var date = (dateHidden && dateHidden.value);
            var meal = resolveMealType(container);

            console.log('fetchAndRender called:', {roomId, date, meal, hasRoomSelect: !!roomSelect, hasRoomHidden: !!roomHidden});

            if (!roomId) { 
                showMsg(tbody, '部屋が選択されていません。管理画面で部屋を設定してください。', true); 
                return; 
            }
            if (!date)   { showMsg(tbody, '日付が不正です。', true); return; }
            if (!meal)   { showMsg(tbody, '食種(mealType)が不正です。', true); return; }

            showLoading(tbody);

            var ctrl = new AbortController();
            var to = setTimeout(function(){ ctrl.abort(); }, 12000);

            fetch(apiUrl(base, roomId, date, meal), {
                method: 'GET',
                headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: ctrl.signal
            })
                .then(function(res){
                    return res.text().then(function(text){
                        var json;
                        try { json = JSON.parse(text); }
                        catch(e) {
                            console.error('JSON解析失敗:', text.slice(0,500));
                            console.error('Response status:', res.status);
                            throw new Error('JSON解析失敗: ' + text.slice(0, 100));
                        }
                        return { ok: res.ok, json: json, status: res.status };
                    });
                })
                .then(function(pair){
                    var json = pair.json;
                    
                    console.log('API Response:', {
                        ok: pair.ok,
                        status: pair.status,
                        jsonStatus: json ? json.status : null,
                        hasData: json && json.data ? true : false
                    });

                    // エラーレスポンスのチェック
                    if (!pair.ok) {
                        var errMsg = (json && json.message) || 'サーバーエラー (HTTP ' + pair.status + ')';
                        console.error('Server error:', errMsg);
                        showMsg(tbody, errMsg, true);
                        return;
                    }

                    // ★ 構造正規化: usersByRoom, data.users, users 全対応
                    var users = [];
                    var flags = {};

                    if (json.status === 'success' && json.data) {
                        users = Array.isArray(json.data.users) ? json.data.users : [];
                        flags = json.data.userReservations || {};
                    } else if (Array.isArray(json.usersByRoom)) {
                        // 本番レスポンス形式
                        users = json.usersByRoom;
                        // 各ユーザーのフラグを抽出
                        users.forEach(function(u){
                            var uid = String(u.id);
                            flags[uid] = {};
                            // morning, noon, night, bento をフラグに変換
                            if (u.morning !== undefined) flags[uid][1] = { i_change_flag: u.morning ? 1 : 0 };
                            if (u.noon !== undefined)    flags[uid][2] = { i_change_flag: u.noon ? 1 : 0 };
                            if (u.night !== undefined)   flags[uid][3] = { i_change_flag: u.night ? 1 : 0 };
                            if (u.bento !== undefined)   flags[uid][4] = { i_change_flag: u.bento ? 1 : 0 };
                        });
                    } else if (Array.isArray(json.users)) {
                        users = json.users;
                    }

                    if (users.length === 0) {
                        showMsg(tbody, '該当する利用者がいません。');
                        return;
                    }

                    var html = '';
                    users.forEach(function(u){
                        html += createRowHTML(u, flags[String(u.id)] || {});
                    });
                    tbody.innerHTML = html;

                    installUncheckGuards(tbody);
                    installLunchBentoExclusion(tbody);
                    bindHeaderChecks(container);

                    // 保存処理
                    var csrfMeta = document.querySelector('meta[name="csrfToken"]');
                    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : null;

                    if (form && form.dataset.submitBound !== '1') {
                        form.dataset.submitBound = '1';
                        form.addEventListener('submit', function(e){
                            e.preventDefault();

                            var rId = (roomSelect && roomSelect.value) || (roomHidden && roomHidden.value);
                            var d = (dateHidden && dateHidden.value);
                            var m = resolveMealType(container);

                            if (!rId || !d || !m) {
                                alert('パラメータが不正です。');
                                return;
                            }

                            var usersPayload = {};
                            var errors = [];
                            var trs = tbody.querySelectorAll('tr[data-user-id]');

                            Array.prototype.forEach.call(trs, function(tr){
                                var uid = tr.getAttribute('data-user-id');
                                if (!uid) return;

                                var lunchCb = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                                var bentoCb = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');

                                if (lunchCb && bentoCb && lunchCb.checked && bentoCb.checked) {
                                    var userName = tr.querySelector('td:first-child');
                                    errors.push((userName ? userName.textContent : 'ユーザーID:' + uid) +
                                        ' の昼食と弁当が両方選択されています。');
                                }

                                var obj = {};
                                var hasChange = false;

                                for (var t=1; t<=4; t++){
                                    var cb = tr.querySelector('input.meal-checkbox[data-reservation-type="'+t+'"]');
                                    if (!cb) continue;

                                    var isChecked = !!cb.checked;
                                    var wasChecked = cb.getAttribute('data-initial-checked') === '1';
                                    var changeFlag = 0;

                                    if (isChecked && !wasChecked) {
                                        changeFlag = 1;
                                    } else if (!isChecked && wasChecked) {
                                        changeFlag = 2;
                                    }

                                    if (changeFlag > 0) {
                                        obj[String(t)] = { i_change_flag: changeFlag };
                                        hasChange = true;
                                    }
                                }

                                if (hasChange) usersPayload[String(uid)] = obj;
                            });

                            if (errors.length > 0) {
                                alert('エラー:\n' + errors.join('\n'));
                                return;
                            }

                            if (Object.keys(usersPayload).length === 0) {
                                alert('変更された項目がありません。');
                                return;
                            }

                            var headers = {
                                'Content-Type':'application/json',
                                'Accept':'application/json',
                                'X-Requested-With':'XMLHttpRequest'
                            };
                            if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                            fetch(apiUrl(base, rId, d, m), {
                                method:'POST',
                                headers: headers,
                                credentials:'same-origin',
                                body: JSON.stringify({ users: usersPayload })
                            })
                                .then(function(res2){
                                    return res2.json().then(function(j){ return { ok: res2.ok, j:j }; });
                                })
                                .then(function(pair2){
                                    var json2 = pair2.j;
                                    if (!pair2.ok || !json2 || json2.status !== 'success') {
                                        alert((json2 && json2.message) || '更新に失敗しました。');
                                        return;
                                    }

                                    alert('直前予約を更新しました。');

                                    var modalEl = container.closest('.modal');
                                    if (modalEl && typeof bootstrap !== 'undefined') {
                                        var inst = bootstrap.Modal.getInstance(modalEl);
                                        if (inst) inst.hide();
                                    }

                                    window.location.reload();
                                })
                                .catch(function(){ alert('保存リクエスト送信に失敗しました。'); });
                        });
                    }

                    if (roomSelect && roomSelect.dataset.changeBound !== '1') {
                        roomSelect.dataset.changeBound = '1';
                        roomSelect.addEventListener('change', function(){
                            fetchAndRender(container);
                        });
                    }
                })
                .catch(function(err){
                    console.error('一覧取得エラー:', err);
                    console.error('Error details:', {
                        name: err.name,
                        message: err.message,
                        stack: err.stack
                    });
                    showMsg(tbody, '一覧取得に失敗しました: ' + err.message, true);
                })
                .finally(function(){ clearTimeout(to); });
        }

        function init(scope){
            var container = scope || document;
            var form = container.querySelector('#change-edit-form');
            if (!form || form.dataset.ceBooted === '1') return;

            form.dataset.ceBooted = '1';

            // 初期部屋選択の確認
            var sel = container.querySelector('#ce-room-select');
            var hidden = container.querySelector('#ce-room-hidden');
            
            console.log('CE init:', {
                hasSelect: !!sel,
                hasHidden: !!hidden,
                selectValue: sel ? sel.value : null,
                hiddenValue: hidden ? hidden.value : null,
                selectOptions: sel ? sel.options.length : 0
            });
            
            if (sel) {
                if (!sel.value && sel.options.length > 0) {
                    sel.value = sel.options[0].value;
                    console.log('Auto-selected first room:', sel.value);
                }
            }

            // 初回描画
            setTimeout(function(){ fetchAndRender(container); }, 50);
        }

        window.CE_CHANGE_EDIT = { init: init };

        document.addEventListener('DOMContentLoaded', function(){
            var root = document.getElementById('ce-root');
            if (root) init(document);
        });

        document.addEventListener('shown.bs.modal', function(ev){
            var modal = ev.target;
            if (!modal || !modal.querySelector) return;
            if (modal.querySelector('#ce-root')) {
                window.CE_CHANGE_EDIT.init(modal);
            }
        });
    })();
</script>
