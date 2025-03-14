document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('loading-overlay'); // ローディング用オーバーレイ

    // ローディングを表示する関数
    function showLoading() {
        overlay.style.display = 'block';
    }

    // ローディングを非表示にする関数
    function hideLoading() {
        overlay.style.display = 'none';
    }

    // フォーム送信時にローディングを表示
    const form = document.getElementById('reservation-form');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault(); // 通常の送信処理を防ぐ
            showLoading(); // ローディングを表示

            // サーバー処理のシミュレーション（1.5秒後に処理完了）
            setTimeout(() => {
                hideLoading(); // ローディングを非表示

                // 登録完了アラートを表示し、OKを押したら遷移
                if (window.confirm('登録完了しました！')) {
                    window.location.href = 'MUserInfo/'; // /MUserInfo/ に遷移
                }
            }, 1500);
        });
    }
});
