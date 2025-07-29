/* eslint-env browser */

document.addEventListener('DOMContentLoaded', () => {
    //--------------------------------------------------------------------
    // 0. CSRF トークン取得
    //--------------------------------------------------------------------
    const csrfMeta = document.querySelector('meta[name="csrfToken"]');
    if (!csrfMeta) {
        console.error('CSRFトークンが見つかりません。HTML 内に <meta name="csrfToken"> があるか確認してください。');
        return;
    }
    const csrfToken = csrfMeta.getAttribute('content');

    //--------------------------------------------------------------------
    // 1. 必須パラメータの取得
    //--------------------------------------------------------------------
    // ① PHP からインラインで渡された値を優先
    const {
        roomId: phpRoomId,
        date: phpDate,
        mealType: phpMealType,
    } = window.mealEditParams ?? {};

    // ② URL クエリ文字列（旧実装のフォールバック）
    const searchParams = new URLSearchParams(window.location.search);
    const roomId   = phpRoomId   || searchParams.get('roomId');
    const date     = phpDate     || searchParams.get('date');
    const mealType = phpMealType || searchParams.get('mealType');

    if (!roomId || !date || !mealType) {
        console.error('URL パラメータが不足しています: roomId, date, mealType を確認してください。');
        return;
    }

    //--------------------------------------------------------------------
    // 2. 利用者一覧表示箇所
    //--------------------------------------------------------------------
    const userTableBody = document.getElementById('user-checkboxes');
    if (!userTableBody) {
        console.error('テーブル tbody 要素 #user-checkboxes が見つかりません。');
        return;
    }

    //--------------------------------------------------------------------
    // 3. API で利用者データを取得
    //--------------------------------------------------------------------
    function fetchUserData() {
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForEdit/${roomId}?date=${encodeURIComponent(date)}&mealType=${encodeURIComponent(mealType)}`, {
            headers: { 'X-CSRF-Token': csrfToken },
        })
            .then((response) => {
                if (!response.ok) throw new Error(`HTTPエラー: ${response.status}`);
                return response.json();
            })
            .then((data) => {
                if (Array.isArray(data.usersByRoom) && data.usersByRoom.length > 0) {
                    renderUserCheckboxes(data.usersByRoom);
                } else {
                    userTableBody.innerHTML = '<tr><td colspan="4">該当する利用者がいません。</td></tr>';
                }
            })
            .catch((error) => {
                console.error('エラーが発生しました:', error);
                userTableBody.innerHTML = '<tr><td colspan="4">データを取得できませんでした。</td></tr>';
            });
    }

    //--------------------------------------------------------------------
    // 4. チェックボックス描画
    //--------------------------------------------------------------------
    function renderUserCheckboxes(users) {
        userTableBody.innerHTML = '';

        // i_change_flag が立っているか判定するヘルパー
        const hasChangeFlag = (meal) => !!(meal?.i_change_flag);

        users.forEach((user) => {
            const meals = user.meals ?? {};

            const row = document.createElement('tr');
            row.innerHTML = `
        <td>${user.name}</td>

        <!-- 朝食 -->
        <td>
          <input type="hidden" name="reservations[${user.id}][1]" value="0">
          <input class="form-check-input" type="checkbox"
                 name="reservations[${user.id}][1]" value="1"
                 ${hasChangeFlag(meals.morning) ? 'checked' : ''}>
        </td>

        <!-- 昼食 -->
        <td>
          <input type="hidden" name="reservations[${user.id}][2]" value="0">
          <input class="form-check-input" type="checkbox"
                 name="reservations[${user.id}][2]" value="1"
                 ${hasChangeFlag(meals.noon) ? 'checked' : ''}>
        </td>

        <!-- 夕食 -->
        <td>
          <input type="hidden" name="reservations[${user.id}][3]" value="0">
          <input class="form-check-input" type="checkbox"
                 name="reservations[${user.id}][3]" value="1"
                 ${hasChangeFlag(meals.night) ? 'checked' : ''}>
        </td>
      `;
            userTableBody.appendChild(row);
        });
    }

    // 初期ロード
    fetchUserData();
});