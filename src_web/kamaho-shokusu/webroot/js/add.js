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
        const initRoomInput      = root.querySelector('#__init_room_id');       // add.php が出している初期部屋

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
            if (!roomId) return;
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
                        tr.innerHTML =
                            `<td>${String(u.name)}</td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${u.id}][1]" value="1" ${Number(u.morning)==1?'checked':''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${u.id}][2]" value="1" ${Number(u.noon)==1?'checked':''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${u.id}][3]" value="1" ${Number(u.night)==1?'checked':''}></td>` +
                            `<td class="text-center"><input type="checkbox" name="users[${u.id}][4]" value="1" ${Number(u.bento)==1?'checked':''}></td>`;
                        userCheckboxesTbody.appendChild(tr);
                    });
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
                    const rid = roomSelect?.value || initRoomInput?.value || '';
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

        if (form) {
            form.addEventListener('submit', (ev) => {
                // 同期送信
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
            const rid = roomSelect?.value || initRoomInput?.value || '';
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
        // ce-rootはchangeEdit用なので、reservation-formの存在で判断
        if (modal.querySelector?.('#reservation-form')) {
            window.ADD_RESERVATION.safeInit(modal);
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
