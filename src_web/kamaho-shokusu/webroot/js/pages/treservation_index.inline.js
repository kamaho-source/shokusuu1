
(function(){
    var cfg = window.__TRESP || {};
    window.__BASE_PATH = cfg.basePath || window.__BASE_PATH || '';
    window.GET_USERS_BY_ROOM_TPL = cfg.getUsersByRoomTpl || window.GET_USERS_BY_ROOM_TPL || '';
    window.QUERY_DATE = cfg.queryDate || window.QUERY_DATE || '';
    window.__USER_INFO = {
        isStaff: !!cfg.isStaff,
        isChild: !!cfg.isChild,
        isAdmin: !!cfg.isAdmin,
        userLevel: cfg.userLevel,
        roomId: cfg.roomId,
        roomIds: cfg.roomIds || [],
        roomCount: cfg.roomCount || 0
    };
    window.__PRIMARY_ROOM_ID = cfg.primaryRoomId || window.__PRIMARY_ROOM_ID || null;
    window.__IS_STAFF = !!cfg.isStaff;
    window.__csrfToken = cfg.csrfToken || window.__csrfToken || '';
    window.SERVER_TODAY = cfg.serverToday || window.SERVER_TODAY || '';
    window.TODAY = cfg.serverToday || window.TODAY || '';
})();
window.__BASE_PATH = window.__TRESP.basePath;
        window.GET_USERS_BY_ROOM_TPL = window.__TRESP.getUsersByRoomTpl;
        window.QUERY_DATE = window.__TRESP.queryDate;
        window.__USER_INFO = {
            isStaff: window.__TRESP.isStaff,
            isChild: window.__TRESP.isChild,
            isAdmin: window.__TRESP.isAdmin,
            userLevel: window.__TRESP.userLevel,
            roomId: window.__TRESP.roomId,
            roomIds: window.__TRESP.roomIds,
            roomCount: (window.__TRESP.roomCount || 0)
        };

