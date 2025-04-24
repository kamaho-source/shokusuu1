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
        });
    }

    function displayNoUsersFound() {
        const userTableBody = document.getElementById('user-checkboxes');
        if (userTableBody) {
            userTableBody.innerHTML = '<tr><td colspan="5">利用者が見つかりません。</td></tr>';
        }
    }

    function toggleAllUsers(mealTime, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name$="[${mealTime}]"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    }

    const toggleButtons = {
        morningCheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'morning\', true)"]'),
        morningUncheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'morning\', false)"]'),
        noonCheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'noon\', true)"]'),
        noonUncheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'noon\', false)"]'),
        nightCheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'night\', true)"]'),
        nightUncheckAll: document.querySelector('button[onclick*="toggleAllUsers(\'night\', false)"]')
    };

    if (toggleButtons.morningCheckAll) toggleButtons.morningCheckAll.addEventListener('click', () => toggleAllUsers('morning', true));
    if (toggleButtons.morningUncheckAll) toggleButtons.morningUncheckAll.addEventListener('click', () => toggleAllUsers('morning', false));
    if (toggleButtons.noonCheckAll) toggleButtons.noonCheckAll.addEventListener('click', () => toggleAllUsers('noon', true));
    if (toggleButtons.noonUncheckAll) toggleButtons.noonUncheckAll.addEventListener('click', () => toggleAllUsers('noon', false));
    if (toggleButtons.nightCheckAll) toggleButtons.nightCheckAll.addEventListener('click', () => toggleAllUsers('night', true));
    if (toggleButtons.nightUncheckAll) toggleButtons.nightUncheckAll.addEventListener('click', () => toggleAllUsers('night', false));

    reservationForm.addEventListener('submit', function (event) {
        event.preventDefault();
        showLoading();

        const formData = new FormData(this);

        fetch('/kamaho-shokusu/TReservationInfo/bulkAddSubmit', {
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
