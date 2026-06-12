/* eslint-disable no-console */
(function(){
    function bindOnce(el, type, handler){
        el.addEventListener(type, handler, { once: true });
    }

    const QDATE = typeof window.QUERY_DATE !== 'undefined' ? window.QUERY_DATE : null;

    function initReservationForm(){
        if (window.__reservationFormInited && document.getElementById('reservation-form')) return;
        if (!document.getElementById('reservation-form')) return;
        window.__reservationFormInited = true;

        const reservationTypeSelect = document.getElementById('c_reservation_type');
        const roomSelectionTable    = document.getElementById('room-selection-table');
        const roomSelectGroup       = document.getElementById('room-select-group');
        const userSelectionTable    = document.getElementById('user-selection-table');
        const roomCheckboxes        = document.getElementById('room-checkboxes');
        const userCheckboxes        = document.getElementById('user-checkboxes');
        const roomSelect            = document.getElementById('room-select');
        const form                  = document.getElementById('reservation-form');
        const overlay               = document.getElementById('loading-overlay');
        const submitButton          = form ? form.querySelector('button[type="submit"]') : null;
        const initRoomInput         = document.getElementById('__init_room_id');

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

        const showEl = (el) => { if (el) el.classList.remove('d-none'); };
        const hideEl = (el) => { if (el) el.classList.add('d-none'); };

        function toggleReservationTypeDisplay(reservationType){
            if (reservationType === 1) {
                showEl(roomSelectionTable);
                hideEl(roomSelectGroup);
                hideEl(userSelectionTable);
            } else if (reservationType === 2) {
                hideEl(roomSelectionTable);
                showEl(roomSelectGroup);
                hideEl(userSelectionTable);
            }
        }
        window.toggleReservationTypeDisplay = toggleReservationTypeDisplay;

        async function fetchUserData(roomId){
            showLoading();
            try {
                const RU = window.ReservationUsers;
                if (!RU) throw new Error('ReservationUsers not loaded');
                await RU.fetchAndRender(roomId, userCheckboxes, userSelectionTable);
            } catch (e) {
                console.error(e);
                if (typeof window.pageToast === 'function') {
                    window.pageToast('利用者の取得に失敗しました。', 'danger');
                }
            } finally {
                hideLoading();
            }
        }
        window.fetchUserData = fetchUserData;

        function validateForm(reservationType){
            if (reservationType === 2) {
                if (userSelectionTable && userSelectionTable.querySelector('tbody input[type="checkbox"]:checked')) {
                    return true;
                }
                if (userSelectionTable && userSelectionTable.querySelector('tbody input[type="checkbox"][data-existing="1"]')) {
                    return true;
                }
                return false;
            }
            return true;
        }
        window.validateForm = validateForm;

        window.toggleAllRooms = function(mealType, checked){
            if (!roomCheckboxes) return;
            roomCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(function(cb){
                const name = cb.getAttribute('name') || '';
                if (name.indexOf('meals[' + mealType + ']') === 0) {
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

        if (roomSelect) {
            roomSelect.addEventListener('change', function(){
                const roomId = this.value;
                if (userCheckboxes) userCheckboxes.innerHTML = '';
                if (roomId) {
                    fetchUserData(roomId);
                } else {
                    hideEl(userSelectionTable);
                }
            });
        }

        if (form) {
            const toast = (msg, type) => {
                if (typeof window.pageToast === 'function') {
                    window.pageToast(msg, type);
                } else {
                    console.warn('[pageToast 未定義]', type, msg);
                }
            };

            form.addEventListener('submit', function(event){
                event.preventDefault();
                const reservationType   = parseInt(reservationTypeSelect?.value, 10);
                const validationSuccess = validateForm(reservationType);
                if (!validationSuccess) {
                    toast('エラー: 必須項目を確認してください。（集団予約は利用者行でいずれかの食事にチェックが必要です）', 'danger');
                    return;
                }
                const formData = new FormData(form);
                form.querySelectorAll('input[type="checkbox"][name]').forEach(cb => {
                    formData.set(cb.name, cb.checked ? (cb.value || '1') : '0');
                });
                if (!date) {
                    toast('日付が選択されていません。', 'danger');
                    return;
                }
                formData.append('d_reservation_date', date);
                showLoading();
                fetch(form.action, { method: 'POST', body: formData, headers: { 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
                    .then(response => {
                        const contentType = response.headers.get('Content-Type');
                        if (contentType && contentType.includes('application/json')) return response.json();
                        return response.text().then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            document.body.innerHTML = '';
                            Array.from(doc.body.childNodes).forEach(n => document.body.appendChild(document.adoptNode(n)));
                            throw new Error('HTMLを表示しました');
                        });
                    })
                    .then(data => {
                        const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                        if (payload.status === 'error' || payload.ok === false) {
                            toast(payload.message || '不明なエラーが発生しました。', 'danger');
                        } else if (payload.status === 'success' || payload.ok === true) {
                            const modalEl = document.getElementById('quickDayModal');
                            const emitDate = payload.date || date || '';
                            if (modalEl) {
                                modalEl.dispatchEvent(new CustomEvent('reservation:saved', { detail: { date: emitDate } }));
                            } else {
                                toast(payload.message || '登録が完了しました', 'success');
                                if (payload.redirect) window.location.href = payload.redirect;
                            }
                        }
                    })
                    .catch(error => {
                        if (error.message !== 'HTMLを表示しました') {
                            console.error('送信エラー:', error);
                            toast(`送信エラー: ${error.message}`, 'danger');
                        }
                    })
                    .finally(hideLoading);
            });
        }

        (function autoFetchOnModal(){
            if (!initRoomInput) return;
            const rid = initRoomInput.value || (roomSelect ? roomSelect.value : '');
            if (reservationTypeSelect && reservationTypeSelect.value === '2' && rid) {
                if (userCheckboxes) userCheckboxes.innerHTML = '<tr><td colspan="5" class="text-center text-muted">読み込み中...</td></tr>';
                fetchUserData(rid);
            }
        })();
    }

    window.initReservationForm = initReservationForm;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReservationForm);
    } else {
        initReservationForm();
    }
    document.addEventListener('shown.bs.modal', function(ev){
        const m = ev.target;
        if (m && m.querySelector && m.querySelector('#reservation-form')) {
            try { initReservationForm(); } catch(e){}
        }
    });
})();