(function(){
                function pageToast(message, type = 'warning') {
                    try {
                        var wrap = document.getElementById('toastWrap');
                        if (!wrap) {
                            wrap = document.createElement('div');
                            wrap.id = 'toastWrap';
                            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                            document.body.appendChild(wrap);
                        }
                        var toastEl = document.createElement('div');
                        toastEl.className = 'toast align-items-center text-bg-' + (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
                        toastEl.role = 'alert'; toastEl.ariaLive = 'assertive'; toastEl.ariaAtomic = 'true';
                        toastEl.innerHTML = '<div class="d-flex"><div class="toast-body">' + String(message) + '</div>' +
                            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                            '</div>';
                        wrap.appendChild(toastEl);
                        var instance = window.bootstrap?.Toast.getOrCreateInstance(toastEl, { delay: 3500 });
                        instance?.show();
                        toastEl.addEventListener('hidden.bs.toast', function(){ toastEl.remove(); });
                    } catch (e) {
                        console.log('[pageToast]', message);
                    }
                }

                // 参照用に公開
                window.pageToast = pageToast;
            })();

function notifyUser(message, type) {
    var tone = type || 'warning';
    if (window.pageToast) {
        window.pageToast(message, tone);
        return;
    }
    try { console.log('[notify]', message); } catch (_) {}
}

(function(){
            if (typeof window.__BASE_PATH === 'undefined') {
                window.__BASE_PATH = window.__TRESP.basePath;
            }
            window.GET_USERS_BY_ROOM_TPL = window.__TRESP.getUsersByRoomTpl;
            window.__PRIMARY_ROOM_ID = window.__TRESP.primaryRoomId;
        })();

// 予約チェックボックスのセレクタ
    const mealSelectors = [
        'input[type="checkbox"][name*="breakfast"]',
        'input[type="checkbox"][name*="lunch"]',
        'input[type="checkbox"][name*="dinner"]',
        'input[type="checkbox"][name*="bento"]'
    ];

    function enforceMealLimit(scope) {
        const root = scope || document;
        const cbs = mealSelectors.map(sel => Array.from(root.querySelectorAll(sel))).flat();
        const checked = cbs.filter(cb => cb.checked);

        // 3つ以上チェック済みなら、残りはdisabled
        if (checked.length >= 3) {
            cbs.forEach(cb => {
                if (!cb.checked) {
                    cb.disabled = true;
                    cb.title = '最大3つまで選択できます';
                }
            });
        } else {
            cbs.forEach(cb => {
                cb.disabled = false;
                cb.title = '';
            });
        }

        // 個人・集団予約の昼食と弁当排他制御
        const lunchCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="lunch"],input[type="checkbox"][name$="[lunch]"]'));
        const bentoCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="bento"],input[type="checkbox"][name$="[bento]"]'));

        lunchCbs.forEach((lunchCb, idx) => {
            // 対応するbentoCbを探す（同じ親要素内で）
            let bentoCb = null;
            // 個人予約
            if (lunchCb.name && lunchCb.name.includes('reservation')) {
                bentoCb = root.querySelector(`input[type="checkbox"][name="reservation[弁当]"]`);
            }
            // 集団予約
            else if (lunchCb.name && lunchCb.name.startsWith('users[')) {
                const userId = lunchCb.name.match(/^users\[(\d+)\]\[lunch\]$/);
                if (userId) {
                    bentoCb = root.querySelector(`input[type="checkbox"][name="users[${userId[1]}][bento]"]`);
                }
            }
            // Fallback: indexで対応
            if (!bentoCb && bentoCbs[idx]) bentoCb = bentoCbs[idx];

            if (lunchCb.checked) {
                if (bentoCb) {
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に予約できません';
                }
            } else if (bentoCb && bentoCb.checked) {
                lunchCb.disabled = true;
                lunchCb.title = '昼食と弁当は同時に予約できません';
            } else {
                lunchCb.disabled = false;
                lunchCb.title = '';
                if (bentoCb) {
                    bentoCb.disabled = false;
                    bentoCb.title = '';
                }
            }
        });
    }

    // 変更時にバリデーション実行
    mealSelectors.forEach(sel => {
        document.querySelectorAll(sel).forEach(cb => {
            cb.addEventListener('change', () => enforceMealLimit(cb.closest('form')));
        });
    });

    // 初期表示時にも実行
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form').forEach(f => enforceMealLimit(f));
    });

    const exportBtn = document.getElementById('exportNow');
    if (exportBtn) {
        function setExportLoading(loading) {
            const btn = document.getElementById('exportNow');
            const spn = document.getElementById('exportSpinner');
            if (!btn || !spn) return;
            btn.disabled = !!loading;
            spn.classList.toggle('d-none', !loading);
        }

        function showToast(message, type = 'success') {
            let wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            const toastEl = document.createElement('div');
            toastEl.className = 'toast align-items-center text-bg-' + (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
            toastEl.role = 'alert'; toastEl.ariaLive = 'assertive'; toastEl.ariaAtomic = 'true';
            toastEl.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
            wrap.appendChild(toastEl);
            const t = window.bootstrap?.Toast.getOrCreateInstance(toastEl, { delay: 3000 });
            t?.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        }

        function setRangePreset(preset){
            const from = document.getElementById('fromDate');
            const to   = document.getElementById('toDate');
            const chip = document.getElementById('rangeChip');
            if (!from || !to) return;

            const today = new Date(); today.setHours(0,0,0,0);
            const firstDay = (y,m)=> new Date(y, m, 1);
            const lastDay  = (y,m)=> new Date(y, m+1, 0);

            let s, e;
            switch (preset) {
                case 'this-week': {
                    const d = new Date(today);
                    const day = d.getDay();
                    const mon = new Date(d); mon.setDate(d.getDate() - ((day + 6) % 7));
                    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
                    s = mon; e = sun; break;
                }
                case 'this-month': {
                    s = firstDay(today.getFullYear(), today.getMonth());
                    e = lastDay(today.getFullYear(), today.getMonth()); break;
                }
                case 'next-month': {
                    const y = today.getFullYear(), m = today.getMonth() + 1;
                    s = firstDay(y, m); e = lastDay(y, m); break;
                }
                case 'last-month': {
                    const y = today.getFullYear(), m = today.getMonth() - 1;
                    s = firstDay(y, m); e = lastDay(y, m); break;
                }
                default: return;
            }
            const fmt = d => d.toISOString().slice(0,10);
            from.value = fmt(s);
            to.value   = fmt(e);
            if (chip) chip.textContent = `${from.value} 〜 ${to.value}`;
        }

        document.querySelectorAll('[data-range-preset]').forEach(btn=>{
            btn.addEventListener('click', ()=> setRangePreset(btn.dataset.rangePreset));
        });

        ['fromDate','toDate'].forEach(id=>{
            document.getElementById(id)?.addEventListener('change', ()=>{
                const f = document.getElementById('fromDate')?.value;
                const t = document.getElementById('toDate')?.value;
                if (f && t) {
                    const chip = document.getElementById('rangeChip');
                    if (chip) chip.textContent = `${f} 〜 ${t}`;
                }
            });
        });

        async function downloadWorkbook(workbook, filename){
            workbook.worksheets.forEach(ws=>{
                ws.columns.forEach((col, idx)=>{
                    let maxLen=10;
                    ws.eachRow({includeEmpty:true}, row=>{
                        const v=row.getCell(idx+1).value;
                        if(v){
                            const text = typeof v==='object' ? String(v.text || (v.richText?v.richText.map(rt=>rt.text).join('') : '')) : String(v);
                            const len = Array.from(text).reduce((sum,ch)=> sum + (/[ -~]/.test(ch)?1:2), 0);
                            if(len>maxLen) maxLen=len;
                        }
                    });
                    col.width=maxLen+2;
                });
            });
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
            const a=document.createElement('a');
            a.href=URL.createObjectURL(blob); a.download=filename;
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }

        document.getElementById('exportNow')?.addEventListener('click', async ()=>{
            try {
                const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';
                const from = document.getElementById('fromDate')?.value;
                const to   = document.getElementById('toDate')?.value;
                if(!from || !to){ showToast('開始日・終了日を入力してください。', 'warning'); return; }
                if(from > to){ showToast('開始日は終了日以前の日付を指定してください。', 'warning'); return; }

                const isPlan = document.getElementById('typePlan')?.checked;
                const endpoint = isPlan ? window.__TRESP.exportJsonUrl
                    : window.__TRESP.exportJsonRankUrl;

                setExportLoading(true);

                const res = await fetch(`${endpoint}?from=${from}&to=${to}`, { headers:{'X-CSRF-Token': csrfToken} });
                if (!res.ok) throw new Error(`APIエラー: ${res.status}`);
                const raw = await res.json();
                if (raw && raw.ok === false) {
                    throw new Error(raw.message || 'APIエラー');
                }
                const normalized = window.normalizeApiPayload ? window.normalizeApiPayload(raw) : raw;
                const json = (raw && typeof raw === 'object' && Object.prototype.hasOwnProperty.call(raw, 'ok'))
                    ? (Object.prototype.hasOwnProperty.call(raw, 'data') ? raw.data : normalized)
                    : raw;

                const isEmpty = (() => {
                    if (isPlan) {
                        const hasRooms   = json.rooms && Object.keys(json.rooms).length>0;
                        const hasOverall = json.overall && Object.keys(json.overall).length>0;
                        return !hasRooms && !hasOverall;
                    } else {
                        const rows = Array.isArray(json) ? json : Object.values(json);
                        return rows.length === 0;
                    }
                })();
                if (isEmpty) { showToast('出力対象データがありません。', 'warning'); return; }

                if (isPlan) {
                    const wb = new ExcelJS.Workbook();
                    wb.creator='食数予約システム'; wb.created=new Date(); wb.modified=new Date();

                    const addHeader = (sheet, withRoom=false)=>{
                        const header = withRoom ? ['日付','部屋名','朝食','昼食','夕食','弁当','合計'] : ['日付','朝食','昼食','夕食','弁当','合計'];
                        const row = sheet.addRow(header); row.font={bold:true}; sheet.views=[{state:'frozen',ySplit:1}];
                    };
                    const addTotalRow = (sheet, withRoom=false)=>{
                        const totals=[0,0,0,0];
                        sheet.eachRow((row,i)=>{
                            if(i===1) return;
                            const off = withRoom?2:1;
                            for(let k=0;k<totals.length;k++){ totals[k] += Number(row.getCell(off+k+1).value ?? 0); }
                        });
                        const grand = totals.reduce((a,b)=>a+b,0);
                        const vals = withRoom ? ['合計','',...totals,grand] : ['合計',...totals,grand];
                        const trow = sheet.addRow(vals); trow.font={bold:true};
                        trow.eachCell(c=>{ c.border={top:{style:'thin'}, bottom:{style:'double'}}; });
                    };

                    const hasRooms   = json.rooms && Object.keys(json.rooms).length>0;
                    const hasOverall = json.overall && Object.keys(json.overall).length>0;

                    const sh = wb.addWorksheet('全体'); addHeader(sh, true);
                    if (hasRooms){
                        const allDates=new Set(); const rooms=Object.keys(json.rooms).sort();
                        rooms.forEach(r=>{ Object.keys(json.rooms[r]??{}).forEach(d=>allDates.add(d)); });
                        [...allDates].sort().forEach(date=>{
                            rooms.forEach(r=>{
                                const c=(json.rooms[r]??{})[date]??{};
                                const total=(c['朝']??0)+(c['昼']??0)+(c['夜']??0)+(c['弁当']??0);
                                sh.addRow([date, r, c['朝']??0, c['昼']??0, c['夜']??0, c['弁当']??0, total]);
                            });
                        });
                    } else if (hasOverall){
                        Object.keys(json.overall).sort().forEach(date=>{
                            const c=json.overall[date]??{};
                            const total=(c['朝']??0)+(c['昼']??0)+(c['夜']??0)+(c['弁当']??0);
                            sh.addRow([date,'全体',c['朝']??0,c['昼']??0,c['夜']??0,c['弁当']??0,total]);
                        });
                    }
                    addTotalRow(sh, true);

                    if (hasRooms){
                        Object.keys(json.rooms).forEach(room=>{
                            const name = room.replace(/[:\\/?*\[\]]/g,'').substring(0,31) || '部屋';
                            const ws = wb.addWorksheet(name); addHeader(ws);
                            const rdata = json.rooms[room];
                            Object.keys(rdata).sort().forEach(date=>{
                                const m=rdata[date];
                                const total=(m['朝']??0)+(m['昼']??0)+(m['夜']??0)+(m['弁当']??0);
                                ws.addRow([date, m['朝']??0, m['昼']??0, m['夜']??0, m['弁当']??0, total]);
                            });
                            addTotalRow(ws);
                        });
                    }

                    await downloadWorkbook(wb, `食数予定表_${from}〜${to}.xlsx`);
                } else {
                    const rows = Array.isArray(json) ? json : Object.values(json);
                    const wb=new ExcelJS.Workbook();
                    const ws=wb.addWorksheet('実施食数表');
                    const cols=[
                        {key:'reservation_date', header:'日付'},
                        {key:'rank_name',        header:'ランク'},
                        {key:'gender',           header:'性別'},
                        {key:'breakfast',        header:'朝食'},
                        {key:'lunch',            header:'昼食'},
                        {key:'dinner',           header:'夕食'},
                        {key:'bento',            header:'弁当'},
                        {key:'total_eaters',     header:'合計'}
                    ];
                    ws.addRow(cols.map(c=>c.header)).font={bold:true};
                    rows.forEach(r => ws.addRow(cols.map(c => r[c.key] ?? '')));

                    ws.columns.forEach((col, idx)=>{
                        let maxLen=10;
                        ws.eachRow({includeEmpty:true}, row=>{
                            const v=row.getCell(idx+1).value;
                            if(v){
                                const text = typeof v==='object' ? String(v.text || (v.richText?v.richText.map(rt=>rt.text).join('') : '')) : String(v);
                                const len = Array.from(text).reduce((sum,ch)=> sum + (/[ -~]/.test(ch)?1:2), 0);
                                if(len>maxLen) maxLen=len;
                            }
                        });
                        col.width=maxLen+2;
                    });

                    const buffer = await wb.xlsx.writeBuffer();
                    const blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
                    const a=document.createElement('a');
                    a.href=URL.createObjectURL(blob); a.download=`実施食数表_${from}〜${to}.xlsx`;
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    URL.revokeObjectURL(a.href);
                }

                showToast('エクスポートが完了しました。', 'success');
            } catch (err) {
                console.error(err);
                let msg = 'エクスポートに失敗しました。';
                if (err && err.message) msg += '\n' + err.message;
                showToast(msg, 'danger');
            } finally {
                setExportLoading(false);
            }
        });
    }

function openModalById(id){
        var el = document.getElementById(id);
        if (!el) return;
        try {
            if (window.bootstrap && window.bootstrap.Modal) {
                var m = window.bootstrap.Modal.getOrCreateInstance(el);
                m.show();
                return;
            }
        } catch(e){}
        el.classList.add('show');
        el.style.display = 'block';
        el.removeAttribute('aria-hidden');
        el.setAttribute('aria-modal','true');
        el.scrollTop = 0;
        document.body.classList.add('modal-open');
        if (!document.getElementById('___modal-backdrop')) {
            var bd = document.createElement('div');
            bd.id='___modal-backdrop';
            bd.className='modal-backdrop fade show';
            document.body.appendChild(bd);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        var metaEl = document.querySelector('meta[name="csrfToken"]');
        var csrfToken = metaEl ? metaEl.getAttribute('content') : '';
        window.__csrfToken = csrfToken;

        if (window.jQuery && typeof jQuery.ajaxSetup === 'function') {
            jQuery.ajaxSetup({
                headers: { 'X-CSRF-Token': csrfToken },
                cache: false
            });
        }

        var IS_CHILD = window.__TRESP.isChild;
        var USE_KID_UI = window.__TRESP.isKidUI;

        var TODAY  = window.__TRESP.todayJs;
        var TODAY_STATE = {
            lunch: (window.__TRESP.todayState && window.__TRESP.todayState.lunch),
            bento: (window.__TRESP.todayState && window.__TRESP.todayState.bento)
        };

        var MY_DETAILS = window.__TRESP.myDetails;

        if (USE_KID_UI) {
            // 子供用: 部屋必須 + トグルURL生成
            const roomSelect = document.getElementById('kid-room-select');
            let currentRoomId = window.__TRESP.currentRoom || '';
            const toggleBase = window.__TRESP.toggleBase || '';
            function getToggleUrl(){
                if (!currentRoomId) return '';
                return toggleBase.replace('__ROOM__', encodeURIComponent(String(currentRoomId)));
            }
            if (roomSelect) {
                roomSelect.addEventListener('change', () => { currentRoomId = roomSelect.value || ''; });
            }

            var modeSelectEl = document.getElementById('kidModeSelect');
            var kidMode = modeSelectEl ? modeSelectEl.value : 'auto';

            var mealNamesShort = {1:'朝', 2:'昼', 3:'夜', 4:'弁'};
            var mealJaFull     = {1:'朝食', 2:'昼食', 3:'夕食', 4:'弁当'};

            function updateModeBadge(){
                var badge = document.getElementById('kidModeBadge');
                if (!badge) return;
                var label = kidMode === 'auto' ? '自動判定' : (kidMode === 'late' ? '直前' : '通常');
                badge.textContent = 'モード：' + label;
            }

            function applyKidModeUI(){
                var btns = document.querySelectorAll('.kid-meal-btn');
                for (var i=0; i<btns.length; i++){
                    var btn = btns[i];
                    var isMine = btn.getAttribute('data-is-mine') === '1';
                    var originalIsLast = btn.getAttribute('data-is-last-minute') === '1';
                    var targetIsLast = (kidMode === 'auto') ? originalIsLast : (kidMode === 'late');

                    var meal  = Number(btn.getAttribute('data-meal') || 0);
                    var name  = mealNamesShort[meal] || '';

                    var cap = targetIsLast ? (isMine ? '変更(直前)' : '追加(直前)') : (isMine ? '取消' : '追加');
                    btn.setAttribute('data-target-is-last', targetIsLast ? '1' : '0');

                    var capEl = btn.querySelector('.btn-cap');
                    if (capEl) {
                        capEl.innerHTML = name + '<small> ' + cap + '</small>';
                    }
                    btn.setAttribute('aria-label', name + '：' + cap);
                }
                updateModeBadge();
            }

            function filterCardsByMode(){
                var cards = document.querySelectorAll('.kid-card');
                var firstVisible = null;
                for (var i=0; i<cards.length; i++){
                    var card = cards[i];
                    var isLast = card.getAttribute('data-is-last-minute') === '1';
                    var show = true;
                    if (kidMode === 'late')   show =  isLast;
                    if (kidMode === 'normal') show = !isLast;
                    card.style.display = show ? '' : 'none';
                    if (show && !firstVisible) firstVisible = card;
                }
                if (firstVisible && firstVisible.scrollIntoView) {
                    firstVisible.scrollIntoView({ behavior:'smooth', block:'start' });
                }
            }

            applyKidModeUI();
            filterCardsByMode();
            if (modeSelectEl) {
                modeSelectEl.addEventListener('change', function(e){
                    kidMode = e.target.value || 'auto';
                    applyKidModeUI();
                    filterCardsByMode();
                });
            }

            if (!IS_CHILD) {
                kidMode = 'normal';
                if (modeSelectEl) {
                    modeSelectEl.value = 'normal';
                    modeSelectEl.disabled = true;
                }
                updateModeBadge();
                filterCardsByMode();
            }

            function setBtnReserved(btn, reserved){
                var cls = btn.classList;
                var colorTokens   = (btn.getAttribute('data-meal-class')    || 'btn-primary').split(/\s+/).filter(Boolean);
                var neutralTokens = (btn.getAttribute('data-neutral-class') || 'btn-outline-secondary').split(/\s+/).filter(Boolean);
                var legacyTokens = ['btn-outline-light', 'border'];
                for (var i=0; i<colorTokens.length; i++)   { cls.remove(colorTokens[i]); }
                for (var j=0; j<neutralTokens.length; j++) { cls.remove(neutralTokens[j]); }
                for (var k=0; k<legacyTokens.length; k++)  { cls.remove(legacyTokens[k]); }

                if (reserved){
                    for (var a=0; a<colorTokens.length; a++) { cls.add(colorTokens[a]); }
                    btn.setAttribute('data-is-mine', '1');
                } else {
                    for (var b=0; b<neutralTokens.length; b++) { cls.add(neutralTokens[b]); }
                    btn.setAttribute('data-is-mine', '0');
                }

                var meal = Number(btn.getAttribute('data-meal')||0);
                var name = mealNamesShort[meal] || '';
                var targetIsLast = btn.getAttribute('data-target-is-last') === '1';
                var capEl = btn.querySelector('.btn-cap');
                if (capEl){
                    var cap = targetIsLast ? (reserved ? '変更(直前)' : '追加(直前)') : (reserved ? '取消' : '追加');
                    capEl.innerHTML = name + '<small> ' + cap + '</small>';
                }
                btn.setAttribute('aria-label', name + '：' + (reserved ? (targetIsLast?'変更(直前)':'取消') : (targetIsLast?'追加(直前)':'追加')));
            }

            function updateDayStatus(dateStr){
                var card = document.getElementById('card-' + dateStr);
                if (!card) return;
                var detail = MY_DETAILS[dateStr] || {};
                var any = !!(detail.breakfast || detail.lunch || detail.bento || detail.dinner);
                var ok = card.querySelector('.status-flag.ok');
                var none = card.querySelector('.status-flag.none');
                if (ok && none){
                    ok.style.display = any ? 'inline-flex' : 'none';
                    none.style.display = any ? 'none' : 'inline-flex';
                }
            }

            function refreshDayUI(dateStr){
                var esc = function(s){ return (window.CSS && CSS.escape) ? CSS.escape(s) : s; };
                var detail = MY_DETAILS[dateStr] || { breakfast:false, lunch:false, dinner:false, bento:false };
                var list = document.querySelectorAll('.kid-meal-btn[data-date="' + esc(dateStr) + '"]');
                for (var i=0; i<list.length; i++){
                    var btn = list[i];
                    var key = btn.getAttribute('data-meal-key');
                    if (!key) continue;
                    setBtnReserved(btn, !!detail[key]);
                }
                updateDayStatus(dateStr);
                if (dateStr === TODAY) {
                    TODAY_STATE.lunch = !!detail.lunch;
                    TODAY_STATE.bento = !!detail.bento;
                }
            }

            function showConflict(html, onResolve, actionLabel){
                var body = document.getElementById('conflictBody');
                var act  = document.getElementById('conflictAction');
                var reload = document.getElementById('conflictReload');
                var el   = document.getElementById('conflictModal');
                if (body) body.innerHTML = html || 'この操作は競合しています。';
                if (reload) {
                    reload.classList.add('d-none');
                    reload.classList.remove('disabled');
                    reload.setAttribute('aria-disabled', 'false');
                    reload.textContent = reload.getAttribute('data-default-label') || '対象日を再読み込み';
                    reload.setAttribute('href', '#');
                    reload.onclick = null;
                }
                if (act) {
                    if (!act.getAttribute('data-default-label')) {
                        act.setAttribute('data-default-label', act.textContent || '競合先を解除して続行');
                    }
                    act.classList.remove('d-none');
                    act.classList.remove('disabled');
                    act.setAttribute('aria-disabled','false');
                    act.textContent = actionLabel || '競合先を解除して続行';
                    act.onclick = function(e){
                        e.preventDefault();
                        if (act.classList.contains('disabled')) return false;
                        act.classList.add('disabled');
                        act.setAttribute('aria-disabled','true');
                        act.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>処理中...';
                        try {
                            if (typeof onResolve === 'function') onResolve();
                        } catch (err) {
                            act.classList.remove('disabled');
                            act.setAttribute('aria-disabled','false');
                            act.textContent = actionLabel || act.getAttribute('data-default-label') || '競合先を解除して続行';
                            notifyUser((err && err.message) || '競合解消の処理を開始できませんでした。', 'danger');
                            return false;
                        }
                        if (window.bootstrap && window.bootstrap.Modal) {
                            var m = window.bootstrap.Modal.getOrCreateInstance(el);
                            if (m) m.hide();
                        } else {
                            el.classList.remove('show'); el.style.display='none';
                            var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                        }
                        return false;
                    };
                }
                openModalById('conflictModal');
            }

            function makeLateNoticeHtml(date, mealIdx, action) {
                var actionLabel = action
                    ? '<span class="badge bg-danger ms-1">' + action + '</span>'
                    : '';
                return '<div class="late-notice-alert alert alert-danger mb-0">'
                    + '<i class="bi bi-exclamation-circle-fill me-1"></i>'
                    + 'この期間はすでに<strong>発注済</strong>です。内容をよく確認してください。'
                    + '</div>'
                    + '<dl class="late-notice-detail row g-0 mt-2 mb-1">'
                    + '<dt class="col-4 text-muted small">対象日</dt>'
                    + '<dd class="col-8 fw-semibold mb-1">' + date + '</dd>'
                    + '<dt class="col-4 text-muted small">食事種別</dt>'
                    + '<dd class="col-8 fw-semibold mb-1">' + mealJaFull[mealIdx] + actionLabel + '</dd>'
                    + '</dl>';
            }

            function showLateNotice(html, onAgree){
                var body = document.getElementById('lateNoticeBody');
                var agree = document.getElementById('lateAgreeCheck');
                var proceed = document.getElementById('lateProceed');
                var modalEl = document.getElementById('lateNoticeModal');
                if (body) body.innerHTML = html;
                if (agree){
                    agree.checked = false;
                    agree.onchange = function(){
                        if (agree.checked) {
                            if (proceed){
                                proceed.classList.remove('disabled');
                                proceed.setAttribute('aria-disabled','false');
                                proceed.setAttribute('tabindex','0');
                            }
                        } else {
                            if (proceed){
                                proceed.classList.add('disabled');
                                proceed.setAttribute('aria-disabled','true');
                                proceed.setAttribute('tabindex','-1');
                            }
                        }
                    };
                }
                if (proceed){
                    proceed.onclick = function(e){
                        if (proceed.classList.contains('disabled')) { e.preventDefault(); return false; }
                        if (window.bootstrap && window.bootstrap.Modal) {
                            var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            if (m) m.hide();
                        } else {
                            modalEl.classList.remove('show'); modalEl.classList.remove('d-block'); modalEl.style.display='none';
                            var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                        }
                        if (typeof onAgree === 'function') onAgree();
                        e.preventDefault();
                        return false;
                    };
                }
                openModalById('lateNoticeModal');
            }

            async function callToggle(dateStr, mealNumber, wantValue, override){
                const url = getToggleUrl();
                if (!url) {
                    const msg = '先に「利用する部屋」を選択してください。';
                    notifyUser(msg, 'warning');
                    throw new Error('Room not selected');
                }
                if (!csrfToken)  throw new Error('CSRFトークンが取得できていません。再読み込みしてください。');

                const res = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        date: String(dateStr),
                        meal: Number(mealNumber),
                        value: wantValue ? 1 : 0,
                        override: override ? 1 : 0
                    })
                });

                const ct = res.headers.get('content-type') || '';
                const isJson = ct.indexOf('application/json') !== -1;
                const payload = isJson ? await res.json() : { message: await res.text() };

                if (res.status === 409) {
                    const err = new Error(payload?.message || '昼食と弁当は同時に予約できません。');
                    err.name = 'Conflict';
                    err.details = payload;
                    throw err;
                }
                if (res.status === 422) {
                    const err = new Error(payload?.message || '入力が不正です。');
                    err.name = 'Unprocessable';
                    throw err;
                }
                if (res.status === 400) {
                    const err = new Error(payload?.message || '不正なリクエストです。');
                    err.name = 'BadRequest';
                    throw err;
                }

                if (payload && payload.ok === true) {
                    if (payload.data && typeof payload.data === 'object') {
                        return Object.assign({ ok: true, message: payload.message }, payload.data);
                    }
                    return payload;
                }
                if (payload && typeof payload.status === 'string') {
                    const st = payload.status.toLowerCase();
                    if (st === 'success') return payload;
                    if (st === 'error') {
                        const msg = payload.message || '更新に失敗しました。';
                        const err = new Error(msg);
                        err.name = /2週間|１４日|14日|two/i.test(msg) ? 'RuleError' : 'ServerError';
                        throw err;
                    }
                }

                if (!res.ok) {
                    throw new Error(payload?.message || ('更新に失敗しました（' + res.status + '）'));
                }
                return payload;
            }

            function conflictPair(mealIdx){ if (mealIdx === 2) return 4; if (mealIdx === 4) return 2; return null; }

            function applyDetailsAndRefresh(date, payload, btn, mealKey){
                if (payload && typeof payload.details === 'object') {
                    var prev = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    MY_DETAILS[date] = {
                        breakfast: Object.prototype.hasOwnProperty.call(payload.details,'breakfast') ? payload.details.breakfast : prev.breakfast,
                        lunch:     Object.prototype.hasOwnProperty.call(payload.details,'lunch')     ? payload.details.lunch     : prev.lunch,
                        dinner:    Object.prototype.hasOwnProperty.call(payload.details,'dinner')    ? payload.details.dinner    : prev.dinner,
                        bento:     Object.prototype.hasOwnProperty.call(payload.details,'bento')     ? payload.details.bento     : prev.bento
                    };
                } else {
                    var d = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    if (mealKey) d[mealKey] = !!(payload && payload.value);
                    MY_DETAILS[date] = d;
                }
                refreshDayUI(date);
            }

            async function resolveConflictSequence(date, targetIdx, targetOn, btn, mealKey){
                var opponentIdx = conflictPair(targetIdx);
                if (!opponentIdx) throw new Error('競合先が特定できませんでした。');
                await callToggle(date, opponentIdx, false, false);
                var result = await callToggle(date, targetIdx, targetOn, false);
                applyDetailsAndRefresh(date, result, btn, mealKey);
            }

            var kidBtns = document.querySelectorAll('.kid-meal-btn');
            Array.prototype.forEach.call(kidBtns, function(btn){
                btn.addEventListener('click', async function(ev){
                    ev.preventDefault();
                    var date  = btn.getAttribute('data-date');
                    var mealIdx = Number(btn.getAttribute('data-meal') || 0);
                    var mealKey = btn.getAttribute('data-meal-key');
                    if (!date || !mealIdx || !mealKey) return;

                    var agreedOnce = false;
                    var detail = MY_DETAILS[date] || { breakfast:false, lunch:false, dinner:false, bento:false };
                    var current = !!detail[mealKey];
                    var nextVal = !current;

                    var localConflict =
                        nextVal &&
                        ((mealKey === 'lunch'  && (detail.bento || (date === TODAY && TODAY_STATE.bento))) ||
                            (mealKey === 'bento'  && (detail.lunch || (date === TODAY && TODAY_STATE.lunch))));

                    var isLast = (btn.getAttribute('data-target-is-last') || btn.getAttribute('data-is-last-minute')) === '1';

                    function withLateAgreement(html, action){
                        if (isLast && !agreedOnce) {
                            showLateNotice(html, function(){ agreedOnce = true; if (typeof action === 'function') action(); });
                        } else {
                            if (typeof action === 'function') action();
                        }
                    }

                    var conflictActionLabel =
                        mealIdx === 2 ? 'お弁当からお昼に登録を変更する'
                            : mealIdx === 4 ? 'お昼からお弁当に登録を変更する'
                                : '競合先を解除して続行';

                    async function doToggle(){
                        try {
                            btn.disabled = true; btn.style.opacity = .65;

                            if (localConflict) {
                                var labelFrom = mealIdx === 2 ? 'お弁当' : '昼ごはん';
                                var labelTo   = mealIdx === 2 ? '昼ごはん' : 'お弁当';

                                showConflict(
                                    'この日（' + date + '）は<strong>' + labelFrom + '</strong>の予約があります。<br><strong>' + labelFrom + '</strong>を先に<strong>取り消し</strong>てから、<strong>' + labelTo + '</strong>を登録してもよろしいですか？',
                                    async function(){
                                        var html = makeLateNoticeHtml(date, mealIdx, null);
                                        withLateAgreement(html, async function(){
                                            try { await resolveConflictSequence(date, mealIdx, true, btn, mealKey); }
                                            catch (ee) { notifyUser((ee && ee.message) || '競合解消に失敗しました。', 'danger'); }
                                            finally { btn.disabled = false; btn.style.opacity = 1; }
                                        });
                                    },
                                    conflictActionLabel
                                );
                                return;
                            }

                            var json = await callToggle(date, mealIdx, nextVal, false);
                            applyDetailsAndRefresh(date, json, btn, mealKey);

                        } catch (e) {
                            if (e && e.name === 'RuleError') {
                                notifyUser(e.message || '当日から2週間後までは予約の登録ができません。', 'warning');
                            } else if (e && e.name === 'Conflict') {
                                showConflict(
                                    ((e && e.message) || '昼食と弁当は同時に予約できません。') + '<br><small class="text-muted">（競合先の予約を先にOFFしてから目的の予約をONにします）</small>',
                                    async function(){
                                        var html = makeLateNoticeHtml(date, mealIdx, null);
                                        withLateAgreement(html, async function(){
                                            try {
                                                btn.disabled = true; btn.style.opacity = .65;
                                                try {
                                                    var over = await callToggle(date, mealIdx, nextVal, true);
                                                    applyDetailsAndRefresh(date, over, btn, mealKey);
                                                } catch (ovErr) {
                                                    await resolveConflictSequence(date, mealIdx, nextVal, btn, mealKey);
                                                }
                                            } catch (ee) {
                                                notifyUser((ee && ee.message) || '競合解消に失敗しました。', 'danger');
                                            } finally {
                                                btn.disabled = false; btn.style.opacity = 1;
                                            }
                                        });
                                    },
                                    conflictActionLabel
                                );
                            } else {
                                notifyUser((e && e.message) || '予約の更新に失敗しました', 'danger');
                            }
                        } finally {
                            if (!localConflict) { btn.disabled = false; btn.style.opacity = 1; }
                        }
                    }

                    var bodyHtml = makeLateNoticeHtml(date, mealIdx, nextVal ? '追加' : 'キャンセル');
                    withLateAgreement(bodyHtml, doToggle);
                }, false);
            });

        } else {
            var reservedDates  = window.__TRESP.reservedDates;
            var existingEvents = window.__TRESP.existingEvents;

            var calendarEl    = document.getElementById('calendar');
            var fromDateInput = document.getElementById('fromDate');
            var toDateInput   = document.getElementById('toDate');

            function formatYmd(d){
                var y=d.getFullYear(), m=('0'+(d.getMonth()+1)).slice(-2), dd=('0'+d.getDate()).slice(-2);
                return y+'-'+m+'-'+dd;
            }
            function updateInputsByCalendar(view){
                if(!fromDateInput || !toDateInput) return;
                var start=view.currentStart;
                var end=new Date(view.currentEnd); end.setDate(end.getDate()-1);
                fromDateInput.value = formatYmd(start);
                toDateInput.value   = formatYmd(end);
                var chip = document.getElementById('rangeChip');
                if (chip) { chip.textContent = fromDateInput.value + ' 〜 ' + toDateInput.value; }
            }
            var defaultDate = (function(){ var d=new Date(); d.setDate(d.getDate()+14); return d; })();

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialDate: defaultDate,
                initialView: 'dayGridMonth',
                locale: 'ja',
                firstDay: 1,
                height: 'auto',
                contentHeight: 'auto',
                expandRows: true,
                aspectRatio: 1.35,
                customButtons: { nextMonth:{ text:'次月', click:function(){ calendar.next(); } } },
                headerToolbar: { right:'prev,today,nextMonth,next', center:'' },
                buttonText: { today:'今日' },

                dayCellDidMount: function(info) {
                    var y = info.date.getFullYear();
                    var m = info.date.getMonth();
                    var d = info.date.getDate();
                    var name = (typeof JapaneseHolidays !== 'undefined' && JapaneseHolidays && typeof JapaneseHolidays.isHoliday === 'function')
                        ? JapaneseHolidays.isHoliday(new Date(y, m, d)) : null;
                    if (name) {
                        info.el.classList.add('is-holiday');
                        if (!info.el.querySelector('.fc-holiday-badge')) {
                            var badge = document.createElement('div');
                            badge.className = 'fc-holiday-badge';
                            badge.textContent = name;
                            info.el.appendChild(badge);
                        }
                    }
                },

                datesSet: function(arg){ updateInputsByCalendar(arg.view); },

                events: function(fetchInfo, successCallback){
                    var unreservedEvents=[];
                    var cur=new Date(fetchInfo.start);
                    while(cur < fetchInfo.end){
                        var dateStr = cur.toISOString().slice(0,10);
                        if(reservedDates.indexOf(dateStr) === -1){
                            unreservedEvents.push({
                                title:'未予約', start:dateStr, allDay:true,
                                backgroundColor:'#fd7e14', borderColor:'#fd7e14', textColor:'white',
                                extendedProps:{displayOrder:-10}
                            });
                        }
                        cur.setDate(cur.getDate()+1);
                    }
                    
                    var allEvents = [].concat(existingEvents, unreservedEvents);
                    successCallback(allEvents);
                },

                eventOrder: function(a,b){
                    var A = Number((a.extendedProps && typeof a.extendedProps.displayOrder !== 'undefined') ? a.extendedProps.displayOrder : 0);
                    var B = Number((b.extendedProps && typeof b.extendedProps.displayOrder !== 'undefined') ? b.extendedProps.displayOrder : 0);
                    return (isNaN(A)?0:A) - (isNaN(B)?0:B);
                },

                dateClick: function(info){
                    try {
                        window.quickOpenDayModal(info.dateStr);
                    } catch (e) {
                        console.warn('quickOpenDayModal error:', e);
                    }
                },

                eventClick: function(info){
                    var ep = info.event.extendedProps || {};
                    if (!ep.isMealCount) return; // 食数イベント以外は無視
                    info.jsEvent.stopPropagation();
                    var date   = info.event.startStr ? info.event.startStr.slice(0, 10) : '';
                    var roomId = (window.__TRESP && window.__TRESP.calRoomId != null)
                        ? window.__TRESP.calRoomId : null;
                    if (window.openMealCalUserModal) {
                        window.openMealCalUserModal(date, roomId);
                    }
                }
            });

            calendar.render();
            window.calendar = calendar;
            window.__reservationCalendar = calendar;

            // 部屋セレクタを FullCalendar ツールバー右端の先頭（「前月」ボタンの左）へ移動
            (function() {
                var wrap = document.getElementById('calRoomSelectorWrap');
                if (!wrap) return;
                // FullCalendar の右ツールバーチャンク（prev/today/next が入っている塊）
                var toolbarRight = calendarEl.querySelector('.fc-header-toolbar .fc-toolbar-chunk:last-child');
                if (!toolbarRight) return;
                wrap.style.display = 'inline-flex';
                toolbarRight.insertBefore(wrap, toolbarRight.firstChild);
            })();

            if (fromDateInput) {
                fromDateInput.addEventListener('change', function(){
                    if(fromDateInput.value) calendar.gotoDate(fromDateInput.value);
                });
            }
        }
    });

