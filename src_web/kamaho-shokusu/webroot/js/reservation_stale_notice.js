/* eslint-env browser */
/**
 * 食数予約画面の「別タブで保存された」通知。
 *
 * 同じブラウザで複数タブを開いたまま片方で保存すると、もう片方は古い内容のまま残る。
 * そのまま保存すると別タブの変更を上書きしてしまうため、保存側から失効を知らせて
 * 受け取った側に再読込を促す。
 *
 * サーバー側の競合検知（一括画面の reservation_snapshot、行単位の楽観ロック）を
 * 置き換えるものではなく、同一ブラウザの取り違えを手前で防ぐための補助。
 */
(function(){
    var CHANNEL = 'shokusu-reservation-saved';
    var STORAGE_KEY = 'shokusu:reservation-saved';
    var channel = null;

    if (typeof window.BroadcastChannel === 'function') {
        try { channel = new BroadcastChannel(CHANNEL); } catch (e) { channel = null; }
    }

    function showStaleBanner(date){
        if (document.getElementById('reservation-stale-notice')) return;

        var notice = document.createElement('div');
        notice.id = 'reservation-stale-notice';
        notice.className = 'alert alert-warning d-flex align-items-center gap-2 mb-3';
        notice.setAttribute('role', 'alert');
        notice.setAttribute('aria-live', 'polite');
        notice.innerHTML =
            '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>' +
            '<span>この画面は他のタブで更新されました' + (date ? '（' + String(date).replace(/[<>&"]/g, '') + '）' : '') +
            '。このまま保存すると、他のタブの変更を上書きする可能性があります。</span>' +
            '<button type="button" class="btn btn-sm btn-warning ms-auto" id="reservation-stale-reload">再読込する</button>';

        var host = document.querySelector('#ce-root, #reservation-form, .container, main') || document.body;
        host.insertBefore(notice, host.firstChild);

        var btn = document.getElementById('reservation-stale-reload');
        if (btn) btn.addEventListener('click', function(){ window.location.reload(); });
    }

    function onSaved(payload){
        // 自分が保存したタブでは出さない（保存側はこの直後にリロードする）
        if (!payload || payload.tabId === window.__RESERVATION_TAB_ID) return;
        showStaleBanner(payload.date);
    }

    // タブ固有ID（Math.random ではなく時刻＋カウンタで十分）
    window.__RESERVATION_TAB_ID = window.__RESERVATION_TAB_ID ||
        (String(Date.now()) + '-' + String(performance.now()).replace('.', ''));

    if (channel) {
        channel.addEventListener('message', function(ev){ onSaved(ev.data); });
    }
    // BroadcastChannel 非対応ブラウザ向けのフォールバック
    window.addEventListener('storage', function(ev){
        if (ev.key !== STORAGE_KEY || !ev.newValue) return;
        try { onSaved(JSON.parse(ev.newValue)); } catch (e) { /* 壊れた値は無視 */ }
    });

    window.ReservationSync = {
        /** 保存が成功したことを他タブへ知らせる。 */
        notifySaved: function(date){
            var payload = { tabId: window.__RESERVATION_TAB_ID, date: date || null, at: Date.now() };
            if (channel) {
                try { channel.postMessage(payload); } catch (e) { /* 送れなければ storage にまかせる */ }
            }
            try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload)); } catch (e) { /* 無視 */ }
        }
    };
})();
