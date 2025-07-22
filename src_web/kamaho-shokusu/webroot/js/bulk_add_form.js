document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrfToken"]').getAttribute('content');
    const reservationForm = document.getElementById('reservation-form');
    const overlay = document.getElementById('loading-overlay');

    const showLoading = () => {
        overlay.style.display = 'block';
        const submitButton = document.querySelector('#reservation-form button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
    };

    const hideLoading = () => {
        overlay.style.display = 'none';
        const submitButton = document.querySelector('#reservation-form button[type="submit"]');
        if (submitButton) submitButton.disabled = false;
    };

    const roomSelect = document.getElementById('i_id_room');
    if (roomSelect) {
        roomSelect.addEventListener('change', function () {
            const roomId = this.value;
            if (roomId) {
                fetchUsersByRoom(roomId);
            }
        });
    }

    function fetchUsersByRoom(roomId) {
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Invalid JSON response:', text);
                        throw new Error('JSON形式のレスポンスではありません');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data && data.users) {
                    renderUsers(data.users);
                } else {
                    displayNoUsersFound();
                }
            })
            .catch(error => {
                console.error('ユーザー情報の取得に失敗しました:', error);
                displayNoUsersFound();
            });
    }

    function renderUsers(users) {
        const userTableBody = document.getElementById('user-checkboxes');
        if (!userTableBody) return;
        userTableBody.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][morning]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][noon]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][night]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][bento]" value="1"></td>
            `;
            userTableBody.appendChild(row);
            setupLunchBentoPair(
                row.querySelector(`input[name="users[${user.id}][noon]"]`),
                row.querySelector(`input[name="users[${user.id}][bento]"]`)
            );
        });
    }

    function displayNoUsersFound() {
        const userTableBody = document.getElementById('user-checkboxes');
        if (userTableBody) {
            userTableBody.innerHTML = '<tr><td colspan="5">利用者が見つかりません。</td></tr>';
        }
    }
    function toggleAllRooms(mealType, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name^="meals[${mealType}]"]`);
        checkboxes.forEach(cb => {
            cb.checked = isChecked;

            // 昼⇔弁当排他処理（昼:2, 弁当:4）
            const match = cb.name.match(/^meals\[(\d+)]\[(\d+)]$/);
            if (match && (mealType === 2 || mealType === 4)) {
                const type = parseInt(match[1], 10);
                const roomId = match[2];
                const counterpartType = (type === 2) ? 4 : 2;
                const counterpartCb = document.querySelector(`input[name="meals[${counterpartType}][${roomId}]"]`);
                if (counterpartCb && isChecked) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }
        });
    }


    function toggleAllUsers(mealTime, isChecked) {
        const map = {morning: 1, noon: 2, night: 3, bento: 4};
        const mealType = map[mealTime];
        if (!mealType) return;

        const checkboxes = document.querySelectorAll(
            `input[type="checkbox"][name^="users"][name$="[${mealType}]"]`
        );

        const headerCheckbox = document.querySelector(
            `input[type="checkbox"][onclick^="toggleAllUsers('${mealTime}'"]`
        );

        checkboxes.forEach(cb => {
            cb.checked = isChecked;

            const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
            if (match && (mealType === 2 || mealType === 4)) {
                const userId = match[1];
                const counterpartType = mealType === 2 ? 4 : 2;
                const counterpartCb = document.querySelector(
                    `input[name="users[${userId}][${counterpartType}]"]`
                );
                if (counterpartCb && isChecked) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }

            cb.removeEventListener('change', cb._onchangeHandler ?? (() => {}));
            cb._onchangeHandler = () => {
                const allChecked = [...checkboxes].every(c => c.checked);
                if (headerCheckbox) {
                    headerCheckbox.checked = allChecked;
                }

                const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                if (match && (mealType === 2 || mealType === 4)) {
                    const userId = match[1];
                    const counterpartType = mealType === 2 ? 4 : 2;
                    const counterpartCb = document.querySelector(
                        `input[name="users[${userId}][${counterpartType}]"]`
                    );
                    if (counterpartCb && cb.checked) {
                        counterpartCb.checked = false;
                        counterpartCb.dispatchEvent(new Event('change'));
                    }
                }
            };
            cb.addEventListener('change', cb._onchangeHandler);
        });

        const allChecked = [...checkboxes].every(c => c.checked);
        if (headerCheckbox) {
            headerCheckbox.checked = allChecked;
        }
    }

    function setupLunchBentoPair(lunchCb, bentoCb) {
        if (!lunchCb || !bentoCb) return;
        if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

        const updateHeaderCheckbox = (changedCb, mealType) => {
            const allCbs = document.querySelectorAll(`input[name^="users"][name$="[${mealType}]"]`);
            const headerCb = document.querySelector(
                `input[type="checkbox"][onclick^="toggleAllUsers('${mealType === 2 ? 'noon' : 'bento'}'"]`
            );
            if (!headerCb) return;
            const allChecked = [...allCbs].every(c => c.checked);
            headerCb.checked = allChecked;
        };

        lunchCb.addEventListener('change', () => {
            if (lunchCb.checked) {
                bentoCb.checked = false;
                bentoCb.dispatchEvent(new Event('change'));
                updateHeaderCheckbox(bentoCb, 4);
            }
        });

        bentoCb.addEventListener('change', () => {
            if (bentoCb.checked) {
                lunchCb.checked = false;
                lunchCb.dispatchEvent(new Event('change'));
                updateHeaderCheckbox(lunchCb, 2);
            }
        });

        lunchCb.dataset._paired = '1';
        bentoCb.dataset._paired = '1';
    }

    function setupAllRoomPairs() {
        document
            .querySelectorAll('input[type="checkbox"][name^="meals[2]["]')
            .forEach(lunchCb => {
                const m = lunchCb.name.match(/^meals\[2]\[(.+)]$/);
                if (!m) return;
                const roomId = m[1];
                const bentoCb = document.querySelector(
                    `input[type="checkbox"][name="meals[4][${roomId}]"]`
                );
                setupLunchBentoPair(lunchCb, bentoCb);
            });
    }

    setupAllRoomPairs();

    reservationForm.addEventListener('submit', function (event) {
        event.preventDefault();
        showLoading();

        const formData = new FormData(this);

        fetch('/kamaho-shokusu/TReservationInfo/bulk-add-submit', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': csrfToken
            }
        })
            .then(async response => {
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    const text = await response.text();
                    console.error('JSONでないレスポンス:', text);
                    throw new Error('JSON形式のレスポンスではありません');
                }
            })
            .then(data => {
                hideLoading();
                if (data.status === 'success') {
                    alert('一括予約が完了しました。');
                    window.location.href = data.redirect_url;
                } else {
                    alert(`エラー: ${data.message}`);
                }
            })
            .catch(error => {
                console.error('エラーが発生しました:', error);
                hideLoading();
                alert('エラーが発生しました。再度お試しください。');
            });
    });
});
