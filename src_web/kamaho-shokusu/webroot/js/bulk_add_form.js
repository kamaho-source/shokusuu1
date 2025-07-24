/* eslint-disable no-console */
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken       = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
    const reservationForm = document.getElementById('reservation-form');
    const overlay         = document.getElementById('loading-overlay');

    /** ローディング表示 */
    const showLoading = () => {
        if (!overlay) return;
        overlay.style.display = 'block';
        const submitButton = document.querySelector('#reservation-form button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
    };

    /** ローディング非表示 */
    const hideLoading = () => {
        if (!overlay) return;
        overlay.style.display = 'none';
        const submitButton = document.querySelector('#reservation-form button[type="submit"]');
        if (submitButton) submitButton.disabled = false;
    };

    /** 部屋プルダウン変更時にユーザー取得 */
    const roomSelect = document.getElementById('i_id_room');
    if (roomSelect) {
        roomSelect.addEventListener('change', function onChange() {
            const roomId = this.value;
            if (roomId) fetchUsersByRoom(roomId);
        });
    }

    /** 部屋に紐づくユーザー一覧取得 */
    function fetchUsersByRoom(roomId) {
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
            .then((response) => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then((text) => {
                        console.error('Invalid JSON response:', text);
                        throw new Error('JSON形式のレスポンスではありません');
                    });
                }
                return response.json();
            })
            .then((data) => {
                if (data && data.users) {
                    renderUsers(data.users);
                } else {
                    displayNoUsersFound();
                }
            })
            .catch((error) => {
                console.error('ユーザー情報の取得に失敗しました:', error);
                displayNoUsersFound();
            });
    }

    /**
     * ユーザー列ヘッダ（一括チェックボックス）を
     * mealType ごとに現在の行チェック状態へ合わせる
     * @param {Number} mealType 1:朝 2:昼 3:夜 4:弁当
     */
    function updateUserHeaderCheckbox(mealType) {
        const selector  = `input[type="checkbox"][name^="users"][name$="[${mealType}]"]`;
        const allCbs    = document.querySelectorAll(selector);
        const headerCb  = document.querySelector(
            `input[type="checkbox"][onclick^="toggleAllUsers('${mealType === 1 ? 'morning' : mealType === 2 ? 'noon' : mealType === 3 ? 'night' : 'bento'}'"]`,
        );
        if (!headerCb) return;
        headerCb.checked = allCbs.length > 0 && [...allCbs].every((cb) => cb.checked);
    }

    /**
     * ===== 追加：部屋ヘッダ（一括チェック） ↔ 行チェック 同期 =====
     * mealType ごとに部屋行チェックの状態からヘッダを更新
     * @param {Number} mealType 1:朝 2:昼 3:夜 4:弁当
     */
    function updateRoomHeaderCheckbox(mealType) {
        const headerCb = document.getElementById(`toggle-room-all-${mealType}`);
        if (!headerCb) return;

        const allCbs     = document.querySelectorAll(`input[type="checkbox"][name^="meals[${mealType}]["]`);
        const checkedCbs = document.querySelectorAll(`input[type="checkbox"][name^="meals[${mealType}]["]:checked`);

        headerCb.checked = allCbs.length > 0 && allCbs.length === checkedCbs.length;
    }

    /** すべての部屋行チェックボックスへ change イベントを付与 */
    function attachRoomCheckboxListeners() {
        document
            .querySelectorAll('input[type="checkbox"][name^="meals["]')
            .forEach((cb) => {
                cb.addEventListener('change', () => {
                    const m = cb.name.match(/^meals\[(\d+)]\[/);
                    if (!m) return;
                    const mealType = Number(m[1]);
                    updateRoomHeaderCheckbox(mealType);
                });
            });
    }

    /** ユーザーチェックボックス描画 */
    function renderUsers(users) {
        const userTableBody = document.getElementById('user-checkboxes');
        if (!userTableBody) return;

        userTableBody.innerHTML = '';
        users.forEach((user) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][1]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][2]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][3]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][4]" value="1"></td>
            `;
            userTableBody.appendChild(row);

            // 行内チェックボックスへ共通ハンドラを付与
            row.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
                cb.addEventListener('change', () => {
                    const m = cb.name.match(/^users\[\d+]\[(\d+)]$/);
                    if (!m) return;
                    const mealType = Number(m[1]);

                    // 行内 昼⇔弁当 排他制御
                    if (mealType === 2 || mealType === 4) {
                        const counterpartType = mealType === 2 ? 4 : 2;
                        const counterpartCb   = row.querySelector(`input[name="users[${user.id}][${counterpartType}]"]`);
                        if (counterpartCb && cb.checked) {
                            counterpartCb.checked = false;
                            // ヘッダを更新するため change イベントをトリガ
                            counterpartCb.dispatchEvent(new Event('change'));
                        }
                    }
                    // ヘッダ同期
                    updateUserHeaderCheckbox(mealType);
                });
            });

            // 昼⇔弁当初期排他セットアップ
            setupLunchBentoPair(
                row.querySelector(`input[name="users[${user.id}][2]"]`),
                row.querySelector(`input[name="users[${user.id}][4]"]`),
            );
        });

        // 新規描画後に列ヘッダを再計算
        [1, 2, 3, 4].forEach(updateUserHeaderCheckbox);
    }

    /** ユーザーが存在しない場合の表示 */
    function displayNoUsersFound() {
        const userTableBody = document.getElementById('user-checkboxes');
        if (userTableBody) {
            userTableBody.innerHTML = '<tr><td colspan="5">利用者が見つかりません。</td></tr>';
        }
    }

    /** 部屋単位で全選択／解除 */
    function toggleAllRooms(mealType, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name^="meals[${mealType}]"]`);
        checkboxes.forEach((cb) => {
            cb.checked = isChecked;

            // 昼⇔弁当 排他（昼:2, 弁当:4）
            const match = cb.name.match(/^meals\[(\d+)]\[(.+)]$/);
            if (match && (mealType === 2 || mealType === 4)) {
                const type            = Number(match[1]);
                const roomId          = match[2];
                const counterpartType = type === 2 ? 4 : 2;
                const counterpartCb   = document.querySelector(`input[name="meals[${counterpartType}][${roomId}]"]`);
                if (counterpartCb && isChecked) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }
        });

        // 追加：ヘッダ同期
        updateRoomHeaderCheckbox(mealType);
    }

    /** 利用者単位で全選択／解除 */
    function toggleAllUsers(mealTime, isChecked) {
        const map      = { morning: 1, noon: 2, night: 3, bento: 4 };
        const mealType = map[mealTime];
        if (!mealType) return;

        const checkboxes = document.querySelectorAll(`input[type="checkbox"][name^="users"][name$="[${mealType}]"]`);

        checkboxes.forEach((cb) => {
            cb.checked = isChecked;

            // 昼⇔弁当 排他
            const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
            if (match && (mealType === 2 || mealType === 4)) {
                const userId          = match[1];
                const counterpartType = mealType === 2 ? 4 : 2;
                const counterpartCb   = document.querySelector(`input[name="users[${userId}][${counterpartType}]"]`);
                if (counterpartCb && isChecked) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }
        });

        // 列ヘッダを更新
        updateUserHeaderCheckbox(mealType);
    }

    /** 昼(2)⇔弁当(4) 相互排他セットアップ */
    function setupLunchBentoPair(lunchCb, bentoCb) {
        if (!lunchCb || !bentoCb) return;
        if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

        const syncHeaders = () => {
            updateUserHeaderCheckbox(2);
            updateUserHeaderCheckbox(4);
        };

        lunchCb.addEventListener('change', () => {
            if (lunchCb.checked) {
                bentoCb.checked = false;
                bentoCb.dispatchEvent(new Event('change'));
            }
            syncHeaders();
        });

        bentoCb.addEventListener('change', () => {
            if (bentoCb.checked) {
                lunchCb.checked = false;
                lunchCb.dispatchEvent(new Event('change'));
            }
            syncHeaders();
        });

        lunchCb.dataset._paired = '1';
        bentoCb.dataset._paired = '1';
    }

    /** 画面ロード時に部屋単位の昼⇔弁当排他制御をセットアップ */
    function setupAllRoomPairs() {
        document
            .querySelectorAll('input[type="checkbox"][name^="meals[2]["]')
            .forEach((lunchCb) => {
                const m = lunchCb.name.match(/^meals\[2]\[(.+)]$/);
                if (!m) return;
                const roomId  = m[1];
                const bentoCb = document.querySelector(`input[type="checkbox"][name="meals[4][${roomId}]"]`);
                setupLunchBentoPair(lunchCb, bentoCb);
            });
    }

    // ===== 初期セットアップ =====
    setupAllRoomPairs();            // 部屋：昼⇔弁当排他
    attachRoomCheckboxListeners();  // 部屋：ヘッダー同期
    [1, 2, 3, 4].forEach(updateRoomHeaderCheckbox); // 初期ヘッダー状態

    /** 送信イベント */
    if (reservationForm) {
        reservationForm.addEventListener('submit', function onSubmit(event) {
            event.preventDefault();
            showLoading();

            const formData = new FormData(this);

            fetch('/kamaho-shokusu/TReservationInfo/bulk-add-submit', {
                method:  'POST',
                body:    formData,
                headers: { 'X-CSRF-Token': csrfToken },
            })
                .then(async (response) => {
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    }
                    const text = await response.text();
                    console.error('JSONでないレスポンス:', text);
                    throw new Error('JSON形式のレスポンスではありません');
                })
                .then((data) => {
                    hideLoading();
                    if (data.status === 'success') {
                        alert('一括予約が完了しました。');
                        window.location.href = data.redirect_url;
                    } else {
                        alert(`エラー: ${data.message}`);
                    }
                })
                .catch((error) => {
                    console.error('エラーが発生しました:', error);
                    hideLoading();
                    alert('エラーが発生しました。再度お試しください。');
                });
        });
    }
});