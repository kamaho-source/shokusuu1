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

    // フォーム送信処理（重複情報の処理を追加）
    function submitForm(formData) {
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': csrfToken }
        })
            .then(response => {
                const contentType = response.headers.get('Content-Type');

                // 応答がJSONの場合は JSON として処理する
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    return response.text().then(html => {
                        throw new Error(`期待していないレスポンスが返されました:\n${html}`);
                    });
                }
            })
            .then(data => {
                if (data.status === 'error') {
                    // 重複エラーの処理
                    if (data.errors && data.errors.duplicates) {
                        const duplicateUsers = data.errors.duplicates
                            .map(dup => `ユーザー: ${dup.user_name}, 食事タイプ: ${dup.meal_type}, 部屋: ${dup.room_name}`)
                            .join('\n');

                        alert(`以下のユーザーは既に予約登録されています:\n${duplicateUsers}`);
                        return;
                    }

                    alert('エラー: ' + (data.message || '不明なエラーが発生しました。'));
                } else if (data.status === 'success') {
                    alert('成功: ' + data.message);
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
                hideLoading(); // ローディング非表示
            });
    }

    // バリデーション用関数の追加
    function validateForm(reservationType) {
        let hasSelection = false;

        if (reservationType === 1) {
            const checkboxes = document.querySelectorAll('#room-checkboxes input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    hasSelection = true;
                }
            });

            if (!hasSelection) {
                roomCheckboxes.classList.add('error-highlight');
                alert('食数を入力してください。');
            } else {
                roomCheckboxes.classList.remove('error-highlight');
            }
        } else if (reservationType === 2) {
            const roomSelected = roomSelect.value !== '';
            const checkboxes = document.querySelectorAll('#user-checkboxes input[type="checkbox"]');

            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    hasSelection = true;
                }
            });

            if (!roomSelected) {
                roomSelect.classList.add('error-highlight');
                alert('部屋を選択してください。');
            } else {
                roomSelect.classList.remove('error-highlight');
            }

            hasSelection = roomSelected && hasSelection;
        }

        if (!reservationType || isNaN(reservationType)) {
            alert('予約タイプを選択してくださœ∑œい。');
            return false;
        }

        return hasSelection;
    }

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

    function fetchUserData(roomId) {
        const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}?date=${encodeURIComponent(date)}`;
        console.log('Fetching user data from URL:', url); // URL ログ出力

        fetch(url)
            .then(response => {
                const contentType = response.headers.get('Content-Type');

                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    return response.text().then(html => {
                        throw new Error(`HTML がサーバーから返されました: ${html}`);
                    });
                }
            })
            .then(data => {
                if (!Array.isArray(data.usersByRoom)) {
                    throw new Error('usersByRoom が配列ではありません');
                }

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
