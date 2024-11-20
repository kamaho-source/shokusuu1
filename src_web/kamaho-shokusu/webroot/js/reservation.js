document.addEventListener('DOMContentLoaded', function() {
    const reservationTypeSelect = document.getElementById('c_reservation_type');
    const roomSelectionTable = document.getElementById('room-selection-table');
    const roomSelectGroup = document.getElementById('room-select-group');
    const userSelectionTable = document.getElementById('user-selection-table');
    const roomCheckboxes = document.getElementById('room-checkboxes');
    const userCheckboxes = document.getElementById('user-checkboxes');
    const roomSelect = document.getElementById('room-select');
    const form = document.getElementById('reservation-form');

    // CSRFトークンを取得
    const csrfToken = document.querySelector('meta[name="csrfToken"]').getAttribute('content');

    // 日付の要素を取得
    const dateInput = document.querySelector('input[name="d_reservation_date"]');
    const date = dateInput ? dateInput.value : null;

    // デバッグ用: 日付情報の確認
    console.log("Reservation Date:", date);

    // 予約タイプの選択ハンドリング
    reservationTypeSelect.addEventListener('change', function() {
        const reservationType = parseInt(this.value, 10);
        console.log("Selected reservation type:", reservationType);
        toggleReservationTypeDisplay(reservationType);
    });

    // グループ予約の部屋選択ハンドリング
    roomSelect.addEventListener('change', function() {
        const roomId = this.value;
        console.log("Selected room ID for group reservation:", roomId);
        userCheckboxes.innerHTML = '';
        if (roomId) {
            fetchUserData(roomId);
        }
    });

    // フォーム送信イベント
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        const formData = new FormData(form);

        // 日付をFormDataに追加
        if (date) {
            formData.append('d_reservation_date', date);
        } else {
            alert('日付が選択されていません。');
            console.error('日付が選択されていません。');
            return;
        }

        const reservationType = parseInt(reservationTypeSelect.value, 10);
        console.log("Reservation type at submission:", reservationType);

        if (reservationType === 1) {
            // 個人予約処理
            const mealData = collectMealCheckboxData();
            console.log("Collected Meal Data:", Array.from(mealData.entries()));

            const roomIds = Array.from(mealData.keys()).map(key => {
                const match = key.match(/\[.*?\]\[(\d+)\]/); // 部屋IDを抽出
                return match ? match[1] : null;
            }).filter(Boolean);

            console.log("Extracted Room IDs:", roomIds);

            const uniqueRoomIds = [...new Set(roomIds)];
            if (uniqueRoomIds.length > 0) {
                formData.append('i_id_room', uniqueRoomIds[0]);
                console.log("Appended Room ID:", uniqueRoomIds[0]);
            } else {
                alert('部屋が選択されていません。');
                console.error('部屋が選択されていません。');
                return;
            }

            // mealsデータを追加
            mealData.forEach((value, key) => formData.append(key, value));
        } else if (reservationType === 2) {
            // 集団予約処理
            if (roomSelect.value) {
                formData.append('i_id_room', roomSelect.value);
                console.log("Appended Room ID for group:", roomSelect.value);
            } else {
                alert('部屋が選択されていません。');
                console.error('部屋が選択されていません。');
                return;
            }

            const userData = collectUserCheckboxData();
            console.log("Collected User Data:", Array.from(userData.entries()));

            if (userData.size === 0) {
                alert('ユーザーが選択されていません。');
                console.error('ユーザーが選択されていません。');
                return;
            }
            userData.forEach((value, key) => formData.append(key, value));
        }

        console.log("Form Data Being Sent:", Object.fromEntries(formData.entries()));

        fetch(`${window.location.origin}/kamaho-shokusu/TReservationInfo/add`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Network response was not ok, status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('サーバーからの応答:', data);
                if (data.status === 'error') {
                    alert(`エラーが発生しました: ${data.message}`);
                } else {
                    alert(`送信結果: ${data.message}`);
                    if (data.redirect) {
                        window.location.href = data.redirect; // リダイレクトを処理
                    }
                }
            })
            .catch(error => {
                console.error('送信エラー:', error);
                alert('送信エラー: ' + error.message);
            });
    });

    function toggleReservationTypeDisplay(reservationType) {
        console.log("Toggling display for reservation type:", reservationType);
        if (reservationType === 1) {
            roomSelectionTable.style.display = 'block';
            roomSelectGroup.style.display = 'none';
            userSelectionTable.style.display = 'none';
        } else if (reservationType === 2) {
            roomSelectionTable.style.display = 'none';
            roomSelectGroup.style.display = 'block';
            userSelectionTable.style.display = 'block';
        }
    }

    function collectMealCheckboxData() {
        const data = new Map();
        roomCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (checkbox.checked) {
                const match = checkbox.name.match(/\[(\d+)\]\[(\d+)\]/); // 正規表現で mealType と roomId を抽出
                if (match) {
                    const mealType = match[1]; // 食事タイプ
                    const roomId = match[2];  // 部屋ID
                    data.set(`meals[${mealType}][${roomId}]`, 1);
                } else {
                    console.warn("No Match for Checkbox name:", checkbox.name); // マッチしない場合
                }
            }
        });
        return data;
    }

    function collectUserCheckboxData() {
        const data = new Map();
        userCheckboxes.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
            data.set(checkbox.name, checkbox.value);
        });
        return data;
    }

    function fetchUserData(roomId) {
        const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}`;
        console.log("Fetching user data from URL:", url);
        fetch(url, {
            headers: { 'X-CSRF-Token': csrfToken }
        })
            .then(response => response.json())
            .then(data => {
                if (data.usersByRoom) {
                    renderUserCheckboxes(data.usersByRoom);
                } else {
                    alert('ユーザー情報が見つかりません。');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('Fetch error: ' + error.message);
            });
    }

    function renderUserCheckboxes(users) {
        userCheckboxes.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][1]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][2]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][3]" value="1"></td>
            `;
            userCheckboxes.appendChild(row);
        });
    }
});
