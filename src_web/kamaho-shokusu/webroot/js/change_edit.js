/* eslint-env browser */

document.addEventListener('DOMContentLoaded', () => {
    // 先進版(インライン版)があるページでは本レガシーを停止
    if (document.getElementById('ce-table') || document.getElementById('ce-room-select')) {
        return;
    }

    // --------------------------------------------------------------------
    // 0. CSRF
    // --------------------------------------------------------------------
    const csrfMeta = document.querySelector('meta[name="csrfToken"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : null;
    if (!csrfMeta) {
        console.warn('CSRFメタが見つかりません。POST時にヘッダ未付与となります。');
    }

    // --------------------------------------------------------------------
    // 1. パラメータ（window もしくは URL）
    // --------------------------------------------------------------------
    const { roomId: phpRoomId, date: phpDate, mealType: phpMealType } = window.mealEditParams ?? {};
    const searchParams = new URLSearchParams(window.location.search);
    const roomId   = String(phpRoomId   || searchParams.get('roomId')   || '').trim();
    const date     = String(phpDate     || searchParams.get('date')     || '').trim();
    const mealType = String(phpMealType || searchParams.get('mealType') || '').trim();

    if (!roomId || !date || !mealType) {
        console.error('パラメータ不足: roomId, date, mealType が必要です。', { roomId, date, mealType });
        return;
    }

    // --------------------------------------------------------------------
    // 2. DOM 参照
    // --------------------------------------------------------------------
    const userTableBody = document.getElementById('user-checkboxes'); // tbody
    if (!userTableBody) {
        console.error('#user-checkboxes (tbody) が見つかりません。');
        return;
    }

    // 保存フォーム（存在すれば POST(JSON) に差し替え）
    const legacyForm = document.getElementById('legacy-change-edit-form');

    // --------------------------------------------------------------------
    // 3. API URL ビルド（新エンドポイント）
    // --------------------------------------------------------------------
    function buildApiUrl(rid, d, mt) {
        return `/t-individual-reservation-info/change-edit/${encodeURIComponent(rid)}/${encodeURIComponent(d)}/${encodeURIComponent(mt)}.json`;
    }

    // --------------------------------------------------------------------
    // 4. ユーティリティ
    // --------------------------------------------------------------------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

    function roomsCellHTML(user, defaultRoomId) {
        const rooms = Array.isArray(user.rooms) ? user.rooms : [];
        if (rooms.length > 1) {
            const opts = rooms.map(r => {
                const selected = String(r.id) === String(defaultRoomId) ? ' selected' : '';
                return `<option value="${esc(r.id)}"${selected}>${esc(r.name)}</option>`;
            }).join('');
            return `
        <td>
          <select name="reservations[${esc(user.id)}][room_id]" class="form-select form-select-sm js-user-room">
            ${opts}
          </select>
        </td>`;
        }
        const rid = rooms[0]?.id ?? defaultRoomId;
        const rname = rooms[0]?.name ?? '';
        return `
      <td>
        <span class="badge bg-secondary-subtle text-dark">${esc(rname)}</span>
        <input type="hidden" name="reservations[${esc(user.id)}][room_id]" value="${esc(rid)}" class="js-user-room">
      </td>`;
    }

    // mealType→プロパティ名（API互換のラベルは使わず、1..4 を直接出力）
    function mealCellHTML(userId, t, flag, allowEdit, currentRoomId) {
        const checked  = Number(flag?.i_change_flag || 0) === 1 ? ' checked' : '';
        const disabled = allowEdit ? '' : ' disabled';
        const existRid = flag?.room_id ?? '';
        const eatFlag  = flag?.eat_flag ?? '';
        let note = '';
        if (existRid && String(existRid) !== String(currentRoomId)) {
            if (Number(eatFlag || 0) === 1) {
                note = `<div class="small text-danger mt-1">別部屋で予約（${esc(flag?.room_name || '不明')}）</div>`;
            } else if (Number(flag?.i_change_flag || 0) === 0) {
                note = `<div class="small text-warning mt-1">別部屋で「食べない」</div>`;
            }
        }
        return `
      <td class="text-center">
        <input type="checkbox"
               class="form-check-input js-meal"
               name="reservations[${esc(userId)}][${t}]"
               data-reservation-type="${t}"
               data-existing-room-id="${esc(existRid)}"
               data-eat-flag="${esc(eatFlag)}"
               value="1"${checked}${disabled}>
        ${note}
      </td>`;
    }

    function bindRowGuards(tr) {
        // 昼(2)⇔弁当(4) 相互排他
        const lunch = tr.querySelector('input.js-meal[data-reservation-type="2"]');
        const bento = tr.querySelector('input.js-meal[data-reservation-type="4"]');
        if (lunch && bento) {
            lunch.addEventListener('change', () => { if (lunch.checked) bento.checked = false; });
            bento.addEventListener('change', () => { if (bento.checked) lunch.checked = false; });
        }

        // 別部屋予約の注意
        tr.querySelectorAll('input.js-meal').forEach(cb => {
            cb.addEventListener('click', (e) => {
                const existRid   = cb.dataset.existingRoomId;
                const ridInput   = tr.querySelector('.js-user-room');
                const currentRid = ridInput?.value ?? '';
                const eatFlag    = cb.dataset.eatFlag;
                if (existRid && currentRid && existRid !== currentRid && eatFlag !== '0') {
                    e.preventDefault();
                    alert('この利用者は別の部屋で予約されています。部屋を変更するか、該当食種のチェックを外してください。');
                }
            });
        });
    }

    // --------------------------------------------------------------------
    // 5. GET: ユーザー・フラグ取得して描画
    // --------------------------------------------------------------------
    function fetchUserData() {
        const url = buildApiUrl(roomId, date, mealType);
        fetch(url, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((json) => {
                if (!json || json.status !== 'success' || !json.data) {
                    throw new Error(json?.message || '取得エラー');
                }
                renderUserCheckboxes(json.data);
            })
            .catch((err) => {
                console.error('取得失敗:', err);
                userTableBody.innerHTML = '<tr><td colspan="6">データを取得できませんでした。</td></tr>';
            });
    }

    function renderUserCheckboxes(payload) {
        const users         = Array.isArray(payload.users) ? payload.users : [];
        const flags         = payload.userReservations || {};
        const contextRoomId = String(payload.contextRoom?.id ?? roomId);

        if (users.length === 0) {
            userTableBody.innerHTML = '<tr><td colspan="6">該当する利用者がいません。</td></tr>';
            return;
        }

        const rows = [];
        users.forEach((u) => {
            const uFlags = flags[String(u.id)] || {};
            const allow  = !!u.allowEdit;

            const tds = [
                `<td>${esc(u.name)}</td>`,
                roomsCellHTML(u, contextRoomId),
                mealCellHTML(u.id, 1, uFlags[1], allow, contextRoomId),
                mealCellHTML(u.id, 2, uFlags[2], allow, contextRoomId),
                mealCellHTML(u.id, 3, uFlags[3], allow, contextRoomId),
                mealCellHTML(u.id, 4, uFlags[4], allow, contextRoomId),
            ].join('');

            rows.push(`<tr data-user-id="${esc(u.id)}">${tds}</tr>`);
        });

        userTableBody.innerHTML = rows.join('');
        // 行ごとのガード
        userTableBody.querySelectorAll('tr').forEach(bindRowGuards);
    }

    // --------------------------------------------------------------------
    // 6. POST: 保存（フォームがあれば JSON 送信に差し替え）
    // --------------------------------------------------------------------
    function buildUsersPayloadForPost(sendMealType) {
        // 新APIは mealType（URLで固定）のみ解釈する想定
        // ここでは該当 mealType のチェック状態だけ送る。
        const usersPayload = {};
        userTableBody.querySelectorAll('tr[data-user-id]').forEach((tr) => {
            const uid  = tr.getAttribute('data-user-id');
            const rsel = tr.querySelector('.js-user-room'); // select or hidden
            const rid  = rsel?.value;
            if (!uid || !rid) return;

            const cb = tr.querySelector(`input.js-meal[data-reservation-type="${sendMealType}"]`);
            const obj = { room_id: Number(rid) };
            obj[String(sendMealType)] = !!(cb && cb.checked);
            usersPayload[String(uid)] = obj;
        });
        return usersPayload;
    }

    async function postSaveJSON() {
        const url = buildApiUrl(roomId, date, mealType);
        const users = buildUsersPayloadForPost(mealType);
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: JSON.stringify({ users })
            });
        } catch (e) {
            alert('保存リクエストの送信に失敗しました。');
            return;
        }

        let json;
        try { json = await res.json(); } catch { alert('保存応答の解析に失敗しました。'); return; }

        if (!res.ok || !json || json.status !== 'success') {
            console.warn('save skipped:', json?.result?.skipped);
            alert(json?.message || '直前予約の更新に失敗しました。');
            return;
        }

        alert('直前予約を更新しました。');
        // 成功後は再取得
        fetchUserData();
    }

    if (legacyForm) {
        legacyForm.addEventListener('submit', (e) => {
            e.preventDefault();
            postSaveJSON();
        });
    }

    // --------------------------------------------------------------------
    // 7. 初期ロード
    // --------------------------------------------------------------------
    fetchUserData();
});
