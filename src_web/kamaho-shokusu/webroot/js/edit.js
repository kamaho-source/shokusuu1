document.addEventListener('DOMContentLoaded', function () {
    const csrfMeta = document.querySelector('meta[name="csrfToken"]');
    if (!csrfMeta) {
        console.error('CSRFトークンが見つかりません。HTML内に正しい<meta>タグが含まれていることを確認してください。');
        return;
    }

    const csrfToken = csrfMeta.getAttribute('content');
    const roomId = new URLSearchParams(window.location.search).get('roomId');
    const date = new URLSearchParams(window.location.search).get('date');
    const mealType = new URLSearchParams(window.location.search).get('mealType');

    if (!roomId || !date || !mealType) {
        console.error('URLパラメータが不足しています: roomId, date, mealTypeを確認してください。');
        return;
    }

    const userTableBody = document.getElementById('user-checkboxes');
    if (!userTableBody) {
        console.error('ユーザー表示用のテーブルボディが見つかりません。HTML内で要素ID "user-checkboxes" を確認してください。');
        return;
    }

    function fetchUserData() {
        const basePath = window.__BASE_PATH || '/kamaho-shokusu';
        fetch(`${basePath}/TReservationInfo/getUsersByRoomForEdit/${roomId}?date=${date}&mealType=${mealType}`, {
            headers: { 'X-CSRF-Token': csrfToken }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.usersByRoom && data.usersByRoom.length > 0) {
                    renderUserCheckboxes(data.usersByRoom);
                } else {
                    userTableBody.innerHTML = '<tr><td colspan="4">該当する利用者がいません。</td></tr>';
                }
            })
            .catch(error => {
                console.error('エラーが発生しました:', error);
                userTableBody.innerHTML = '<tr><td colspan="4">データを取得できませんでした。</td></tr>';
            });
    }

    function renderUserCheckboxes(users) {
        userTableBody.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="reservations[${user.id}][1]" value="1" ${user.meals.morning ? 'checked' : ''}></td>
                <td><input class="form-check-input" type="checkbox" name="reservations[${user.id}][2]" value="1" ${user.meals.noon ? 'checked' : ''}></td>
                <td><input class="form-check-input" type="checkbox" name="reservations[${user.id}][3]" value="1" ${user.meals.night ? 'checked' : ''}></td>
            `;
            userTableBody.appendChild(row);
        });
    }

    fetchUserData();
});
