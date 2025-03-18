document.addEventListener('DOMContentLoaded', function () {
    const reservationTypeSelect = document.getElementById('c_reservation_type');
    const roomSelectionTable = document.getElementById('room-selection-table');
    const roomSelectGroup = document.getElementById('room-select-group');
    const userSelectionTable = document.getElementById('user-selection-table');
    const roomCheckboxes = document.getElementById('room-checkboxes');
    const userCheckboxes = document.getElementById('user-checkboxes');
    const roomSelect = document.getElementById('room-select');
    const form = document.getElementById('reservation-form');
    const overlay = document.getElementById('loading-overlay');
    const submitButton = form.querySelector('button[type="submit"]');

    // CSRFトークンを取得
    const csrfToken = document.querySelector('meta[name="csrfToken"]').getAttribute('content');

    // 日付の要素を取得
    const dateInput = document.querySelector('input[name="d_reservation_date"]');
    const date = dateInput ? dateInput.value : null;

    // ローディング表示を制御する関数
    const showLoading = () => {
        overlay.style.display = 'block';
        submitButton.disabled = true; // ボタンを無効化
    };

    const hideLoading = () => {
        overlay.style.display = 'none';
        submitButton.disabled = false; // ボタンを再有効化
    };

    // 予約タイプの選択ハンドリング
    if (reservationTypeSelect) {
        reservationTypeSelect.addEventListener('change', function () {
            const reservationType = parseInt(this.value, 10);
            toggleReservationTypeDisplay(reservationType);
        });

        // 初期化時に現在の選択値に基づいてUIを設定
        const initialReservationType = parseInt(reservationTypeSelect.value, 10);
        if (!isNaN(initialReservationType)) {
            toggleReservationTypeDisplay(initialReservationType);
        }
    }

    // 部屋選択の変更ハンドリング
    if (roomSelect) {
        roomSelect.addEventListener('change', function () {
            const roomId = this.value;
            userCheckboxes.innerHTML = ''; // リストをリセット

            if (roomId) {
                showLoading(); // ローディングを表示
                fetchUserData(roomId); // 部屋IDを利用してユーザーリストを取得
            } else {
                alert('部屋が選択されていません。');
            }
        });
    }

    // フォーム送信イベント
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault(); // デフォルトのフォーム送信を防ぐ

            // バリデーションの実装
            const reservationType = parseInt(reservationTypeSelect.value, 10);
            const validationSuccess = validateForm(reservationType);

            if (!validationSuccess) {
                // バリデーションエラーの条件で処理を停止
                alert('エラー: 必須項目を確認してください。');
                return;
            }

            const formData = new FormData(form);
            if (!date) {
                alert('日付が選択されていません。');
                return;
            }

            formData.append('d_reservation_date', date);
            showLoading(); // ローディングを表示
            submitForm(formData); // フォーム送信処理
        });
    }

    // フォーム送信処理
    function submitForm(formData) {
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': csrfToken }
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'error') {
                    alert(`エラー: ${data.message}`);
                } else {
                    alert(`成功: ${data.message}`);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                }
            })
            .catch(error => {
                console.error('送信エラー:', error);
                alert(`送信エラー: ${error.message}`);
            })
            .finally(() => {
                hideLoading(); // ローディングを非表示
            });
    }

    // バリデーション用関数の追加
    /**
     * @param {number} reservationType
     */

    function validateForm(reservationType) {
        let hasSelection = false;

        if (reservationType === 1) {
            // 個人予約のチェック
            const checkboxes = document.querySelectorAll('#room-checkboxes input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    hasSelection = true;
                }
            });

            if (!hasSelection) {
                // 個人予約用のチェックが1つもない場合のエラーハイライトとメッセージ
                roomCheckboxes.classList.add('error-highlight');
                alert('食数を入力してください。');
            } else {
                roomCheckboxes.classList.remove('error-highlight');
            }
        } else if (reservationType === 2) {
            // 集団予約のチェック
            const roomSelected = roomSelect.value !== '';
            const checkboxes = document.querySelectorAll('#user-checkboxes input[type="checkbox"]');

            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    hasSelection = true;
                }
            });

            if (!roomSelected) {
                roomSelect.classList.add('error-highlight'); // 部屋未選択の場合のエラー
                alert('部屋を選択してください。');
            } else {
                roomSelect.classList.remove('error-highlight');
            }

            hasSelection = roomSelected && hasSelection;
        }

        if (!reservationType || isNaN(reservationType)) {
            alert('予約タイプを選択してください。');
            return false;
        }

        return hasSelection;
    }

    // 予約タイプの表示切り替え
    function toggleReservationTypeDisplay(reservationType) {
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

    // ユーザーデータを取得する関数
    function fetchUserData(roomId) {
        const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}?date=${encodeURIComponent(date)}`;
        fetch(url)
            .then(response => response.json())
            .then(data => {
                const users = data.usersByRoom;
                if (Array.isArray(users)) {
                    users.forEach(user => {
                        const row = document.createElement('tr');

                        const morningChecked = user.eat_flag === 1 || user.morning;
                        const noonChecked = user.eat_flag === 1 || user.noon;
                        const nightChecked = user.eat_flag === 1 || user.night;
                        const bentoChecked = user.eat_flag === 1 || user.bento;

                        row.innerHTML = `
                            <td>${user.name}</td>
                            <td><input type="checkbox" name="users[${user.id}][1]" value="1" ${morningChecked ? 'checked' : ''}></td>
                            <td><input type="checkbox" name="users[${user.id}][2]" value="1" ${noonChecked ? 'checked' : ''}></td>
                            <td><input type="checkbox" name="users[${user.id}][3]" value="1" ${nightChecked ? 'checked' : ''}></td>
                            <td><input type="checkbox" name="users[${user.id}][4]" value="1" ${bentoChecked ? 'checked' : ''}> </td>
                        `;
                        userCheckboxes.appendChild(row);
                    });
                } else {
                    console.error('予期しないデータ型: usersByRoom が配列ではありません', users);
                }
            })
            .catch(error => {
                console.error('ユーザーデータの取得エラー:', error);
            })
            .finally(() => {
                hideLoading(); // ローディング解除
            });
    }
});
