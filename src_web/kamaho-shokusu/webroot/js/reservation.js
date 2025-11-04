/* eslint-disable no-console */
(function(){
    function bindOnce(el, type, handler){
        el.addEventListener(type, handler, { once: true });
    }

    // ページ内スクリプトで用意されていれば利用（add.php に埋め込んだ定数）
    const TPL  = typeof window.GET_USERS_BY_ROOM_TPL !== 'undefined' ? window.GET_USERS_BY_ROOM_TPL : null;
    const QDATE = typeof window.QUERY_DATE !== 'undefined' ? window.QUERY_DATE : null;

    // URLビルダ（ページ定数があればそれを優先）
    function buildGetUsersByRoomUrl(roomId, date){
        if (TPL) {
            let url = TPL.indexOf('__RID__') !== -1
                ? TPL.replace('__RID__', encodeURIComponent(roomId))
                : (TPL.replace(/\/$/, '') + '/' + encodeURIComponent(roomId));
            if (date) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(date);
            }
            return url;
        }
        // フォールバック（サブディレクトリを自動検出）
        const base = (function(){
            const parts = location.pathname.split('/').filter(Boolean);
            return parts[0] === 'kamaho-shokusu' ? '/kamaho-shokusu' : '';
        })();
        const u = new URL(base + '/TReservationInfo/getUsersByRoom/' + encodeURIComponent(roomId), window.location.origin);
        if (date) u.searchParams.set('date', date);
        return u.toString();
    }

    function initReservationForm(){
        if (window.__reservationFormInited && document.getElementById('reservation-form')) return;
        if (!document.getElementById('reservation-form')) return;
        window.__reservationFormInited = true;

        const reservationTypeSelect = document.getElementById('c_reservation_type');
        const roomSelectionTable    = document.getElementById('room-selection-table');   // 個人：部屋ごとのチェック
        const roomSelectGroup       = document.getElementById('room-select-group');      // 集団：部屋を選択
        const userSelectionTable    = document.getElementById('user-selection-table');   // 集団：利用者×食事
        const roomCheckboxes        = document.getElementById('room-checkboxes');
        const userCheckboxes        = document.getElementById('user-checkboxes');
        const roomSelect            = document.getElementById('room-select');
        const form                  = document.getElementById('reservation-form');
        const overlay               = document.getElementById('loading-overlay');
        const submitButton          = form ? form.querySelector('button[type="submit"]') : null;
        const initRoomInput         = document.getElementById('__init_room_id'); // モーダル時の初期部屋

        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
        const dateInput = document.querySelector('input[name="d_reservation_date"]');
        const date      = QDATE || (dateInput ? dateInput.value : null);

        const showLoading = () => {
            if (overlay) overlay.style.display = 'block';
            if (submitButton) submitButton.disabled = true;
        };
        const hideLoading = () => {
            if (overlay) overlay.style.display = 'none';
            if (submitButton) submitButton.disabled = false;
        };

        // 正しい表示切替（1=個人 / 2=集団）
        function toggleReservationTypeDisplay(reservationType){
            if (reservationType === 1) {
                // 個人：個人テーブルのみ表示
                if (roomSelectionTable) roomSelectionTable.style.display = 'block';
                if (roomSelectGroup)    roomSelectGroup.style.display    = 'none';
                if (userSelectionTable) userSelectionTable.style.display = 'none';
            } else if (reservationType === 2) {
                // 集団：部屋選択を先に表示、利用者表は部屋選択後
                if (roomSelectionTable) roomSelectionTable.style.display = 'none';
                if (roomSelectGroup)    roomSelectGroup.style.display    = 'block';
                if (userSelectionTable) userSelectionTable.style.display = 'none';
            }
        }
        window.toggleReservationTypeDisplay = toggleReservationTypeDisplay;

        async function fetchUserData(roomId){
            try {
                const url = buildGetUsersByRoomUrl(roomId, date);
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('通信に失敗しました');
                const data = await res.json();
                const users = Array.isArray(data.usersByRoom) ? data.usersByRoom
                    : (Array.isArray(data.users) ? data.users : []);
                if (!Array.isArray(users)) throw new Error('データ形式が不正です');
                if (userCheckboxes) {
                    userCheckboxes.innerHTML = '';
                    users.forEach(function(u){
                        const tr = document.createElement('tr');
                        const morning = Number(u.morning || 0) === 1;
                        const noon    = Number(u.noon || 0) === 1;
                        const night   = Number(u.night || 0) === 1;
                        const bento   = Number(u.bento || 0) === 1;
                        tr.innerHTML =
                            '<td>' + String(u.name) + '</td>' +
                            '<td class="text-center"><input type="checkbox" name="users[' + u.id + '][1]" value="1" ' + (morning ? 'checked' : '') + '></td>' +
                            '<td class="text-center"><input type="checkbox" name="users[' + u.id + '][2]" value="1" ' + (noon ? 'checked' : '') + '></td>' +
                            '<td class="text-center"><input type="checkbox" name="users[' + u.id + '][3]" value="1" ' + (night ? 'checked' : '') + '></td>' +
                            '<td class="text-center"><input type="checkbox" name="users[' + u.id + '][4]" value="1" ' + (bento ? 'checked' : '') + '></td>';
                        userCheckboxes.appendChild(tr);
                    });
                }
                // 利用者リストが描画できたら表を見せる
                if (userSelectionTable) userSelectionTable.style.display = 'block';
            } catch (e) {
                console.error(e);
                alert('利用者の取得に失敗しました。');
            } finally {
                hideLoading();
            }
        }
        window.fetchUserData = fetchUserData;

        // バリデーション（集団=部屋行のチェックが1つ以上必須 / 個人=必須なし）
        function validateForm(reservationType){
            if (reservationType === 2) {
                // 集団: 部屋行のいずれかチェック必須
                if (userSelectionTable && userSelectionTable.querySelector('tbody input[type="checkbox"]:checked')) {
                    return true;
                }
                return false;
            }
            // 個人: 現状は必須なし（利用者チェックは保存時の仕様に依存）
            return true;
        }
        window.validateForm = validateForm;

        // 部屋行の一括チェック（昼(2)と弁当(4)の排他）
        window.toggleAllRooms = function(mealType, checked){
            if (!roomCheckboxes) return;
            roomCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(function(cb){
                const name = cb.getAttribute('name') || '';
                if (name.indexOf('meals[' + mealType + ']') === 0) {
                    // 昼(2)⇔弁当(4) 排他（同じ行で相手を外す）
                    const row = cb.closest('tr');
                    if (mealType === 2 && checked) {
                        const bento = row?.querySelector('input[name^="meals[4]"]');
                        if (bento) bento.checked = false;
                    }
                    if (mealType === 4 && checked) {
                        const lunch = row?.querySelector('input[name^="meals[2]"]');
                        if (lunch) lunch.checked = false;
                    }
                    cb.checked = !!checked;
                }
            });
        };

        // 予約タイプの変更
        if (reservationTypeSelect) {
            reservationTypeSelect.addEventListener('change', function(){
                const reservationType = parseInt(this.value, 10);
                toggleReservationTypeDisplay(reservationType);
            });
            const initialReservationType = parseInt(reservationTypeSelect.value, 10);
            if (!Number.isNaN(initialReservationType)) {
                toggleReservationTypeDisplay(initialReservationType);
            }
        }

        // 集団：部屋選択→利用者取得
        if (roomSelect) {
            roomSelect.addEventListener('change', function(){
                const roomId = this.value;
                if (userCheckboxes) userCheckboxes.innerHTML = '';
                if (roomId) {
                    showLoading();
                    fetchUserData(roomId);
                } else {
                    if (userSelectionTable) userSelectionTable.style.display = 'none';
                }
            });
        }

        // 送信
        if (form) {
            form.addEventListener('submit', function(event){
                event.preventDefault();
                const reservationType   = parseInt(reservationTypeSelect?.value, 10);
                const validationSuccess = validateForm(reservationType);
                if (!validationSuccess) {
                    alert('エラー: 必須項目を確認してください。（集団予約は利用者行でいずれかの食事にチェックが必要です）');
                    return;
                }
                const formData = new FormData(form);
                if (!date) { alert('日付が選択されていません。'); return; }
                formData.append('d_reservation_date', date);
                showLoading();
                fetch(form.action, { method: 'POST', body: formData, headers: { 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
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
                            alert(`エラー: ${data.message || '不明なエラーが発生しました。'}`);
                        } else if (data.status === 'success') {
                            const modalEl = document.getElementById('quickDayModal');
                            const emitDate = (data.data && data.data.date) || date || '';
                            if (modalEl) {
                                modalEl.dispatchEvent(new CustomEvent('reservation:saved', { detail: { date: emitDate } }));
                            } else {
                                alert(`成功: ${data.message}`);
                                if (data.redirect) window.location.href = data.redirect;
                            }
                        }
                    })
                    .catch(error => {
                        if (error.message !== 'HTMLを表示しました') {
                            console.error('送信エラー:', error);
                            alert(`送信エラー: ${error.message}`);
                        }
                    })
                    .finally(hideLoading);
            });
        }

        // --- モーダル対応：初期部屋が埋まっていれば即時 fetch（親が select に値を入れてくれている想定） ---
        (function autoFetchOnModal(){
            if (!initRoomInput) return;                 // 通常ページでは無し
            const rid = initRoomInput.value || (roomSelect ? roomSelect.value : '');
            // 集団タブを想定（親の ensure で reservation type=2 に切り替わっているケースに対応）
            if (reservationTypeSelect && reservationTypeSelect.value === '2' && rid) {
                if (userCheckboxes) userCheckboxes.innerHTML = '<tr><td colspan="5" class="text-center text-muted">読み込み中...</td></tr>';
                showLoading();
                fetchUserData(rid);
            }
        })();
    }

    // expose
    window.initReservationForm = initReservationForm;

    // initialize on DOMContentLoaded（通常ページ）
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReservationForm);
    } else {
        initReservationForm();
    }
    // モーダル表示時にもフォームが挿入されたら再初期化
    document.addEventListener('shown.bs.modal', function(ev){
        const m = ev.target;
        if (m && m.querySelector && m.querySelector('#reservation-form')) {
            try { initReservationForm(); } catch(e){}
        }
    });
})();
