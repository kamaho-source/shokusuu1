document.addEventListener('DOMContentLoaded', function (message) {
    const csrfToken = document.querySelector('meta[name="csrfToken"]').getAttribute('content');
    const reservationForm = document.getElementById('reservation-form');

    // 曜日チェックボックスを取得
    const mondayCheckbox = document.getElementById('monday');
    const tuesdayCheckbox = document.getElementById('tuesday');
    const wednesdayCheckbox = document.getElementById('wednesday');
    const thursdayCheckbox = document.getElementById('thursday');
    const fridayCheckbox = document.getElementById('friday');

    // 曜日の入力データ
    const dateInputs = {
        monday: mondayCheckbox ? mondayCheckbox.value : '',
        tuesday: tuesdayCheckbox ? tuesdayCheckbox.value : '',
        wednesday: wednesdayCheckbox ? wednesdayCheckbox.value : '',
        thursday: thursdayCheckbox ? thursdayCheckbox.value : '',
        friday: fridayCheckbox ? fridayCheckbox.value : ''
    };

    // 送信ボタン
    const submitButton = document.querySelector('button[type="submit"]');

    submitButton.addEventListener('click', function (event) {
        event.preventDefault();

        // 選択された曜日に対してデータを一括登録
        const selectedDates = [];

        if (mondayCheckbox.checked) selectedDates.push(dateInputs.monday);
        if (tuesdayCheckbox.checked) selectedDates.push(dateInputs.tuesday);
        if (wednesdayCheckbox.checked) selectedDates.push(dateInputs.wednesday);
        if (thursdayCheckbox.checked) selectedDates.push(dateInputs.thursday);
        if (fridayCheckbox.checked) selectedDates.push(dateInputs.friday);

        // 選択された曜日がある場合、一括登録処理
        if (selectedDates.length > 0) {
            // 選択された曜日データをフォームにセットして送信
            const formData = new FormData(reservationForm);
            formData.append('selected_dates', JSON.stringify(selectedDates)); // 追加した日付の情報

            // 一括登録リクエスト
            fetch('/kamaho-shokusu/TReservationInfo/bulkAddSubmit', {
                method: 'POST',
                body: formData, // FormDataをそのまま送る
                headers: {
                    'X-CSRF-Token': csrfToken, // CSRFトークン
                    // 'Content-Type': 'application/json' // FormDataを送る場合、このヘッダーは必要ない
                }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTPエラー: ${response.status}`);
                    }
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('サーバーがJSONではなくHTMLを返しました');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        alert('一括予約が完了しました。');
                        window.location.href = data.redirect_url;
                    } else {
                        alert('一括予約に失敗しました。再度お試しください。');
                        alert(`エラー: ${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('エラーが発生しました:', error);
                    alert('エラーが発生しました。再度お試しください。');
                });

        } else {
            alert('一括予約する曜日を選択してください。');
        }
    });
});
