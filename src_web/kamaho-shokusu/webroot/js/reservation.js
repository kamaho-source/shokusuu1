/* eslint-disable no-console */
document.addEventListener('DOMContentLoaded', function () {
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

    const csrfToken =
        document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';

    const dateInput = document.querySelector('input[name="d_reservation_date"]');
    const date      = dateInput ? dateInput.value : null;

    const showLoading = () => {
        if (!overlay) return;
        overlay.style.display = 'block';
        if (submitButton) submitButton.disabled = true;
    };

    const hideLoading = () => {
        if (!overlay) return;
        overlay.style.display = 'none';
        if (submitButton) submitButton.disabled = false;
    };

    if (reservationTypeSelect) {
        reservationTypeSelect.addEventListener('change', function () {
            const reservationType = parseInt(this.value, 10);
            toggleReservationTypeDisplay(reservationType);
        });

        const initialReservationType = parseInt(reservationTypeSelect.value, 10);
        if (!Number.isNaN(initialReservationType)) {
            toggleReservationTypeDisplay(initialReservationType);
        }
    }

    if (roomSelect) {
        roomSelect.addEventListener('change', function () {
            const roomId = this.value;
            if (userCheckboxes) userCheckboxes.innerHTML = '';

            if (roomId) {
                showLoading();
                fetchUserData(roomId);
            } else {
                alert('部屋が選択されていません。');
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const reservationType   = parseInt(reservationTypeSelect.value, 10);
            const validationSuccess = validateForm(reservationType);

            if (!validationSuccess) {
                alert('エラー: 必須項目を確認してください。');
                return;
            }

            const formData = new FormData(form);
            if (!date) {
                alert('日付が選択されていません。');
                return;
            }

            formData.append('d_reservation_date', date);

            showLoading();

            fetch(form.action, {
                method : 'POST',
                body   : formData,
                headers: { 'X-CSRF-Token': csrfToken },
            })
                .then(response => {
                    const contentType = response.headers.get('Content-Type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    }
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
                        alert(`成功: ${data.message}`);
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        }
                    }
                })
                .catch(error => {
                    if (error.message !== 'HTMLを表示しました') {
                        console.error('送信エラー:', error);
                        alert(`送信エラー: ${error.message}`);
                    }
                })
                .finally(() => {
                    hideLoading();
                });
        });
    }

    function validateForm(reservationType) {
        let hasSelection = false;

        if (reservationType === 1) {
            const checkboxes = document.querySelectorAll('#room-checkboxes input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) hasSelection = true;
            });

            if (!hasSelection && roomCheckboxes) {
                roomCheckboxes.classList.add('error-highlight');
                alert('食数を入力してください。');
            } else if (roomCheckboxes) {
                roomCheckboxes.classList.remove('error-highlight');
            }
        } else if (reservationType === 2) {
            const roomSelected = roomSelect && roomSelect.value !== '';
            const checkboxes   = document.querySelectorAll('#user-checkboxes input[type="checkbox"]');

            checkboxes.forEach(checkbox => {
                if (checkbox.checked) hasSelection = true;
            });

            if (!roomSelected && roomSelect) {
                roomSelect.classList.add('error-highlight');
                alert('部屋を選択してください。');
            } else if (roomSelect) {
                roomSelect.classList.remove('error-highlight');
            }

            hasSelection = roomSelected && hasSelection;
        }

        if (!reservationType || Number.isNaN(reservationType)) {
            alert('予約タイプを選択してください。');
            return false;
        }

        return hasSelection;
    }

    function toggleReservationTypeDisplay(type) {
        const roomTable       = document.getElementById('room-selection-table');
        const roomSelectGroup = document.getElementById('room-select-group');
        const userSelectTable = document.getElementById('user-selection-table');

        const t = Number(type);

        if (t === 1) {
            if (roomTable)       roomTable.style.display       = '';
            if (roomSelectGroup) roomSelectGroup.style.display = 'none';
            if (userSelectTable) userSelectTable.style.display = 'none';
            fetchPersonalReservationData?.();
        } else if (t === 2) {
            if (roomTable)       roomTable.style.display       = 'none';
            if (roomSelectGroup) roomSelectGroup.style.display = '';
            if (userSelectTable) userSelectTable.style.display = 'none';
        } else {
            if (roomTable)       roomTable.style.display       = 'none';
            if (roomSelectGroup) roomSelectGroup.style.display = 'none';
            if (userSelectTable) userSelectTable.style.display = 'none';
        }
    }

    function fetchUserData(roomId) {
        const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}?date=${encodeURIComponent(date)}`;
        console.log('Fetching user data from URL:', url);

        fetch(url)
            .then(response => {
                const contentType = response.headers.get('Content-Type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                }
                return response.text().then(html => {
                    throw new Error(`HTML がサーバーから返されました: ${html}`);
                });
            })
            .then(data => {
                if (!Array.isArray(data.usersByRoom)) {
                    throw new Error('usersByRoom が配列ではありません');
                }

                if (!userCheckboxes) return;
                userCheckboxes.innerHTML = '';
                data.usersByRoom.forEach(user => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${user.name}</td>
                        <td><input type="checkbox" name="users[${user.id}][1]" ${user.morning ? 'checked' : ''}></td>
                        <td><input type="checkbox" name="users[${user.id}][2]" ${user.noon ? 'checked' : ''}></td>
                        <td><input type="checkbox" name="users[${user.id}][3]" ${user.night ? 'checked' : ''}></td>
                        <td><input type="checkbox" name="users[${user.id}][4]" ${user.bento ? 'checked' : ''}></td>
                    `;
                    userCheckboxes.appendChild(row);
                });
            })
            .catch(error => {
                console.error('ユーザーデータ取得エラー:', error);
                alert(`ユーザーデータ取得エラー: ${error.message}`);
            })
            .finally(() => {
                hideLoading();
            });
    }
});
