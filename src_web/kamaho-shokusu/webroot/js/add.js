// javascript
/* eslint-disable no-console */
/* eslint-env browser */
(function(){
    // --- ページ埋め込み定数（add.php が出していればそれを使う） ---
    const TPL   = typeof window.GET_USERS_BY_ROOM_TPL !== 'undefined' ? window.GET_USERS_BY_ROOM_TPL : null;
    const QDATE = typeof window.QUERY_DATE !== 'undefined' ? window.QUERY_DATE : null;

    function buildGetUsersByRoomUrl(roomId, date){
        if (TPL) {
            let url = TPL.indexOf('__RID__') !== -1
                ? TPL.replace('__RID__', encodeURIComponent(roomId))
                : (TPL.replace(/\/$/, '') + '/' + encodeURIComponent(roomId));
            if (date) url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(date);
            return url;
        }
        // フォールバック（/kamaho-shokusu のようなサブディレクトリ判定）
        const base = (function(){
            const parts = location.pathname.split('/').filter(Boolean);
            return parts[0] === 'kamaho-shokusu' ? '/kamaho-shokusu' : '';
        })();
        const u = new URL(base + '/TReservationInfo/getUsersByRoom/' + encodeURIComponent(roomId), window.location.origin);
        if (date) u.searchParams.set('date', date);
        return u.toString();
    }

    function init(container){
        // 多重初期化防止
        const root = (container && container.querySelector) ? (container.querySelector('#ce-root') || container) : document;
        if (root.__ADD_FORM_BOOTED__) return;
        
        // If reservation.js has already initialized, skip our form handling
        if (window.__reservationFormInited && root.querySelector('#reservation-form')) {
            return;
        }
        
        root.__ADD_FORM_BOOTED__ = true;

        // DOM 参照
        const form               = root.querySelector('#reservation-form');
        const reservationTypeSel = root.querySelector('#c_reservation_type');
        const roomSelectionTable = root.querySelector('#room-selection-table'); // 個人：部屋ごとのチェック
        const roomSelectGroup    = root.querySelector('#room-select-group');    // 集団：部屋選択
        const userSelectionTable = root.querySelector('#user-selection-table'); // 集団：利用者×食事
        const roomCheckboxesTbody= root.querySelector('#room-checkboxes');
        const userCheckboxesTbody= root.querySelector('#user-checkboxes');
        const roomSelect         = root.querySelector('#room-select');
        const overlay            = root.querySelector('#loading-overlay');
        const submitButton       = form ? form.querySelector('button[type="submit"]') : null;

        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
        const dateInput = root.querySelector('input[name="d_reservation_date"]');
        const date      = QDATE || (dateInput ? dateInput.value : null);

        const showLoading = () => { if (overlay) overlay.style.display = 'block'; if (submitButton) submitButton.disabled = true; };
        const hideLoading = () => { if (overlay) overlay.style.display = 'none';  if (submitButton) submitButton.disabled = false; };

        // ユーティリティ: 表示/非表示を d-none で制御（存在チェック付き）
        const showEl = (el) => { if (!el) return; el.classList.remove('d-none'); };
        const hideEl = (el) => { if (!el) return; el.classList.add('d-none'); };

        // 1=個人 / 2=集団 で表示を切替
        function toggleReservationTypeDisplay(type){
            const typeStr = String(type);
            if (typeStr === '1') {
                showEl(roomSelectionTable);
                hideEl(roomSelectGroup);
                hideEl(userSelectionTable);
            } else if (typeStr === '2') {
                hideEl(roomSelectionTable);
                showEl(roomSelectGroup);
                hideEl(userSelectionTable); // 部屋選択後に表示
            } else {
                // ★★★ 修正: typeが未定義または空の場合、個人をデフォルト表示にする
                showEl(roomSelectionTable);
                hideEl(roomSelectGroup);
                hideEl(userSelectionTable);
            }
        }

        // 利用者取得＆描画
        async function fetchAndRenderUsers(roomId){
            if (!roomId) {
                if (userCheckboxesTbody) userCheckboxesTbody.innerHTML = '';
                hideEl(userSelectionTable);
                return;
            }
            
            showLoading();
            try{
                const url = buildGetUsersByRoomUrl(roomId, date);
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                
                const json = await res.json();
                const users = Array.isArray(json.usersByRoom) ? json.usersByRoom
                    : (Array.isArray(json.users) ? json.users : []);
                    
                if (!userCheckboxesTbody) return;

                userCheckboxesTbody.innerHTML = '';
                if (users.length === 0) {
                    userCheckboxesTbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">該当する利用者がいません。</td></tr>';
                } else {
                    users.forEach(u => {
                        const tr = document.createElement('tr');
                        
                        // 安全にデータを取得
                        const userId = u.id || u.user_id || '';
                        const userName = u.name || u.user_name || '名前不明';
                        
                        // 予約状態を安全に取得（boolean変換）
                        const morning = Boolean(u.morning === 1 || u.morning === '1' || u.morning === true);
                        const noon = Boolean(u.noon === 1 || u.noon === '1' || u.noon === true);
                        const night = Boolean(u.night === 1 || u.night === '1' || u.night === true);
                        const bento = Boolean(u.bento === 1 || u.bento === '1' || u.bento === true);
                        
                        // 昼食と弁当の排他制御（既存予約がある場合）
                        const hasNoon = noon && !bento;
                        const hasBento = bento && !noon;
                        
                        tr.innerHTML =
                            `<td>${String(userName).replace(/[<>&"']/g, function(c) {
                                return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];
                            })}</td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${userId}][1]" value="1" ${morning ? 'checked' : ''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${userId}][2]" value="1" ${hasNoon ? 'checked' : ''} ${hasBento ? 'disabled title="弁当と同時選択不可"' : ''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${userId}][3]" value="1" ${night ? 'checked' : ''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${userId}][4]" value="1" ${hasBento ? 'checked' : ''} ${hasNoon ? 'disabled title="昼食と同時選択不可"' : ''}></td>`;
                        
                        userCheckboxesTbody.appendChild(tr);
                    });
                    
                    // 昼食と弁当の排他制御を動的に適用
                    setTimeout(() => {
                        if (typeof window.applyLunchBentoExclusion === 'function') {
                            window.applyLunchBentoExclusion(userSelectionTable);
                        } else {
                            // フォールバック: 手動で排他制御を適用
                            const rows = userCheckboxesTbody.querySelectorAll('tr');
                            rows.forEach(row => {
                                const lunchCb = row.querySelector('input[name$="[2]"]');
                                const bentoCb = row.querySelector('input[name$="[4]"]');
                                if (lunchCb && bentoCb) {
                                    lunchCb.addEventListener('change', function() {
                                        if (lunchCb.checked) {
                                            bentoCb.checked = false;
                                            bentoCb.disabled = true;
                                            bentoCb.title = '昼食と同時選択不可';
                                        } else {
                                            bentoCb.disabled = false;
                                            bentoCb.title = '';
                                        }
                                    });
                                    bentoCb.addEventListener('change', function() {
                                        if (bentoCb.checked) {
                                            lunchCb.checked = false;
                                            lunchCb.disabled = true;
                                            lunchCb.title = '弁当と同時選択不可';
                                        } else {
                                            lunchCb.disabled = false;
                                            lunchCb.title = '';
                                        }
                                    });
                                }
                            });
                        }
                    }, 100);
                }
                // 一覧を可視化（d-none を外す）
                showEl(userSelectionTable);
            } catch(e){
                console.error('ユーザ取得失敗:', e);
                if (userCheckboxesTbody) {
                    userCheckboxesTbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました。</td></tr>';
                }
            } finally {
                hideLoading();
            }
        }

        // 個人テーブルのヘッダ「全選択」チェックと行内昼⇔弁当の排他
        function bindRoomTableGuards(){
            if (!roomCheckboxesTbody) return;
            roomCheckboxesTbody.querySelectorAll('tr').forEach(tr => {
                const lunch = tr.querySelector('input[name^="meals[2]"]');
                const bento = tr.querySelector('input[name^="meals[4]"]');
                if (lunch && bento) {
                    lunch.addEventListener('change', () => { if (lunch.checked) bento.checked = false; });
                    bento.addEventListener('change', () => { if (bento.checked) lunch.checked = false; });
                }
            });
        }

        // イベント: 予約タイプ変更
        if (reservationTypeSel) {
            reservationTypeSel.addEventListener('change', (e) => {
                const val = e.target.value;
                toggleReservationTypeDisplay(val);

                if (String(val) === '2') { // 集団
                    const rid = roomSelect?.value || '';
                    if (rid) fetchAndRenderUsers(rid);
                }
            });
        }

        // イベント: 集団の部屋選択
        if (roomSelect) {
            roomSelect.addEventListener('change', () => {
                const rid = roomSelect.value;
                if (!rid) {
                    if (userCheckboxesTbody) userCheckboxesTbody.innerHTML = '';
                    hideEl(userSelectionTable);
                    return;
                }
                fetchAndRenderUsers(rid);
            });
        }

        // Only handle form submission if reservation.js hasn't already initialized it
        if (form && !window.__reservationFormInited) {
            form.addEventListener('submit', (ev) => {
                ev.preventDefault();
                
                // バリデーション
                const reservationType = reservationTypeSel?.value;
                if (reservationType === '2') {
                    // 集団の場合：利用者のチェックが1つ以上必要
                    const userChecked = userSelectionTable && userSelectionTable.querySelector('input[type="checkbox"]:checked');
                    if (!userChecked) {
                        if (typeof window.pageToast === 'function') {
                            window.pageToast('エラー: 集団予約は利用者行でいずれかの食事にチェックが必要です。', 'danger');
                        } else {
                            alert('エラー: 集団予約は利用者行でいずれかの食事にチェックが必要です。');
                        }
                        return;
                    }
                }
                
                const formData = new FormData(form);
                if (!date) { 
                    if (typeof window.pageToast === 'function') {
                        window.pageToast('日付が選択されていません。', 'danger');
                    } else {
                        alert('日付が選択されていません。');
                    }
                    return; 
                }
                formData.append('d_reservation_date', date);
                
                // 集団予約の場合、部屋IDが正しく設定されているかチェック
                if (reservationType === '2') {
                    const roomId = formData.get('i_id_room') || roomSelect?.value;
                    if (!roomId) {
                        if (typeof window.pageToast === 'function') {
                            window.pageToast('エラー: 部屋を選択してください。', 'danger');
                        } else {
                            alert('エラー: 部屋を選択してください。');
                        }
                        return;
                    }
                    // 部屋IDが明示的に設定されていない場合は追加
                    if (!formData.get('i_id_room') && roomSelect?.value) {
                        formData.append('i_id_room', roomSelect.value);
                    }
                    console.log('集団予約 - 選択された部屋ID:', roomId);
                }
                
                showLoading();
                
                fetch(form.action, { 
                    method: 'POST', 
                    body: formData, 
                    headers: { 
                        'X-CSRF-Token': csrfToken, 
                        'X-Requested-With': 'XMLHttpRequest', 
                        'Accept': 'application/json' 
                    }
                })
                .then(response => {
                    const contentType = response.headers.get('Content-Type');
                    if (contentType && contentType.includes('application/json')) return response.json();
                    // 通常遷移HTMLが返ってきた場合（非モーダルのときなど）
                    return response.text().then(html => {
                        const container = document.createElement('div');
                        container.innerHTML = html;
                        document.body.innerHTML = '';
                        document.body.appendChild(container);
                        throw new Error('HTMLを表示しました');
                    });
                })
                .then(data => {
                    if (data.status === 'error') {
                        if (typeof window.pageToast === 'function') {
                            window.pageToast(data.message || '不明なエラーが発生しました。', 'danger');
                        } else {
                            alert(`エラー: ${data.message || '不明なエラーが発生しました。'}`);
                        }
                    } else if (data.status === 'success') {
                        // 成功メッセージを表示
                        if (typeof window.pageToast === 'function') {
                            window.pageToast('登録が完了しました', 'success');
                        }
                        
                        // モーダル要素を取得
                        const modalEl = form.closest('.modal') || document.getElementById('quickDayModal');
                        const emitDate = (data.data && data.data.date) || date || '';
                        
                        if (modalEl) {
                            console.log('Modal found, attempting to close:', modalEl.id);
                            
                            // 複数の方法でモーダルを閉じる試行
                            // 1. reservation:savedイベントを発火（既存の仕組み）
                            modalEl.dispatchEvent(new CustomEvent('reservation:saved', { detail: { date: emitDate } }));
                            
                            // 2. Bootstrap Modal APIで即座に閉じる
                            const modalInstance = window.bootstrap?.Modal.getInstance(modalEl);
                            if (modalInstance) {
                                modalInstance.hide();
                            } else {
                                try {
                                    const newInstance = new window.bootstrap.Modal(modalEl);
                                    newInstance.hide();
                                } catch (e) {
                                    // 3. 手動でモーダルを閉じる
                                    modalEl.classList.remove('show');
                                    modalEl.style.display = 'none';
                                    modalEl.setAttribute('aria-hidden', 'true');
                                    modalEl.removeAttribute('aria-modal');
                                    modalEl.removeAttribute('role');
                                    
                                    // body クラスとバックドロップを削除
                                    document.body.classList.remove('modal-open');
                                    document.body.style.removeProperty('overflow');
                                    document.body.style.removeProperty('padding-right');
                                    
                                    const backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) {
                                        backdrop.remove();
                                    }
                                }
                            }
                            
                            // 4. カレンダーの更新
                            if (window.calendar && typeof window.calendar.refetchEvents === 'function') {
                                window.calendar.refetchEvents();
                            }
                        } else {
                            // モーダルでない場合（通常ページ）
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                // ページを更新して結果を反映
                                window.location.reload();
                            }
                        }
                    }
                })
                .catch(error => {
                    if (error.message !== 'HTMLを表示しました') {
                        console.error('送信エラー:', error);
                        if (typeof window.pageToast === 'function') {
                            window.pageToast(`送信エラー: ${error.message}`, 'danger');
                        } else {
                            alert(`送信エラー: ${error.message}`);
                        }
                    }
                })
                .finally(hideLoading);
            }, { once:false });
        }

        // ★★★ 修正: 初期表示ロジックの修正 ★★★
        bindRoomTableGuards();
        const initialTypeRaw = reservationTypeSel?.value;
        // 予約タイプが未選択の場合でも `toggleReservationTypeDisplay` を呼び出すことで、
        // デフォルト（個人）の表示が適用されるようにする
        toggleReservationTypeDisplay(initialTypeRaw);

        // もし初期タイプが集団なら、利用者取得を試みる
        if (String(initialTypeRaw) === '2') {
            const rid = roomSelect?.value || '';
            if (rid) fetchAndRenderUsers(rid);
        }
    }

    // safety wrapper: 明示的呼び出し用のラッパー（loadInto 等から安全に呼べる）
    function safeInit(host){
        try {
            if (typeof init === 'function') {
                try {
                    // host が未指定なら document を利用
                    init(host || document);
                } catch(e) {
                    console.error('[ADD_RESERVATION.init] error:', e);
                }
            } else {
                console.warn('[ADD_RESERVATION] init not found. UI might be misconfigured.');
            }
        } catch(e){
            console.error('[ADD_RESERVATION.safeInit] error:', e);
        }
    }

    // 公開
    window.ADD_RESERVATION = { init, safeInit };

    // 各種タイミングでの初期化
    document.addEventListener('DOMContentLoaded', () => window.ADD_RESERVATION.safeInit(document));

    document.addEventListener('shown.bs.modal', (ev) => {
        const modal = ev.target;
        if (!modal) return;
        // Only handle if reservation.js hasn't already initialized
        if (modal.querySelector?.('#reservation-form') && !window.__reservationFormInited) {
            window.ADD_RESERVATION.safeInit(modal);
            
            // 排他制御を適用
            setTimeout(() => {
                if (typeof window.applyLunchBentoExclusion === 'function') {
                    window.applyLunchBentoExclusion(modal);
                }
            }, 200);
        }
    });

    if (document.readyState !== 'loading') {
        try { window.ADD_RESERVATION.safeInit(document); } catch(e) {}
    }

    // 追加: 動的に挿入された断片を検出して自動初期化する（loadInto 等で使用）
    (function autoInitInsertedFragments(){
        if (!window.MutationObserver || !document.body) return;
        const matcher = (node) => {
            if (!(node instanceof HTMLElement)) return false;
            if (node.id === 'ce-root' || node.id === 'reservation-form') return true;
            try {
                return !!(node.querySelector && (node.querySelector('#ce-root') || node.querySelector('#reservation-form')));
            } catch(e) {
                return false;
            }
        };

        const mo = new MutationObserver((records) => {
            for (const rec of records) {
                for (const added of Array.from(rec.addedNodes)) {
                    try {
                        if (matcher(added)) {
                            // 追加されたノード自体をホストとして渡す
                            try { window.ADD_RESERVATION?.safeInit(added); } catch(e){ console.error('[ADD_RESERVATION.init] error:', e); }
                        } else if (added.querySelector) {
                            // 追加ノードの内部にマッチがあればそのホストを init
                            const host = added.querySelector('#ce-root') || added.querySelector('#reservation-form');
                            if (host) {
                                try { window.ADD_RESERVATION?.safeInit(host.closest('.modal') || added); } catch(e){ console.error('[ADD_RESERVATION.init] error:', e); }
                            }
                        }
                    } catch(e){}
                }
            }
        });

        mo.observe(document.body, { childList: true, subtree: true });
        // safety: 既に存在する可能性のある断片に対しても一度走らせる
        try {
            const existing = document.querySelector('#ce-root') || document.querySelector('#reservation-form');
            if (existing) {
                try { window.ADD_RESERVATION?.safeInit(existing.closest('.modal') || document); } catch(e){ console.error(e); }
            }
        } catch(e){}
    })();

})();