function unlockForChildren(wrap){
        if (!wrap || window.__IS_STAFF) return;
        wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function(cb){
            cb.disabled = false;
            cb.removeAttribute('data-locked');
            if (cb.title &&
                (cb.title.includes('直前予約のため') || cb.title.includes('直前期間のため'))) {
                cb.removeAttribute('title');
            }
            cb.classList?.remove('deletion-blocked');
        });
    }

    function observeChildUnlock(wrap){
        if (!wrap || window.__IS_STAFF || wrap.__childUnlockObserved) return;
        wrap.__childUnlockObserved = true;

        const mo = new MutationObserver(function(){
            unlockForChildren(wrap);
        });
        mo.observe(wrap, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['disabled', 'title', 'class']
        });
        unlockForChildren(wrap);
    }

    (function(){
        var ADD_URL = window.__TRESP.addUrl;
        var CHANGEEDIT_URL = window.__TRESP.changeEditUrl;
        window.__BASE_PATH   = window.__TRESP.basePath;
        window.__csrfToken   = window.__TRESP.csrfToken;
        window.SERVER_TODAY  = window.__TRESP.serverToday;
        window.TODAY         = window.__TRESP.serverToday;
        window.QUERY_DATE    = window.__TRESP.queryDate;
        window.__IS_STAFF    = window.__TRESP.isStaff;
        var SERVER_TODAY = window.__TRESP.todayJs;

        // 複数部屋所属の場合の情報表示
        document.addEventListener('DOMContentLoaded', function() {
            if (window.__USER_INFO) {
                console.log('ユーザー情報:', window.__USER_INFO);
                if (window.__USER_INFO.roomCount > 1) {
                    console.log('複数部屋所属:', window.__USER_INFO.roomIds);
                    console.log('表示される食数は', window.__USER_INFO.roomCount, '部屋の合計です');
                    // 必要に応じて、複数部屋所属の旨をユーザーに表示
                    if (typeof window.pageToast === 'function') {
                        setTimeout(function() {
                            window.pageToast('複数部屋(' + window.__USER_INFO.roomCount + '部屋)の合計数を表示中', 'info');
                        }, 1000);
                    }
                } else if (window.__USER_INFO.roomCount === 1) {
                    console.log('単一部屋所属:', window.__USER_INFO.roomIds);
                } else {
                    console.log('部屋所属なし');
                }
            }
        });

        function closeModalAndRefresh(modalEl) {
            try {
                if (window.bootstrap && window.bootstrap.Modal) {
                    var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    m.hide && m.hide();
                } else {
                    modalEl.classList.remove('show'); modalEl.style.display='none';
                    var bd=document.getElementById('___modal-backdrop'); if (bd) bd.remove();
                    document.body.classList.remove('modal-open');
                }
            } catch(e) {}
            if (window.__reservationCalendar && typeof window.__reservationCalendar.refetchEvents === 'function') {
                window.__reservationCalendar.refetchEvents();
            } else {
                location.reload();
            }
        }

        function getCsrfToken() {
            return (window.__csrfToken) ||
                (document.querySelector('meta[name="csrfToken"]') ? document.querySelector('meta[name="csrfToken"]').getAttribute('content') : '');
        }

        async function executeScriptsFrom(node){
            var scripts = Array.prototype.slice.call(node.querySelectorAll('script'));
            for (var i=0; i<scripts.length; i++){
                var sc = scripts[i];
                if (sc.type && sc.type !== '' && sc.type !== 'text/javascript') continue;
                var newSc = document.createElement('script');
                newSc.async = false;
                if (sc.src) {
                    await new Promise(function(resolve){
                        newSc.src = sc.src;
                        newSc.onload = resolve;
                        newSc.onerror = function(){ console.warn('script load error:', sc.src); resolve(); };
                        document.body.appendChild(newSc);
                    });
                } else {
                    newSc.text = sc.textContent || '';
                    document.body.appendChild(newSc);
                }
            }
        }

        function ensureAddModalCompat(root){
            var scope = root || document;
            var roomSelect = null;

            if (!window.GET_USERS_BY_ROOM_TPL) {
                var basePath = window.__BASE_PATH || '';
                var baseUrl = basePath + '/TReservationInfo/getUsersByRoom/';
                window.GET_USERS_BY_ROOM_TPL = baseUrl + '__RID__';
            }

            if (!window.QUERY_DATE) {
                var urlParams = new URLSearchParams(window.location.search);
                window.QUERY_DATE = urlParams.get('date') || new Date().toISOString().split('T')[0];
            }

            if (!window.buildGetUsersByRoomUrl) {
                window.buildGetUsersByRoomUrl = function(roomId) {
                    if (!roomId) {
                        return '';
                    }
                    var url = window.GET_USERS_BY_ROOM_TPL || '';
                    if (url.indexOf('__RID__') !== -1) {
                        url = url.replace('__RID__', encodeURIComponent(roomId));
                    } else {
                        url = (window.__BASE_PATH || '') + '/TReservationInfo/getUsersByRoom/' + encodeURIComponent(roomId);
                    }
                    url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(window.QUERY_DATE);
                    return url;
                };
            }

            if (!window.fetchUserData) {
                window.fetchUserData = function(roomId) {
                    try {
                        if (!roomId) {
                            return Promise.resolve();
                        }
                        if (!window.buildGetUsersByRoomUrl) {
                            return Promise.resolve();
                        }
                        var url = window.buildGetUsersByRoomUrl(roomId);
                        var tbody = document.getElementById('user-checkboxes') ||
                            scope.querySelector('#user-checkboxes') ||
                            document.querySelector('#qd-remote-wrap #user-checkboxes');

                        if (!tbody) {
                            setTimeout(function() {
                                var retryTbody = document.getElementById('user-checkboxes') ||
                                    document.querySelector('#qd-remote-wrap #user-checkboxes');
                                if (retryTbody) {
                                    window.fetchUserData(roomId);
                                }
                            }, 500);
                            return Promise.resolve();
                        }

                        tbody.innerHTML = '<tr><td colspan="5" class="text-center">読み込み中...</td></tr>';

                        return fetch(url, {
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                            .then(function(response) {
                                if (!response.ok) {
                                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                                }
                                return response.text();
                            })
                            .then(function(text) {
                                try {
                                    var data = JSON.parse(text);
                                    return data;
                                } catch (e) {
                                    throw new Error('レスポンスがJSONではありません: ' + e.message);
                                }
                            })
                            .then(function(d){
                                var users = d && d.usersByRoom;
                                if (!Array.isArray(users)) {
                                    throw new Error('usersByRoom が配列ではありません');
                                }
                                tbody.innerHTML = '';
                                if (users.length === 0) {
                                    tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">この部屋に利用者がいません。</td></tr>';
                                    return;
                                }
                                users.forEach(function(u){
                                    var tr = document.createElement('tr');
                                    tr.innerHTML =
                                        '<td>' + (u.name || 'Unknown') + '</td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][1]" value="1" ' + (Number(u.morning)===1?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][2]" value="1" ' + (Number(u.noon)===1   ?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][3]" value="1" ' + (Number(u.night)===1  ?'checked':'') + '></td>' +
                                        '<td class="text-center"><input type="checkbox" name="users['+u.id+'][4]" value="1" ' + (Number(u.bento)===1  ?'checked':'') + '></td>';
                                    tbody.appendChild(tr);
                                    var lunchCb = tr.querySelector('input[name="users['+u.id+'][2]"]');
                                    var bentoCb = tr.querySelector('input[name="users['+u.id+'][4]"]');
                                    if (window.setupLunchBentoPair && lunchCb && bentoCb) {
                                        window.setupLunchBentoPair(lunchCb, bentoCb);
                                    }
                                });
                                var tableContainer = tbody.closest('.table-responsive, #user-selection-table');
                                if (tableContainer) {
                                    tableContainer.style.maxHeight = '400px';
                                    tableContainer.style.overflowY = 'auto';
                                }
                            })
                            .catch(function(e){
                                if (tbody) {
                                    tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました: ' + e.message + '</td></tr>';
                                }
                            });

                    } catch (error) {
                        console.error('[fetchUserData] error:', error);
                    }
                };
            }

            scope.querySelectorAll('form').forEach(function(f){
                if (f.action && !/^https?:\/\//.test(f.action)) {
                    try {
                        var baseAbs = (window.location.origin + (window.__BASE_PATH || '') + '/');
                        var url = new URL(f.action, baseAbs);
                        f.action = url.toString();
                    } catch(e){}
                }
            });

            scope.querySelectorAll('a[href]').forEach(function(a){
                if (a.href && !/^https?:\/\//.test(a.href) && !/^javascript:/.test(a.href) && !/^#/.test(a.href)) {
                    try {
                        var baseAbs = (window.location.origin + (window.__BASE_PATH || '') + '/');
                        var url = new URL(a.getAttribute('href'), baseAbs);
                        a.href = url.toString();
                    } catch(e){}
                }
            });

            var personalBlocks = scope.querySelectorAll('#room-selection-table, #personal-section, .personal-section, [data-section="personal"], [data-mode="personal"], [data-target="personal"]');
            var groupBlocks    = scope.querySelectorAll('#room-select-group, #user-selection-table, #group-section, .group-section, [data-section="group"], [data-mode="group"], [data-target="group"]');

            function show(elList, on){
                elList.forEach(function(el){
                    el.style.display = on ? '' : 'none';
                });
            }

            var select = scope.querySelector('#c_reservation_type');
            if (select && !select.value && !scope.querySelector('#reserve-type-hint')) {
                var hint = document.createElement('small');
                hint.id = 'reserve-type-hint';
                hint.className = 'text-muted d-block mt-1';
                hint.textContent = '※ まず予約タイプを選択してください';
                select.parentNode.appendChild(hint);
            }

            var table = scope.querySelector('#reservationTable, .reservation-table, table[data-role="reservation"], table#targetTable, table.reservation');
            var $dt   = (window.jQuery && table && jQuery.fn && jQuery.fn.DataTable && jQuery(table).data('DataTable')) ? jQuery(table).DataTable() : null;

            function toggleTable(scopeValue){
                if ($dt) {
                    $dt.search(scopeValue).draw();
                } else if (table) {
                    var rows = table.querySelectorAll('tbody tr');
                    rows.forEach(function(r){
                        r.style.display = (scopeValue && r.textContent.indexOf(scopeValue) > -1) ? '' : 'none';
                    });
                }
            }

            function clearHiddenInputs(isGroup){
                var clearTargets = isGroup
                    ? scope.querySelectorAll('[name^="meals["], input[type="hidden"][name*="room"], input[type="hidden"][name*="user"]')
                    : scope.querySelectorAll('[name^="users["], input[type="hidden"][name*="i_id_room"]');
                clearTargets.forEach(function(inp){
                    if (inp.type === 'checkbox') inp.checked = false;
                    else inp.value = '';
                });
            }

            function applyMode(val){
                var v = String(val || '').toLowerCase();
                var isGroup = /group|collect| |^2$/.test(v);
                show(personalBlocks, !isGroup);
                show(groupBlocks,    isGroup);
                toggleTable(v);
                clearHiddenInputs(isGroup);

                var hint = scope.querySelector('#reserve-type-hint');
                if (hint) hint.style.display = val ? 'none' : '';
            }

            if (select) {
                applyMode(select.value);
                select.addEventListener('change', function(){ applyMode(select.value); });
            }

            setTimeout(function() {
                roomSelect = scope.querySelector('#room-select') ||
                    scope.querySelector('select[name*="room"]') ||
                    scope.querySelector('#room_select') ||
                    scope.querySelector('.room-select');

                if (roomSelect) {
                    function handleRoomChange() {
                        var roomId = roomSelect.value;
                        var tbody = document.getElementById('user-checkboxes');
                        if (tbody) tbody.innerHTML = '';
                        if (!roomId) {
                            var groupContainer = scope.querySelector('#user-selection-table');
                            if (groupContainer) groupContainer.style.display = 'none';
                            return;
                        }
                        var groupContainer = scope.querySelector('#user-selection-table');
                        if (groupContainer) groupContainer.style.display = '';
                        window.fetchUserData(roomId);
                    }
                    roomSelect.removeEventListener('change', roomSelect._handleRoomChange || (() => {}));
                    roomSelect._handleRoomChange = handleRoomChange;
                    roomSelect.addEventListener('change', handleRoomChange);
                    if (roomSelect.value) {
                        setTimeout(function() { handleRoomChange(); }, 100);
                    }
                }
            }, 200);

            if (typeof window.initReservationForm === 'function') {
                window.initReservationForm();
            }

            // ★ 昼食⇔弁当排他制御をモーダル描画直後に適用
            if (typeof window.applyLunchBentoExclusion === 'function') {
                window.applyLunchBentoExclusion(scope);
            }
        }
        function installModalSaveBridge(modal, modalEl){
            if (!modal) return;
            if (modal.dataset.saveBridgeInstalled) return;
            modal.dataset.saveBridgeInstalled = '1';

            modal.addEventListener('reservation:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || detail.d_reservation_date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });

            modal.addEventListener('ce:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });

            modal.addEventListener('change-edit:saved', function(e){
                var detail = e.detail || {};
                var date   = detail.date || '';
                if (window.calendar && date) {
                    window.calendar.refetchEvents();
                }
                if (modalEl && typeof window.bootstrap !== 'undefined') {
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) {
                        setTimeout(function(){ bsModal.hide(); }, 800);
                    }
                }
            });
        }

        function extractFormFragment(htmlText){
            var parser = new DOMParser();
            var doc = parser.parseFromString(htmlText, 'text/html');

            var ceRoot = doc.querySelector('#ce-root');
            if (ceRoot) return ceRoot;

            var changeForm = doc.querySelector('#change-edit-form, form#changeEditForm, form[name="change-edit"]');
            if (changeForm) {
                var card = changeForm.closest('.card');
                if (card) return card;
                return changeForm;
            }

            var addForm = doc.querySelector('form#reservation-form, form[name="reservation-add"], form[action*="/TReservationInfo/add"]');
            if (addForm) {
                var addCard = addForm.closest('.card');
                if (addCard) return addCard;
                return addForm;
            }

            var right = doc.querySelector('.col-md-9');
            if (right) return right;

            return doc.body || doc.documentElement;
        }

        function replaceWithExtract(container, html){
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;

            var extracted = tempDiv.querySelector('#ce-root') ||
                tempDiv.querySelector('.card') ||
                tempDiv.querySelector('form') ||
                tempDiv.firstElementChild || tempDiv;

            container.innerHTML = '';
            container.appendChild(extracted);
        }

        async function timeoutableFetch(url, opts){
            var controller = new AbortController();
            var timeoutId  = setTimeout(function(){ controller.abort(); }, 30000);

            try {
                var merged = Object.assign({}, opts, {signal: controller.signal});
                var res = await fetch(url, merged);
                clearTimeout(timeoutId);
                return res;
            } catch(e) {
                clearTimeout(timeoutId);
                throw e;
            }
        }

        async function loadInto(container, url, modalEl){
            if (!container) {
                if (modalEl) {
                    var wrap = modalEl.querySelector('#qd-remote-wrap');
                    if (wrap) {
                        wrap.innerHTML = '<div class="alert alert-danger">コンテナが見つかりません</div>';
                    }
                }
                return;
            }

            container.innerHTML = '<div class="text-center p-5"><div class="spinner-border" role="status"></div><p class="mt-2">読み込み中...</p></div>';

            try {
                var response = await timeoutableFetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html, */*;q=0.1'
                    }
                });

                if (!response.ok) {
                    // サーバーが JSON エラーを返している場合はそのメッセージを取り出す
                    var errMessage = 'HTTP ' + response.status;
                    try {
                        var ct = response.headers.get('content-type') || '';
                        if (ct.indexOf('application/json') !== -1) {
                            var errJson = await response.json();
                            if (errJson && errJson.message) {
                                errMessage = errJson.message;
                            }
                        }
                    } catch (_) {}
                    throw new Error(errMessage);
                }

                var htmlText = await response.text();
                if (htmlText.trim().length === 0) {
                    throw new Error('空のレスポンス');
                }

                try {
                    replaceWithExtract(container, htmlText);
                    if (window.__IS_STAFF && typeof enforceStaffCancelBlock === 'function'){
                        enforceStaffCancelBlock(container);
                    }
                    if (!window.__IS_STAFF) { observeChildUnlock(container); }
                } catch(extractErr) {
                    container.innerHTML = htmlText;
                    if (!window.__IS_STAFF) { observeChildUnlock(container); }
                }

                var host = container.closest('.modal') || container;

                // ★★★★★ ここからが修正箇所 ★★★★★
                // add.js の初期化関数を明示的に呼び出すことで、表示崩れを解消する
                if (window.ADD_RESERVATION && typeof window.ADD_RESERVATION.init === 'function') {
                    try {
                        console.log('[loadInto] Explicitly calling ADD_RESERVATION.init()');
                        window.ADD_RESERVATION.init(host);
                    } catch (e) {
                        console.error('Error during ADD_RESERVATION.init():', e);
                    }
                } else {
                    console.warn('[loadInto] ADD_RESERVATION.init not found. UI might be misconfigured.');
                }

                // 直前編集モーダル（ce-change-edit.js）の初期化を明示的に呼び出す
                // shown.bs.modal はHTML読み込み前に発火する場合があるため、ここで確実に初期化する
                if (window.CE_CHANGE_EDIT && typeof window.CE_CHANGE_EDIT.init === 'function') {
                    try {
                        window.CE_CHANGE_EDIT.init(host);
                    } catch (e) {
                        console.error('Error during CE_CHANGE_EDIT.init():', e);
                    }
                }
                // ★★★★★ 修正箇所ここまで ★★★★★

                // 直前編集フォーム（change_edit.php）初期化
                if (window.CE_CHANGE_EDIT && typeof window.CE_CHANGE_EDIT.init === 'function') {
                    try {
                        window.CE_CHANGE_EDIT.init(host);
                    } catch (e) {
                        console.error('Error during CE_CHANGE_EDIT.init():', e);
                    }
                }

                ensureAddModalCompat(host);

                // ★ 昼食⇔弁当排他制御をAjax描画直後にも適用
                if (typeof window.applyLunchBentoExclusion === 'function') {
                    window.applyLunchBentoExclusion(host);
                }

                installModalSaveBridge(host, modalEl || host);

            } catch(err) {
                // HTTP ステータス表示など技術的な文字列はユーザーに見せない
                var rawMsg = (err && err.message) ? String(err.message) : '';
                var isTechnical = !rawMsg || /^HTTP \d/.test(rawMsg) || rawMsg === '空のレスポンス';
                var displayMsg = isTechnical
                    ? 'ページを再読み込みするか、管理者にお問い合わせください。'
                    : rawMsg.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                container.innerHTML =
                    '<div class="alert alert-danger" role="alert">' +
                    '<h4 class="alert-heading">エラー</h4>' +
                    '<p>読み込みに失敗しました</p>' +
                    '<hr><p class="mb-0"><small>' + displayMsg + '</small></p>' +
                    '</div>';
            }
        }

        function isWithin14(dateStr){
            var target = new Date(String(dateStr) + 'T00:00:00');
            var server = new Date(String(SERVER_TODAY) + 'T00:00:00');
            var diffDays = Math.round((target.getTime() - server.getTime()) / 86400000);
            return (diffDays >= 0 && diffDays <= 14);
        }

        function isPastDate(dateStr){
            var target = new Date(String(dateStr) + 'T00:00:00');
            var server = new Date(String(SERVER_TODAY) + 'T00:00:00');
            return target.getTime() < server.getTime();
        }

        function renderPastDateNotice(container, dateStr){
            var msg = (window.__TRESP && window.__TRESP.pastDateUnavailableMessage)
                ? String(window.__TRESP.pastDateUnavailableMessage)
                : '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。';
            container.innerHTML =
                '<div class="past-date-panel" role="alert">' +
                '<div class="past-date-icon" aria-hidden="true">!</div>' +
                '<div class="past-date-content">' +
                '<h5 class="past-date-title">この日の予約は表示できません</h5>' +
                '<p class="past-date-date">対象日: <strong>' + String(dateStr) + '</strong></p>' +
                '<p class="past-date-text">' + msg + '</p>' +
                '</div>' +
                '</div>';
        }

        async function loadViewIntoModal(dateStr, useChangeEdit){
            return new Promise(function(resolve, reject){
                var modal = document.getElementById('quickDayModal');
                if (!modal) { reject(new Error('#quickDayModal not found')); return; }
                var container = document.getElementById('qd-remote-wrap');
                if (!container) { reject(new Error('#qd-remote-wrap not found')); return; }

                window.QUERY_DATE = dateStr;
                if (isPastDate(dateStr)) {
                    renderPastDateNotice(container, dateStr);
                    resolve();
                    return;
                }

                var url = useChangeEdit
                    ? CHANGEEDIT_URL + '?date=' + encodeURIComponent(dateStr) + '&modal=1'
                    : ADD_URL + '?date=' + encodeURIComponent(dateStr) + '&modal=1';

                loadInto(container, url, modal).then(resolve).catch(reject);
            });
        }

        window.quickOpenDayModal = function(dateStr){
            try{
                var useChange = isWithin14(dateStr);
                openModalById('quickDayModal');
                loadViewIntoModal(dateStr, useChange).catch(function(){});
            } catch(e){
                openModalById('quickDayModal');
            }
        };

        (function autoOpenQuickModalFromQuery(){
            try {
                var params = new URLSearchParams(window.location.search || '');
                var shouldOpen = params.get('open_quick_modal') === '1';
                var dateStr = params.get('date') || '';
                if (!shouldOpen || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return;
                window.QUERY_DATE = dateStr;
                setTimeout(function(){
                    if (typeof window.quickOpenDayModal === 'function') {
                        window.quickOpenDayModal(dateStr);
                    }
                    params.delete('open_quick_modal');
                    var cleaned = window.location.pathname + (params.toString() ? ('?' + params.toString()) : '') + (window.location.hash || '');
                    window.history.replaceState({}, '', cleaned);
                }, 0);
            } catch (e) {
                // ignore URL parsing failures
            }
        })();

        document.addEventListener('shown.bs.modal', function (ev) {
            var modal = ev.target;
            if (!modal || modal.id !== 'quickDayModal') return;

            var wrap = modal.querySelector('#qd-remote-wrap') || modal;
            var targetDate =
                (typeof window.QUERY_DATE === 'string' && window.QUERY_DATE) ||
                (modal.querySelector('#qd-picked-date')?.textContent?.trim()) ||
                '';

            var isLastMinute =
                !!targetDate && typeof window.isWithin14 === 'function'
                    ? window.isWithin14(targetDate)
                    : false;

            function cleanupAll() {
                wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function (cb) {
                    cb.disabled = false;
                    cb.removeAttribute('data-locked');
                    if (cb.title &&
                        (cb.title.includes('直前予約のため') || cb.title.includes('直前期間のため'))) {
                        cb.removeAttribute('title');
                    }
                    cb.classList?.remove('deletion-blocked');
                });
                wrap.querySelectorAll('.staff-last-minute-notice').forEach(function(n){ n.remove(); });
            }

            function applyStaffLock() {
                wrap.querySelectorAll('input[type="checkbox"][name^="users"]').forEach(function (cb) {
                    var isStaffTarget = (typeof window.isStaffTargetCheckbox === 'function')
                        ? window.isStaffTargetCheckbox(cb)
                        : !!window.__IS_STAFF;
                    if (!isStaffTarget) return;
                    if (cb.checked) {
                        cb.disabled = true;
                        cb.dataset.locked = '1';
                        cb.title = '直前期間のため、既存予約の削除はできません。';
                        cb.classList?.add('deletion-blocked');
                    }
                });

                if (!wrap.querySelector('.staff-last-minute-notice')) {
                    var notice = document.createElement('div');
                    notice.className = 'alert alert-info staff-last-minute-notice mb-3';
                    notice.innerHTML =
                        '<i class="bi bi-info-circle"></i> ' +
                        '<strong>直前期間（当日〜14日以内）</strong>のため、職員の既存予約は変更できません。' +
                        '子供は追加・キャンセルが可能です。';
                    var anchor = wrap.querySelector('.card, form, #ce-root') || wrap.firstElementChild;
                    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(notice, anchor);
                    else wrap.prepend(notice);
                }
            }

            cleanupAll();

            if (!window.__IS_STAFF) {
                return;
            }

            if (!isLastMinute) return;

            try {
                if (typeof window.enforceStaffCancelBlock === 'function') {
                    window.enforceStaffCancelBlock(wrap);
                } else if (typeof window.enforceLastMinuteNoUncheck === 'function') {
                    window.enforceLastMinuteNoUncheck(wrap);
                } else {
                    applyStaffLock();
                }
            } catch (e) {
                applyStaffLock();
            }
        });

        window.ensureAddModalCompat = ensureAddModalCompat;
        window.installModalSaveBridge = installModalSaveBridge;
        window.loadInto = loadInto;
    })();

(function(){
        if (!window.GET_USERS_BY_ROOM_TPL) {
            window.GET_USERS_BY_ROOM_TPL = window.__TRESP.getUsersByRoomTpl;
        }

        if (!window.QUERY_DATE) {
            window.QUERY_DATE = window.__TRESP.todayJs;
        }

        if (!window.buildGetUsersByRoomUrl) {
            window.buildGetUsersByRoomUrl = function(roomId){
                var url = String(window.GET_USERS_BY_ROOM_TPL || '');
                if (url.indexOf('__RID__') !== -1) url = url.replace('__RID__', encodeURIComponent(roomId));
                else url = url.replace(/\/$/, '') + '/' + encodeURIComponent(roomId);
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(window.QUERY_DATE || '');
                return url;
            };
        }

        if (!window.toggleAllUsers) {
            window.toggleAllUsers = function(mealTime, isChecked){
                var map = { morning:1, noon:2, night:3, bento:4 };
                var mealType = map[mealTime]; if (!mealType) return;

                var checkboxes = document.querySelectorAll('input[type="checkbox"][name^="users"][name$="['+mealType+']"]');
                var headerCheckbox = document.querySelector('input[type="checkbox"][onclick^="toggleAllUsers(\''+mealTime+'\',"]');

                checkboxes.forEach(function(cb){
                    cb.checked = isChecked;
                    var m = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                    if (m && (mealType === 2 || mealType === 4)) {
                        var userId = m[1];
                        var counterpart = (mealType === 2 ? 4 : 2);
                        var other = document.querySelector('input[name="users['+userId+']['+counterpart+']"]');
                        if (other && isChecked) {
                            other.checked = false;
                            other.dispatchEvent(new Event('change'));
                        }
                    }
                    cb.dispatchEvent(new Event('change'));
                });

                if (headerCheckbox) {
                    headerCheckbox.checked = Array.prototype.every.call(checkboxes, function(c){ return c.checked; });
                }
            };
        }

        if (!window.setupLunchBentoPair) {
            window.setupLunchBentoPair = function (lunchCb, bentoCb) {
                if (!lunchCb || !bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

                function updateHeader(mealType) {
                    var all = [];
                    var nodes = document.querySelectorAll('input[type="checkbox"][name^="users"]');
                    for (var i = 0; i < nodes.length; i++) {
                        var n = nodes[i];
                        var m = typeof n.name === 'string' ? n.name.match(/\[(\d+)\]$/) : null;
                        if (m && Number(m[1]) === mealType) all.push(n);
                    }

                    var mealKey = mealType === 2 ? 'noon' : 'bento';
                    var header =
                        document.querySelector('input[type="checkbox"][data-meal="' + mealKey + '"]');

                    if (!header) {
                        var cand = document.querySelectorAll('input[type="checkbox"][onclick]');
                        var needles = [
                            "toggleAllUsers('" + mealKey + "',",
                            'toggleAllUsers("' + mealKey + '",'
                        ];
                        for (var j = 0; j < cand.length && !header; j++) {
                            var v = cand[j].getAttribute('onclick') || '';
                            for (var k = 0; k < needles.length && !header; k++) {
                                if (v.indexOf(needles[k]) === 0) header = cand[j];
                            }
                        }
                    }

                    if (header) {
                        var allChecked = true;
                        for (var x = 0; x < all.length; x++) {
                            if (!all[x].checked) { allChecked = false; break; }
                        }
                        header.checked = allChecked;
                    }
                }

                function onLunchChange() {
                    if (lunchCb.checked && bentoCb.checked) {
                        bentoCb.checked = false;
                        bentoCb.dispatchEvent(new Event('change'));
                    }
                    updateHeader(2);
                    updateHeader(4);
                }

                function onBentoChange() {
                    if (bentoCb.checked && lunchCb.checked) {
                        lunchCb.checked = false;
                        lunchCb.dispatchEvent(new Event('change'));
                    }
                    updateHeader(2);
                    updateHeader(4);
                }

                lunchCb.addEventListener('change', onLunchChange);
                bentoCb.addEventListener('change', onBentoChange);

                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';

                updateHeader(2);
                updateHeader(4);
            };
        }

        if (!window.fetchUserData) {
            window.fetchUserData = function(roomId) {
                var url = window.buildGetUsersByRoomUrl(roomId);
                var tbody = document.getElementById('user-checkboxes');
                if (!tbody) { return; }

                tbody.innerHTML = '<tr><td colspan="5" class="text-center">読み込み中...</td></tr>';

                return fetch(url, { credentials: 'same-origin' })
                    .then(function(r){
                        if (!r.ok) throw new Error('HTTP '+r.status);
                        return r.json();
                    })
                    .then(function(d){
                        var users = d && d.usersByRoom;
                        if (!Array.isArray(users)) {
                            throw new Error('usersByRoom が配列ではありません');
                        }

                        tbody.innerHTML = '';

                        if (users.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">この部屋に利用者がいません。</td></tr>';
                            return;
                        }

                        users.forEach(function(u){
                            var tr = document.createElement('tr');
                            tr.innerHTML =
                                '<td>' + (u.name || 'Unknown') + '</td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][1]" value="1" ' + (Number(u.morning)===1?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][2]" value="1" ' + (Number(u.noon)===1   ?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][3]" value="1" ' + (Number(u.night)===1  ?'checked':'') + '></td>' +
                                '<td class="text-center"><input type="checkbox" name="users['+u.id+'][4]" value="1" ' + (Number(u.bento)===1  ?'checked':'') + '></td>';
                            tbody.appendChild(tr);

                            var lunchCb = tr.querySelector('input[name="users['+u.id+'][2]"]');
                            var bentoCb = tr.querySelector('input[name="users['+u.id+'][4]"]');
                            if (window.setupLunchBentoPair && lunchCb && bentoCb) {
                                window.setupLunchBentoPair(lunchCb, bentoCb);
                            }
                        });

                        var tableContainer = tbody.closest('.table-responsive, #user-selection-table');
                        if (tableContainer) {
                            tableContainer.style.maxHeight = '400px';
                            tableContainer.style.overflowY = 'auto';
                        }
                    })
                    .catch(function(e){
                        tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center">利用者一覧の取得に失敗しました: ' + e.message + '</td></tr>';
                    });
            };
        }

        var _origEnsure = window.ensureAddModalCompat;
        window.ensureAddModalCompat = function(host){
            if (typeof _origEnsure === 'function') _origEnsure(host);
            var scope = host || document;

            setTimeout(function() {
                var select = scope.querySelector('#room-select');
                var groupContainer = scope.querySelector('#user-selection-table');

                function handleChange(){
                    var roomId = select && select.value;
                    var tbody = scope.querySelector('#user-checkboxes');
                    if (tbody) tbody.innerHTML = '';
                    if (!roomId) {
                        if (groupContainer) groupContainer.style.display = 'none';
                        return;
                    }
                    if (groupContainer) groupContainer.style.display = '';
                    window.fetchUserData(roomId);
                }

                if (select) {
                    select.removeEventListener('change', handleChange);
                    select.addEventListener('change', handleChange);
                    if (select.value) {
                        handleChange();
                    }
                }
            }, 100);
        };
    })();

document.addEventListener('shown.bs.modal', function(ev) {
        var modal = ev.target;
        if (!modal) return;
        if (modal.id === 'quickDayModal') {
            var wrap = modal.querySelector('#qd-remote-wrap');
            if (wrap) {
                setTimeout(function() {
                    var dateEl = wrap.querySelector('#qd-picked-date');
                    var targetDate = dateEl ? (dateEl.textContent || '').trim() : '';

                    var isStaffAndLastMinute =
                        !!(window.__IS_STAFF && targetDate && typeof isWithin14 === 'function' && isWithin14(targetDate));

                    if (isStaffAndLastMinute && typeof enforceLastMinuteNoUncheck === 'function') {
                        enforceLastMinuteNoUncheck(wrap);
                    }

                    if (isStaffAndLastMinute) {
                        var existingNotice = wrap.querySelector('.staff-last-minute-notice');
                        if (!existingNotice) {
                            var notice = document.createElement('div');
                            notice.className = 'alert alert-info staff-last-minute-notice mb-3';
                            notice.innerHTML =
                                '<i class="bi bi-info-circle"></i> ' +
                                '<strong>直前期間のため、既存予約の削除はできません。</strong>新しい予約の追加のみ可能です。';
                            var firstCard = wrap.querySelector('.card');
                            if (firstCard && firstCard.parentNode) {
                                firstCard.parentNode.insertBefore(notice, firstCard);
                            } else {
                                wrap.prepend(notice);
                            }
                        }
                    }
                }, 100);
            }
        }
    });

(function(){
        const copyApi   = window.__TRESP.copyApi;
        const copyPreviewApi = window.__TRESP.copyPreviewApi;
        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '';

        const ymdLocal = (d)=> {
            const y=d.getFullYear(), m=('0'+(d.getMonth()+1)).slice(-2), day=('0'+d.getDate()).slice(-2);
            return `${y}-${m}-${day}`;
        };
        const startOfWeek = (d)=> {
            const c = new Date(d.getFullYear(), d.getMonth(), d.getDate());
            const w = c.getDay(); const diffToMon = (w === 0 ? -6 : 1 - w);
            c.setDate(c.getDate() + diffToMon); return c;
        };
        const startOfMonth = (d)=> new Date(d.getFullYear(), d.getMonth(), 1);

        const refreshCalendarOrReload = ()=> {
            try {
                if (window.__reservationCalendar?.refetchEvents) {
                    window.__reservationCalendar.refetchEvents();
                    return;
                }
            } catch(_) {}
            location.reload();
        };

        const openModalBtn      = document.querySelector('#res-copy-modal');
        const submitBtn         = document.querySelector('#res-copy-submit');
        const lastWeekQuickBtn  = document.querySelector('#res-copy-btn-lastweek');
        const form              = document.querySelector('#res-copy-form');

        if (!form) return;

        async function doCopy(payload){
            const res = await fetch(copyApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(()=> ({}));
            if (!res.ok || data?.ok === false) {
                const msg = data?.message || `コピーに失敗しました（${res.status}）`;
                throw new Error(msg);
            }
        }

        submitBtn?.addEventListener('click', async ()=>{
            try {
                const fd = new FormData(form);
                const mode         = fd.get('mode');
                const sourceStart  = fd.get('source_start');
                const targetStart  = fd.get('target_start');
                const roomId       = fd.get('room_id') || '';
                const overwrite    = fd.get('overwrite') ? 1 : 0;

                if (!sourceStart || !targetStart) { notifyUser('コピー元/先の開始日を入力してください。', 'warning'); return; }
                if (mode !== 'week' && mode !== 'month') { notifyUser('コピー範囲（週／月）を選択してください。', 'warning'); return; }

                await doCopy({
                    mode,
                    source_start: sourceStart,
                    target_start: targetStart,
                    room_id: roomId || null,
                    overwrite
                });

                notifyUser('コピーが完了しました。', 'success');
                try {
                    const modalEl = document.getElementById('res-copy-modal');
                    if (modalEl && window.bootstrap?.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                } catch(_) {}
                refreshCalendarOrReload();
            } catch (e) {
                notifyUser(e?.message || 'コピーに失敗しました。', 'danger');
            }
        });

        lastWeekQuickBtn?.addEventListener('click', async ()=>{
            try {
                const base = new Date();
                const thisMon = startOfWeek(base);
                const lastMon = new Date(thisMon); lastMon.setDate(thisMon.getDate() - 7);

                await doCopy({
                    mode: 'week',
                    source_start: ymdLocal(lastMon),
                    target_start: ymdLocal(thisMon),
                    room_id: null,
                    overwrite: 0
                });

                notifyUser('先週 → 今週 へのコピーが完了しました。', 'success');
                refreshCalendarOrReload();
            } catch (e) {
                notifyUser(e?.message || 'コピーに失敗しました。', 'danger');
            }
        });
    })();

if (typeof enforceLastMinuteNoUncheck !== 'function') {
        function enforceLastMinuteNoUncheck(scope){
            if (typeof enforceStaffCancelBlock === 'function') {
                enforceStaffCancelBlock(scope);
            }
        }
    }

document.addEventListener('shown.bs.modal', function(ev) {
        var modal = ev.target;
        if (modal.id !== 'quickDayModal') return;
        var isLastMinute = isWithin14(window.QUERY_DATE);
        if (!isLastMinute) return;

        var checkboxes = modal.querySelectorAll('input[type="checkbox"][name^="users"]');
        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                cb.disabled = true;
                cb.title = '直前予約のため、変更できません（追加のみ可能）';
            }
        });
    });

(function(){
        const modalEl          = document.getElementById('res-copy-modal');
        const submitBtn        = document.getElementById('res-copy-submit');
        const lastWeekQuickBtn = document.getElementById('res-copy-btn-lastweek');
        const form             = document.querySelector('#res-copy-form');
        if (!modalEl || !form || !submitBtn) return;

        const copyApi   = window.__TRESP.copyApi;
        const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '';

        const sourceInput = document.getElementById('source_start');
        const targetInput = document.getElementById('target_start_input');
        const addTargetBtn = document.getElementById('add-target-btn');
        const targetDatesList = document.getElementById('target-dates-list');
        const targetDatesEmpty = document.getElementById('target-dates-empty');
        const targetDatesHidden = document.getElementById('target-dates-hidden');
        const modeWeek = document.getElementById('res-copy-mode-week');
        const modeMonth = document.getElementById('res-copy-mode-month');
        const sourceValidation = document.getElementById('source-validation');
        const refreshSourceBtn = document.getElementById('refresh-source');
        const refreshTargetBtn = document.getElementById('refresh-target');

        // 選択されたコピー先日付を管理する配列
        let targetDates = [];

        const isMonday = (d)=> d.getDay() === 1;
        const isFirst  = (d)=> d.getDate() === 1;
        const ymd      = (d)=> d.toISOString().slice(0,10);

        function parseDate(val){
            if(!val) return null;
            const d = new Date(val + 'T00:00:00');
            return isNaN(d) ? null : d;
        }
        
        function toast(msg,type='success'){
            let wrap = document.getElementById('toastWrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'toastWrap';
                wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(wrap);
            }
            const el = document.createElement('div');
            el.className = 'toast align-items-center text-bg-' + (type==='success'?'success':type==='warning'?'warning':'danger') + ' border-0';
            el.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>`;
            wrap.appendChild(el);
            const t = window.bootstrap?.Toast.getOrCreateInstance(el, { delay: 3000 });
            t?.show();
            el.addEventListener('hidden.bs.toast', ()=> el.remove());
        }

        // コピー先日付を追加
        function addTargetDate() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const dateStr = targetInput.value;
            if (!dateStr) {
                toast('日付を選択してください', 'warning');
                return;
            }
            
            const date = parseDate(dateStr);
            if (!date) {
                toast('有効な日付を選択してください', 'warning');
                return;
            }
            
            // バリデーション
            if (mode === 'week' && !isMonday(date)) {
                toast('週単位の場合は月曜日を選択してください', 'warning');
                return;
            }
            if (mode === 'month' && !isFirst(date)) {
                toast('月単位の場合は1日を選択してください', 'warning');
                return;
            }
            
            // コピー元との重複チェック
            const source = parseDate(sourceInput.value);
            if (source && source.getTime() === date.getTime()) {
                toast('コピー元と同じ日付は選択できません', 'warning');
                return;
            }
            
            // 既に追加されているかチェック
            if (targetDates.some(d => d === dateStr)) {
                toast('既に追加されている日付です', 'warning');
                return;
            }
            
            targetDates.push(dateStr);
            renderTargetDates();
            targetInput.value = '';
            validateInputs();
        }

        // コピー先日付を削除
        function removeTargetDate(dateStr) {
            targetDates = targetDates.filter(d => d !== dateStr);
            renderTargetDates();
            validateInputs();
        }

        // コピー先日付のリストを描画
        function renderTargetDates() {
            if (targetDates.length === 0) {
                targetDatesEmpty.style.display = 'block';
                targetDatesList.querySelectorAll('.target-date-item').forEach(el => el.remove());
                targetDatesHidden.innerHTML = '';
                return;
            }
            
            targetDatesEmpty.style.display = 'none';
            
            // 既存のアイテムを削除
            targetDatesList.querySelectorAll('.target-date-item').forEach(el => el.remove());
            targetDatesHidden.innerHTML = '';
            
            // 日付順にソート
            const sorted = [...targetDates].sort();
            
            sorted.forEach(dateStr => {
                // 表示用のバッジ
                const badge = document.createElement('span');
                badge.className = 'badge bg-primary me-2 mb-2 target-date-item';
                badge.style.fontSize = '0.9rem';
                badge.innerHTML = `
                    <i class="bi bi-calendar-check"></i> ${dateStr}
                    <button type="button" class="btn-close btn-close-white ms-2" 
                            style="font-size: 0.7rem;" 
                            onclick="window.removeTargetDate('${dateStr}')"></button>
                `;
                targetDatesList.appendChild(badge);
                
                // hidden input
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'target_dates[]';
                hidden.value = dateStr;
                targetDatesHidden.appendChild(hidden);
            });
            
            // プレビューを更新
            validateInputs();
        }

        // グローバルに公開
        window.removeTargetDate = removeTargetDate;

        // 追加ボタンのイベント
        if (addTargetBtn) {
            addTargetBtn.addEventListener('click', addTargetDate);
        }
        
        // Enterキーで追加
        if (targetInput) {
            targetInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addTargetDate();
                }
            });
        }

        // リアルタイムバリデーション
        async function validateInputs() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const source = parseDate(sourceInput.value);
            
            let isValid = true;
            
            // コピー元のバリデーション
            if (source) {
                if (mode === 'week' && !isMonday(source)) {
                    sourceValidation.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 週単位の場合は月曜日を選択してください</span>';
                    isValid = false;
                } else if (mode === 'month' && !isFirst(source)) {
                    sourceValidation.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 月単位の場合は1日を選択してください</span>';
                    isValid = false;
                } else {
                    sourceValidation.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> OK</span>';
                    
                    // コピー元が有効な場合、コピー先を自動入力
                    autoFillTargetDate(mode, source);
                }
            } else {
                sourceValidation.innerHTML = '';
            }
            
            // ボタンの有効/無効
            submitBtn.disabled = !(isValid && source && targetDates.length > 0);
            
            // プレビュー表示
            const preview = document.getElementById('copy-preview');
            const previewContent = document.getElementById('preview-content');
            
            if (isValid && source && targetDates.length > 0 && preview && previewContent) {
                // プレビュー件数を取得
                await fetchPreviewCounts(mode, source);
            } else if (preview) {
                preview.style.display = 'none';
            }
        }

        // コピー先の日付を自動入力
        function autoFillTargetDate(mode, source) {
            // コピー先入力欄が空の場合のみ自動入力
            if (targetInput.value) return;
            
            let suggestedDate;
            if (mode === 'week') {
                // 週単位の場合：翌週の月曜日
                suggestedDate = new Date(source);
                suggestedDate.setDate(suggestedDate.getDate() + 7);
            } else {
                // 月単位の場合：翌月の1日
                suggestedDate = new Date(source);
                suggestedDate.setMonth(suggestedDate.getMonth() + 1);
                suggestedDate.setDate(1);
            }
            
            // 入力欄に設定
            targetInput.value = ymd(suggestedDate);
            
            // アニメーション効果を追加
            targetInput.classList.add('border-success');
            setTimeout(() => {
                targetInput.classList.remove('border-success');
            }, 1000);
        }

        // プレビュー件数を取得して表示
        async function fetchPreviewCounts(mode, source) {
            const preview = document.getElementById('copy-preview');
            const previewContent = document.getElementById('preview-content');
            
            if (!preview || !previewContent || targetDates.length === 0) return;
            
            try {
                const sourceStr = ymd(source);
                const onlyChildren = document.getElementById('copy-only-children')?.checked || false;
                const roomIdInput = document.querySelector('input[name="room_id"]');
                const roomId = roomIdInput?.value || null;
                
                let totalCount = 0;
                const results = [];
                
                // 各コピー先の件数を取得
                for (const targetStr of targetDates) {
                    const params = new URLSearchParams({
                        mode: mode,
                        source: sourceStr,
                        target: targetStr,
                        only_children: onlyChildren ? '1' : '0'
                    });
                    
                    if (roomId) {
                        params.append('room_id', roomId);
                    }
                    
                    const res = await fetch(`${copyPreviewApi}?${params.toString()}`, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-Token': csrfToken
                        }
                    });
                    
                    if (res.ok) {
                        const raw = await res.json();
                        const data = window.normalizeApiPayload ? window.normalizeApiPayload(raw) : raw;
                        if (data && data.ok === true && data.preview) {
                            const count = data.preview.will_copy || 0;
                            totalCount += count;
                            results.push({ target: targetStr, count: count });
                        }
                    }
                }
                
                // プレビュー表示を更新
                if (totalCount > 0) {
                    let html = `<div class="mb-2"><strong>コピー予定件数：${totalCount}件</strong></div>`;
                    if (results.length > 1) {
                        html += '<div class="small text-muted">内訳：</div><ul class="small mb-0">';
                        results.forEach(r => {
                            html += `<li>${r.target}: ${r.count}件</li>`;
                        });
                        html += '</ul>';
                    }
                    previewContent.innerHTML = html;
                    preview.style.display = 'block';
                } else {
                    previewContent.innerHTML = '<div class="text-muted">コピー対象のデータがありません</div>';
                    preview.style.display = 'block';
                }
            } catch (err) {
                console.error('Preview fetch error:', err);
                preview.style.display = 'none';
            }
        }

        // イベントリスナー
        [modeWeek, modeMonth].forEach(radio => radio.addEventListener('change', () => {
            // モード変更時にコピー先をクリアして再計算
            const source = parseDate(sourceInput.value);
            if (source && !targetInput.value) {
                const mode = modeWeek.checked ? 'week' : 'month';
                autoFillTargetDate(mode, source);
            }
            validateInputs();
        }));
        sourceInput.addEventListener('change', validateInputs);
        sourceInput.addEventListener('input', validateInputs);
        
        // 子供のみチェックボックスの変更
        const onlyChildrenCheckbox = document.getElementById('copy-only-children');
        if (onlyChildrenCheckbox) onlyChildrenCheckbox.addEventListener('change', validateInputs);

        async function postCopy(payload){
            const res = await fetch(copyApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':'application/json; charset=utf-8',
                    'Accept':'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const ct = res.headers.get('content-type') || '';
            const isJson = ct.includes('application/json');
            const raw = isJson ? await res.json() : { message: await res.text() };
            const data = window.normalizeApiPayload ? window.normalizeApiPayload(raw) : raw;

            if (!res.ok || data?.ok === false) {
                const msg = data?.message || `コピーに失敗しました（${res.status}）`;
                throw new Error(msg);
            }
            return data;
        }

        submitBtn.addEventListener('click', async ()=>{
            try{
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>処理中...';

                const fd = new FormData(form);
                const mode         = fd.get('mode') || 'week';
                const sourceStart  = parseDate(fd.get('source_start'));
                const onlyChildren = !!fd.get('only_children');

                if(!sourceStart || targetDates.length === 0){
                    toast('コピー元とコピー先を選択してください。','warning');
                    return;
                }

                // 複数のコピー先に対して順番に実行
                let totalCopied = 0;
                let totalSkipped = 0;
                let totalSource = 0;
                let failedCount = 0;

                for (const targetDateStr of targetDates) {
                    const payload = {
                        mode,
                        source_start: ymd(sourceStart),
                        target_start: targetDateStr,
                        room_id: null, // すべての部屋
                        overwrite: 0,
                        only_children: onlyChildren ? 1 : 0
                    };

                    try {
                        const res = await postCopy(payload);
                        
                        const total = res?.total ?? 0;
                        const copied = res?.copied ?? 0;
                        const skipped = res?.skipped ?? 0;
                        
                        totalCopied += copied;
                        totalSkipped += skipped;
                        if (totalSource === 0) totalSource = total; // 最初の1回だけ
                    } catch (e) {
                        console.error('Copy failed for', targetDateStr, e);
                        failedCount++;
                    }
                }

                // 結果メッセージ
                let message = `コピーが完了しました。\n`;
                message += `コピー先: ${targetDates.length}件\n`;
                message += `コピー元データ: ${totalSource}件\n`;
                message += `新規登録: ${totalCopied}件`;
                if (totalSkipped > 0) {
                    message += `\nスキップ（既存）: ${totalSkipped}件`;
                }
                if (failedCount > 0) {
                    message += `\n失敗: ${failedCount}件`;
                }
                
                toast(message, failedCount > 0 ? 'warning' : 'success');

                if (window.__reservationCalendar?.refetchEvents) {
                    window.__reservationCalendar.refetchEvents();
                }

                const bs = window.bootstrap?.Modal.getOrCreateInstance(modalEl);
                bs?.hide();
            } catch(e){
                console.error(e);
                toast(e.message || 'コピーに失敗しました。','danger');
            } finally {
                submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> コピーを実行';
                submitBtn.disabled = false;
            }
        });

        if (lastWeekQuickBtn) {
            lastWeekQuickBtn.addEventListener('click', async ()=>{
                try{
                    lastWeekQuickBtn.disabled = true;

                    const base = (window.__reservationCalendar?.getDate && new Date(window.__reservationCalendar.getDate())) || new Date();
                    const day = base.getDay(); const monday = new Date(base);
                    monday.setDate(base.getDate() - ((day + 6) % 7));
                    const lastMonday = new Date(monday); lastMonday.setDate(monday.getDate() - 7);

                    const payload = {
                        mode: 'week',
                        source_start: ymd(lastMonday),
                        target_start: ymd(monday),
                        room_id: document.getElementById('res-copy-room')?.value || null,
                        overwrite: 0
                    };

                    const res = await postCopy(payload);
                    
                    const total = res?.total ?? 0;
                    const copied = res?.copied ?? 0;
                    const skipped = res?.skipped ?? 0;
                    
                    let message = '先週 → 今週 へコピーしました。\n';
                    message += `コピー元: ${total}件、新規登録: ${copied}件`;
                    if (skipped > 0) {
                        message += `、スキップ: ${skipped}件`;
                    }
                    
                    toast(message, 'success');
                    window.__reservationCalendar?.refetchEvents?.();
                } catch(e){
                    console.error(e);
                    toast(e.message || 'コピーに失敗しました。','danger');
                } finally {
                    lastWeekQuickBtn.disabled = false;
                }
            });
        }

        // 日付の自動補完機能
        function autoFillDates() {
            const mode = modeWeek.checked ? 'week' : 'month';
            
            // カレンダーの現在表示日付を取得（なければ今日）
            let baseDate = new Date();
            if (window.__reservationCalendar && window.__reservationCalendar.getDate) {
                try {
                    baseDate = new Date(window.__reservationCalendar.getDate());
                } catch(e) {
                    baseDate = new Date();
                }
            }
            
            if (mode === 'week') {
                // 週単位の場合
                const dayOfWeek = baseDate.getDay();
                const currentMonday = new Date(baseDate);
                currentMonday.setDate(baseDate.getDate() - ((dayOfWeek + 6) % 7));
                
                // コピー元: 先週の月曜日
                const lastMonday = new Date(currentMonday);
                lastMonday.setDate(currentMonday.getDate() - 7);
                
                // コピー先: 今週の月曜日
                sourceInput.value = ymd(lastMonday);
                targetInput.value = ymd(currentMonday);
                
            } else {
                // 月単位の場合
                const year = baseDate.getFullYear();
                const month = baseDate.getMonth();
                
                // コピー元: 先月の1日
                const lastMonthFirst = new Date(year, month - 1, 1);
                
                // コピー先: 今月の1日
                const thisMonthFirst = new Date(year, month, 1);
                
                sourceInput.value = ymd(lastMonthFirst);
                targetInput.value = ymd(thisMonthFirst);
            }
            
            // バリデーション実行
            validateInputs();
            
            // ヒントを更新
            updateHint();
        }
        
        // モード変更時にヒントを更新
        function updateHint() {
            const mode = modeWeek.checked ? 'week' : 'month';
            const hint = document.getElementById('mode-hint');
            if (hint) {
                if (mode === 'week') {
                    hint.innerHTML = '<i class="bi bi-info-circle text-primary"></i> 週単位の場合は月曜日を開始日に指定してください（自動入力済み）';
                } else {
                    hint.innerHTML = '<i class="bi bi-info-circle text-primary"></i> 月単位の場合は1日を開始日に指定してください（自動入力済み）';
                }
            }
        }
        
        // モード変更時に自動補完
        [modeWeek, modeMonth].forEach(radio => {
            radio.addEventListener('change', function() {
                autoFillDates();
            });
        });
        
        // 再計算ボタン
        if (refreshSourceBtn) {
            refreshSourceBtn.addEventListener('click', function() {
                autoFillDates();
                toast('日付を再計算しました', 'info');
            });
        }
        if (refreshTargetBtn) {
            refreshTargetBtn.addEventListener('click', function() {
                autoFillDates();
                toast('日付を再計算しました', 'info');
            });
        }

        // モーダルが開いたときに初期化と自動補完
        modalEl.addEventListener('shown.bs.modal', function() {
            autoFillDates();
            
            // アニメーション効果で自動入力を強調
            setTimeout(function() {
                sourceInput.classList.add('border-success');
                targetInput.classList.add('border-success');
                setTimeout(function() {
                    sourceInput.classList.remove('border-success');
                    targetInput.classList.remove('border-success');
                }, 1500);
            }, 100);
        });
        
        // モーダルが閉じたときにフォームをリセット
        modalEl.addEventListener('hidden.bs.modal', function() {
            form.reset();
            sourceValidation.innerHTML = '';
            targetValidation.innerHTML = '';
            document.getElementById('copy-preview').style.display = 'none';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> コピーを実行';
        });
    })();

    function isStaffTargetCheckbox(cb){
        if (!cb) return false;
        var tr = cb.closest('tr');
        if (tr && tr.getAttribute('data-is-staff') === '1') return true;
        var userLevel = cb.dataset.userLevel || cb.getAttribute('data-user-level');
        if (!userLevel && tr) userLevel = tr.dataset.userLevel || tr.getAttribute('data-user-level');
        if (userLevel != null && userLevel !== '') return String(userLevel) === '0';
        // 判別不能な場合はログインユーザーに合わせる
        return !!window.__IS_STAFF;
    }

    function enforceLastMinuteNoUncheck(scope){
        if (!window.__IS_STAFF) return;

        var root = scope || document;
        var dateInput = root.querySelector('input[name="date"]');
        if (!dateInput) return;

        var targetDate = dateInput.value;
        if (!targetDate) return;

        var isLastMinute = window.isWithin14 && window.isWithin14(targetDate);

        if (!isLastMinute) return;

        var checkboxes = root.querySelectorAll('input[type="checkbox"][name^="reservation"], input[type="checkbox"][name*="users"], .meal-checkbox');

        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                var isStaffUser = isStaffTargetCheckbox(cb);
                if (!isStaffUser) return;

                cb.addEventListener('click', function(e) {
                    if (!cb.checked) {
                        e.preventDefault();
                        e.stopPropagation();
                        cb.checked = true;

                        var message = '職員の直前期間での予約キャンセルは禁止されています。';

                        showInlineHint(cb, message);

                        var existingWarning = root.querySelector('.last-minute-warning');
                        if (!existingWarning) {
                            var warning = document.createElement('div');
                            warning.className = 'alert alert-warning last-minute-warning mt-2';
                            warning.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + message;
                            cb.closest('.form-check, tr, .meal-checkbox-container')?.appendChild(warning);
                            setTimeout(function() {
                                warning.remove();
                            }, 3000);
                        }
                    }
                });

                var container = cb.closest('.form-check, tr, .meal-checkbox-container');
                if (container) {
                    container.classList.add('position-relative');
                    if (!container.querySelector('.deletion-blocked')) {
                        var label = document.createElement('small');
                        label.className = 'text-muted deletion-blocked';
                        label.style.cssText = 'font-size: 0.75rem; display: block; margin-top: 0.25rem;';
                        label.textContent = '（職員：削除不可）';
                        container.appendChild(label);
                    }
                }
            }
        });
    }

    function showInlineHint(el, text){
        var root = el.closest('form') || document;
        var holder = el.closest('label') || el.closest('tr') || el.parentElement || root;
        var msg = holder.querySelector('.no-uncheck-hint');
        if (!msg) {
            msg = document.createElement('small');
            msg.className = 'text-warning no-uncheck-hint d-block';
            msg.style.cssText = 'margin-top: 0.25rem; font-weight: 500;';
            holder.appendChild(msg);
        }
        msg.textContent = text;
        clearTimeout(msg._timer);
        msg._timer = setTimeout(function(){
            if (msg.parentNode) {
                msg.remove();
            }
        }, 3000);
    }

function isWithin14(dateStr){
        var t = new Date(String(dateStr) + 'T00:00:00');
        var s = new Date(String(window.SERVER_TODAY || window.TODAY) + 'T00:00:00');
        return Math.round((t - s) / 86400000) >= 0 && Math.round((t - s) / 86400000) <= 14;
    }

    function isStaffCancelProhibited(dateStr, turningOn){
        return !!window.__IS_STAFF && isWithin14(dateStr) && (turningOn === false);
    }

    function enforceStaffCancelBlock(scope){
        try{
            if (!window.__IS_STAFF) return;
            var dateStr = String(window.QUERY_DATE || '');
            if (!dateStr || !isWithin14(dateStr)) return;
            var root = scope || document;

            if (!root.querySelector('.staff-cancel-block-notice')) {
                var notice = document.createElement('div');
                notice.className = 'alert alert-warning staff-cancel-block-notice mb-3';
                notice.innerHTML = '<i class="bi bi-exclamation-triangle"></i> <strong>職員による直前期間（当日〜14日先）の予約削除は禁止されています。</strong>新規追加のみ可能です。';
                var firstCard = root.querySelector('.card, form');
                if (firstCard && firstCard.parentNode) {
                    firstCard.parentNode.insertBefore(notice, firstCard);
                }
            }

            var selector = [
                '#reservation-form input[type="checkbox"]',
                '#change-edit-form input[type="checkbox"]',
                '#user-selection-table input[type="checkbox"]',
                '#reservationTable input[type="checkbox"]',
                'form input[type="checkbox"][name^="users["]'
            ].join(',');

            root.querySelectorAll(selector).forEach(function(cb){
                if (cb.dataset._staffGuardApplied === '1') {
                    return;
                }
                if (!isStaffTargetCheckbox(cb)) {
                    return;
                }
                var initialState = cb.checked;
                cb.dataset._initialChecked = initialState ? '1' : '0';
                cb.dataset._staffGuardApplied = '1';

                if (initialState) {
                    cb.disabled = true;
                    cb.title = '直前期間のため削除できません';

                    var container = cb.closest('tr, .form-check, .meal-checkbox-container, label');
                    if (container && !container.querySelector('.deletion-blocked-label')) {
                        var label = document.createElement('small');
                        label.className = 'text-muted deletion-blocked-label ms-2';
                        label.style.cssText = 'font-size: 0.75rem; font-style: italic;';
                        label.textContent = '（削除不可）';
                        container.appendChild(label);
                    }
                    return;
                }

                cb.addEventListener('mousedown', function(e){
                    if (cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false)) {
                        if (cb.checked) { e.preventDefault(); e.stopPropagation(); }
                    }
                });
                cb.addEventListener('keydown', function(e){
                    if ((e.key === ' ' || e.key === 'Enter')
                        && cb.dataset._initialChecked === '1'
                        && isStaffCancelProhibited(dateStr, false)) {
                        if (cb.checked) { e.preventDefault(); e.stopPropagation(); }
                    }
                });

                cb.addEventListener('change', function(ev){
                    var turningOn = cb.checked === true;
                    if (!turningOn && cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false)) {
                        cb.checked = true;
                        if (!cb.dataset._alerted) {
                            cb.dataset._alerted = '1';
                            notifyUser('直前（当日〜14日先）は、職員による予約の取り消しはできません。', 'warning');
                        }
                        ev.preventDefault(); ev.stopPropagation(); return false;
                    }
                });
            });

            [['#select-all-1',1],['#select-all-2',2],['#select-all-3',3],['#select-all-4',4]].forEach(function(pair){
                var h = root.querySelector(pair[0]); if (!h) return;
                var clone = h.cloneNode(true); h.parentNode.replaceChild(clone, h);
                clone.addEventListener('change', function(e){
                    var toOn = !!e.target.checked;
                    root.querySelectorAll('input.meal-checkbox[data-reservation-type="'+pair[1]+'"]').forEach(function(cb){
                        if (cb.disabled) return;
                        if (!toOn && cb.dataset._initialChecked === '1' && isStaffCancelProhibited(dateStr, false) && isStaffTargetCheckbox(cb)) return;
                        cb.checked = toOn;
                    });
                    if (toOn && pair[1] === 2) root.querySelector('#select-all-4') && (root.querySelector('#select-all-4').checked = false);
                    if (toOn && pair[1] === 4) root.querySelector('#select-all-2') && (root.querySelector('#select-all-2').checked = false);
                });
            });

        }catch(e){
            console.error('[enforceStaffCancelBlock] エラー:', e);
        }
    }
    window.enforceStaffCancelBlock = enforceStaffCancelBlock;

// javascript
    (function(){
        if (!window.fetch) return;
        const originalFetch = window.fetch.bind(window);

        window.fetch = async function(input, init){
            const res = await originalFetch(input, init);
            if (res && res.status === 409) {
                // 可能ならレスポンスJSONからメッセージを抽出
                let msg = '競合が発生しました。';
                let conflictDate = '';
                try {
                    const j = await res.clone().json().catch(()=>null);
                    if (j && (j.message || j.errors)) {
                        msg = j.message || (typeof j.errors === 'string' ? j.errors : JSON.stringify(j.errors));
                    }
                    if (j && j.data && typeof j.data.conflict_date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(j.data.conflict_date)) {
                        conflictDate = j.data.conflict_date;
                    }
                } catch(e){ /* ignore */ }

                // conflictModal があれば中身を書き換えて表示。Bootstrapがあればそれを使う
                try {
                    const modalEl = document.getElementById('conflictModal');
                    if (modalEl) {
                        const body = document.getElementById('conflictBody');
                        if (body) {
                            body.textContent = conflictDate
                                ? `${String(msg)}（対象日: ${conflictDate}）`
                                : String(msg);
                        }
                        const action = document.getElementById('conflictAction');
                        if (action) {
                            action.classList.add('d-none');
                        }
                        const reload = document.getElementById('conflictReload');
                        if (reload) {
                            if (!reload.getAttribute('data-default-label')) {
                                reload.setAttribute('data-default-label', reload.textContent || '対象日を再読み込み');
                            }
                            let reloadUrl = new URL(window.location.href);
                            if (conflictDate) {
                                reloadUrl.searchParams.set('date', conflictDate);
                            }
                            reloadUrl.searchParams.set('open_quick_modal', '1');
                            reload.setAttribute('href', reloadUrl.toString());
                            reload.classList.remove('disabled');
                            reload.setAttribute('aria-disabled', 'false');
                            reload.textContent = reload.getAttribute('data-default-label') || '対象日を再読み込み';
                            reload.onclick = function(ev) {
                                if (reload.classList.contains('disabled')) {
                                    ev.preventDefault();
                                    return false;
                                }
                                reload.classList.add('disabled');
                                reload.setAttribute('aria-disabled', 'true');
                                reload.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>再読み込み中...';
                                return true;
                            };
                            reload.classList.remove('d-none');
                        }
                        if (window.bootstrap && window.bootstrap.Modal) {
                            const inst = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                            inst.show();
                        } else {
                            // 既存の openModalById ヘルパーを使う（存在すれば）
                            if (typeof openModalById === 'function') openModalById('conflictModal');
                            else {
                                modalEl.classList.add('show');
                                modalEl.style.display = 'block';
                            }
                        }
                    } else {
                        notifyUser(msg, 'danger');
                    }
                } catch(e){
                    notifyUser(msg, 'danger');
                }

                // 既存の呼び出し側で catch できるようエラーを投げる（レスポンスを付与）
                const err = new Error('HTTP 409 Conflict');
                err.response = res;
                throw err;
            }
            return res;
        };
    })();
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('res-copy-modal');
        const form = document.getElementById('res-copy-form');
        if (!form) return;

        form.addEventListener('submit', function(e){
            e.preventDefault();
            const fd = new FormData(form);

            // チェックボックスの値を明示的に取得
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                fd.set(cb.name, cb.checked ? cb.value : '');
            });

            const payload = Object.fromEntries(fd.entries());
            fetch(copyApi, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            })
                .then(response => response.json())
                .then(data => {
                    // 成功時の処理
                    console.log('コピー完了', data);
                    // 必要ならリロードやUI更新
                })
                .catch(error => {
                    // エラー時の処理
                    console.error('コピー失敗', error);
                    notifyUser('コピーに失敗しました', 'danger');
                });
        });

        // lunch と bento の排他制御
        function setupLunchBentoPair(lunchSelector, bentoSelector) {
            const lunchCbs = document.querySelectorAll(lunchSelector);
            const bentoCbs = document.querySelectorAll(bentoSelector);

            lunchCbs.forEach((lunchCb, idx) => {
                const bentoCb = bentoCbs[idx];
                if (!lunchCb || !bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

                // 初期状態反映
                if (lunchCb.checked) {
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に選択できません';
                } else if (bentoCb.checked) {
                    lunchCb.disabled = true;
                    lunchCb.title = '昼食と弁当は同時に選択できません';
                }

                lunchCb.addEventListener('change', function() {
                    if (lunchCb.checked) {
                        bentoCb.checked = false;
                        bentoCb.disabled = true;
                        bentoCb.title = '昼食と弁当は同時に選択できません';
                    } else {
                        bentoCb.disabled = false;
                        bentoCb.title = '';
                    }
                });

                bentoCb.addEventListener('change', function() {
                    if (bentoCb.checked) {
                        lunchCb.checked = false;
                        lunchCb.disabled = true;
                        lunchCb.title = '昼食と弁当は同時に選択できません';
                    } else {
                        lunchCb.disabled = false;
                        lunchCb.title = '';
                    }
                });

                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';
            });
        }

        // 個人予約: name="reservation[昼食]" / name="reservation[弁当]"
        setupLunchBentoPair(
            'input[type="checkbox"][name*="lunch"]',
            'input[type="checkbox"][name*="bento"]'
        );

        // 集団予約: name="users[ID][昼食]" / name="users[ID][弁当]"
        setupLunchBentoPair(
            'input[type="checkbox"][name$="[lunch]"]',
            'input[type="checkbox"][name$="[bento]"]'
        );

        // モーダル描画後に排他制御を適用
        function applyLunchBentoExclusion(scope){
            var root = scope || document;

            // 個人予約
            var lunchCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="lunch"]'));
            var bentoCbs = Array.from(root.querySelectorAll('input[type="checkbox"][name*="bento"]'));
            lunchCbs.forEach(function(lunchCb, idx){
                var bentoCb = bentoCbs[idx];
                if (!bentoCb) return;
                if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                
                // 初期状態での排他適用
                if (lunchCb.checked && bentoCb.checked) {
                    bentoCb.checked = false;
                }
                
                lunchCb.addEventListener('change', function(){
                    if (lunchCb.checked && !lunchCb.disabled) {
                        if (bentoCb && !bentoCb.disabled) {
                            bentoCb.checked = false;
                            bentoCb.dispatchEvent(new Event('change'));
                        }
                    }
                });
                bentoCb.addEventListener('change', function(){
                    if (bentoCb.checked && !bentoCb.disabled) {
                        if (lunchCb && !lunchCb.disabled) {
                            lunchCb.checked = false;
                            lunchCb.dispatchEvent(new Event('change'));
                        }
                    }
                });
                lunchCb.dataset._paired = '1';
                bentoCb.dataset._paired = '1';
            });

            // 集団予約（利用者別）- users[userId][2] と users[userId][4]
            var groupRows = root.querySelectorAll('#user-checkboxes tr, tbody tr');
            groupRows.forEach(function(tr){
                var lunchCb = tr.querySelector('input[type="checkbox"][name$="[2]"]');
                var bentoCb = tr.querySelector('input[type="checkbox"][name$="[4]"]');
                if (lunchCb && bentoCb) {
                    if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                    
                    // 初期状態での排他適用
                    if (lunchCb.checked && bentoCb.checked) {
                        bentoCb.checked = false;
                    }
                    
                    lunchCb.addEventListener('change', function(){
                        if (lunchCb.checked && !lunchCb.disabled) {
                            if (bentoCb && !bentoCb.disabled) {
                                bentoCb.checked = false;
                                bentoCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    bentoCb.addEventListener('change', function(){
                        if (bentoCb.checked && !bentoCb.disabled) {
                            if (lunchCb && !lunchCb.disabled) {
                                lunchCb.checked = false;
                                lunchCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    lunchCb.dataset._paired = '1';
                    bentoCb.dataset._paired = '1';
                }
            });
            
            // 直前編集モーダル（change_edit.php）: data-reservation-type属性を使用
            var changeEditRows = root.querySelectorAll('#ce-tbody tr[data-user-id], tbody tr[data-user-id]');
            if (changeEditRows.length > 0) {
                console.log('[applyLunchBentoExclusion] 直前編集モーダルの排他制御を適用します。対象行数:', changeEditRows.length);
            }
            changeEditRows.forEach(function(tr){
                var lunchCb = tr.querySelector('input.meal-checkbox[data-reservation-type="2"]');
                var bentoCb = tr.querySelector('input.meal-checkbox[data-reservation-type="4"]');
                if (lunchCb && bentoCb) {
                    if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;
                    
                    // 初期状態での排他適用
                    if (lunchCb.checked && bentoCb.checked && !lunchCb.disabled && !bentoCb.disabled) {
                        bentoCb.checked = false;
                    }
                    
                    lunchCb.addEventListener('change', function(){
                        if (lunchCb.checked && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                            if (bentoCb && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                                bentoCb.checked = false;
                                bentoCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    
                    bentoCb.addEventListener('change', function(){
                        if (bentoCb.checked && !bentoCb.disabled && bentoCb.dataset.locked !== '1') {
                            if (lunchCb && !lunchCb.disabled && lunchCb.dataset.locked !== '1') {
                                lunchCb.checked = false;
                                lunchCb.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    
                    lunchCb.dataset._paired = '1';
                    bentoCb.dataset._paired = '1';
                }
            });
        }
        
        // グローバルスコープで使えるようにする
        window.applyLunchBentoExclusion = applyLunchBentoExclusion;

        // 例：add/changeEditモーダルの内容描画後
        applyLunchBentoExclusion(modalEl);
        
        // ページロード時に全体に適用
        applyLunchBentoExclusion(document);

        // モーダル表示時にも適用
        document.addEventListener('shown.bs.modal', function(ev) {
            var modal = ev.target;
            if (modal) {
                setTimeout(function() {
                    applyLunchBentoExclusion(modal);
                }, 100);
            }
        });
    });

// ================= 食数イベントクリック → ユーザー一覧モーダル =================
(function () {
    'use strict';

    var MEAL_LABELS = { 1: '朝食', 2: '昼食', 3: '夕食', 4: '弁当' };
    var MEAL_COLORS = { 1: '#17a2b8', 2: '#28a745', 3: '#6610f2', 4: '#fd7e14' };

    function getUsersByRoomUrl(roomId, date) {
        var tpl = window.GET_USERS_BY_ROOM_TPL || '';
        if (!tpl) return null;
        var url = tpl.replace('__RID__', encodeURIComponent(roomId));
        return url + '?date=' + encodeURIComponent(date);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildModalContent(usersByRoom) {
        var html = '';
        var mealKeys = { 1: 'morning', 2: 'noon', 3: 'night', 4: 'bento' };
        [1, 2, 3, 4].forEach(function (mt) {
            var key = mealKeys[mt];
            var users = usersByRoom.filter(function (u) { return !!u[key]; });
            var color = MEAL_COLORS[mt];
            html += '<div class="meal-cal-modal-section">';
            html += '<div class="meal-cal-modal-section-title" style="border-color:' + color + ';color:' + color + ';">'
                + escHtml(MEAL_LABELS[mt]) + '（' + users.length + '名）</div>';
            if (users.length === 0) {
                html += '<p class="text-muted small mb-0">なし</p>';
            } else {
                html += '<div class="meal-cal-user-list">';
                users.forEach(function (u) {
                    html += '<span class="meal-cal-user-chip">' + escHtml(u.name || '') + '</span>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        return html;
    }

    function openMealCalUserModal(date, roomId) {
        var modalEl   = document.getElementById('mealCalUserModal');
        if (!modalEl) return;
        var labelEl   = document.getElementById('mealCalModalDateLabel');
        var loadingEl = document.getElementById('mealCalModalLoading');
        var contentEl = document.getElementById('mealCalModalContent');
        if (labelEl)   labelEl.textContent = date + ' の食数詳細';
        if (loadingEl) loadingEl.classList.remove('d-none');
        if (contentEl) { contentEl.classList.add('d-none'); contentEl.innerHTML = ''; }

        var bsModal = window.bootstrap && window.bootstrap.Modal.getOrCreateInstance(modalEl);
        if (bsModal) bsModal.show();

        if (roomId == null || roomId === '') {
            if (loadingEl) loadingEl.classList.add('d-none');
            if (contentEl) {
                contentEl.innerHTML = '<p class="text-muted"><i class="bi bi-info-circle"></i> 部屋フィルタを選択すると利用者一覧を確認できます。</p>';
                contentEl.classList.remove('d-none');
            }
            return;
        }

        var url = getUsersByRoomUrl(roomId, date);
        if (!url) return;

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = (json && json.ok && json.data) ? json.data : json;
                var usersByRoom = data.usersByRoom || data.users || [];
                var html = buildModalContent(usersByRoom);
                if (contentEl) { contentEl.innerHTML = html; contentEl.classList.remove('d-none'); }
                if (loadingEl) loadingEl.classList.add('d-none');
            })
            .catch(function (err) {
                if (loadingEl) loadingEl.classList.add('d-none');
                if (contentEl) {
                    contentEl.innerHTML = '<p class="text-danger small">データの取得に失敗しました。</p>';
                    contentEl.classList.remove('d-none');
                }
                console.error('[mealCal] fetch error', err);
            });
    }

    // FullCalendar の eventClick から呼べるよう公開
    window.openMealCalUserModal = openMealCalUserModal;
})();
