/* eslint-disable no-console */
/* eslint-env browser */
/**
 * reservation-users.js
 * 利用者一覧の取得・描画を担う共有モジュール。
 * window.ReservationUsers を公開し、add.js / reservation.js から参照する。
 */
(function(){
    var _cache    = new Map();
    var _inFlight = new Map();

    function buildUrl(roomId, date){
        var tpl = window.GET_USERS_BY_ROOM_TPL || '';
        var d   = date || window.QUERY_DATE || '';
        var url;
        if (tpl) {
            url = tpl.indexOf('__RID__') !== -1
                ? tpl.replace('__RID__', encodeURIComponent(roomId))
                : (tpl.replace(/\/$/, '') + '/' + encodeURIComponent(roomId));
        } else {
            var parts = location.pathname.split('/').filter(Boolean);
            var base  = parts[0] === 'kamaho-shokusu' ? '/kamaho-shokusu' : '';
            url = base + '/TReservationInfo/getUsersByRoom/' + encodeURIComponent(roomId);
        }
        if (d) url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(d);
        return url;
    }

    function fetchRaw(roomId, date){
        var key     = String(roomId) + '|' + String(date || '');
        var now     = Date.now();
        var cached  = _cache.get(key);
        if (cached && (now - cached.ts) < 30000) return Promise.resolve(cached.data);
        var inflight = _inFlight.get(key);
        if (inflight) return inflight;

        var url = buildUrl(roomId, date);
        var req = fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res){ if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
        .then(function(json){ _cache.set(key, { ts: Date.now(), data: json }); return json; })
        .finally(function(){ _inFlight.delete(key); });

        _inFlight.set(key, req);
        return req;
    }

    function extractUsers(json){
        var payload = (json && json.data) ? json.data : json;
        return Array.isArray(payload.usersByRoom) ? payload.usersByRoom
             : (Array.isArray(payload.users)      ? payload.users      : []);
    }

    function escHtml(s){
        return String(s).replace(/[<>&"']/g, function(c){
            return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function buildRows(users){
        if (!users.length){
            return '<tr><td colspan="5" class="text-muted text-center">この部屋に利用者がいません。</td></tr>';
        }
        return users.map(function(u){
            var id   = escHtml(u.id   || u.user_id   || '');
            var name = escHtml(u.name || u.user_name || '名前不明');
            var m  = Number(u.morning) === 1;
            var n  = Number(u.noon)    === 1;
            var ni = Number(u.night)   === 1;
            var b  = Number(u.bento)   === 1;
            return '<tr>' +
                '<td>' + name + '</td>' +
                '<td class="text-center"><input type="checkbox" name="users[' + id + '][1]" value="1"' + (m  ? ' checked data-existing="1"' : '') + '></td>' +
                '<td class="text-center"><input type="checkbox" name="users[' + id + '][2]" value="1"' + (n  ? ' checked data-existing="1"' : '') + '></td>' +
                '<td class="text-center"><input type="checkbox" name="users[' + id + '][3]" value="1"' + (ni ? ' checked data-existing="1"' : '') + '></td>' +
                '<td class="text-center"><input type="checkbox" name="users[' + id + '][4]" value="1"' + (b  ? ' checked data-existing="1"' : '') + '></td>' +
                '</tr>';
        }).join('');
    }

    function fetchAndRender(roomId, tbodyEl, tableEl){
        if (!roomId || !tbodyEl) return Promise.resolve();
        tbodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted">読み込み中...</td></tr>';
        return fetchRaw(roomId, window.QUERY_DATE || '')
            .then(function(json){
                tbodyEl.innerHTML = buildRows(extractUsers(json));
                if (tableEl) tableEl.classList.remove('d-none');
            })
            .catch(function(e){
                console.error('[ReservationUsers]', e);
                tbodyEl.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました。</td></tr>';
            });
    }

    window.ReservationUsers = {
        buildUrl:      buildUrl,
        fetch:         fetchRaw,
        extractUsers:  extractUsers,
        fetchAndRender: fetchAndRender
    };
})();
