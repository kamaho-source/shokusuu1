/**
 * サイボウズモード（複数日まとめて予約）
 * 部屋・食事を選び、日付を複数クリックして「登録する」で、日付ごとに direct-register を順番に呼ぶ。
 * treservation_toast.js の後にロードすること（pageToast を使う）。
 */
(function () {
    'use strict';

    var MEALS = [
        { id: 1, key: 'breakfast', label: '朝食', icon: '☀️', short: '朝' },
        { id: 2, key: 'lunch',     label: '昼食', icon: '😋', short: '昼' },
        { id: 3, key: 'dinner',    label: '夕食', icon: '🌙', short: '夜' }
    ];
    // 部屋の色は所属順に割り当てる（1つ目＝緑、2つ目＝紫）
    var ROOM_COLORS = ['#28a745', '#7c3aed', '#0d6efd', '#d63384', '#0891b2', '#6b7280'];
    var MAX_DATES = 20;
    var MONTHS_AHEAD = 2; // 予約データ（myDetails）は今月から2か月先まで
    var NETWORK_ERROR = '通信に失敗しました。時間をおいてやり直してください。';

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function parse(str) { var p = String(str).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function diffDays(a, b) { return Math.round((parse(a) - parse(b)) / 86400000); }
    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function mealById(id) { return MEALS.filter(function (m) { return m.id === id; })[0] || null; }

    /** 選んだ食事がその日に登録済みなら理由を返す（部屋は問わない。昼食は弁当とも重ならない） */
    function lockReason(detail, mealId) {
        var meal = mealById(mealId);
        if (!detail || !meal) return null;
        if (detail[meal.key]) return 'registered';
        if (mealId === 2 && detail.bento) return 'bento';
        return null;
    }

    function isWithin14(date, today) {
        var d = diffDays(date, today);
        return d >= 0 && d <= 14;
    }

    /** 日曜始まりの週の配列。各日は {date, inMonth} */
    function monthCells(year, month) {
        var first = new Date(year, month, 1);
        var start = new Date(year, month, 1 - first.getDay());
        var weeks = [];
        for (var w = 0; w < 6; w++) {
            var week = [];
            for (var i = 0; i < 7; i++) {
                var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + w * 7 + i);
                week.push({ date: ymd(d.getFullYear(), d.getMonth(), d.getDate()), inMonth: d.getMonth() === month, dow: i });
            }
            if (w > 0 && !week.some(function (c) { return c.inMonth; })) break;
            weeks.push(week);
        }
        return weeks;
    }

    function holidayName(date) {
        var J = window.JapaneseHolidays;
        return (J && typeof J.isHoliday === 'function') ? (J.isHoliday(parse(date)) || null) : null;
    }

    function shortDate(date) {
        var d = parse(date);
        return (d.getMonth() + 1) + '/' + d.getDate() + '（' + '日月火水木金土'[d.getDay()] + '）';
    }

    function init() {
        var cfg = window.__TRESP || {};
        var modalEl = document.getElementById('cybozuModal');
        if (!cfg.isCybozu || !modalEl) return;

        var today = String(cfg.serverToday || '');
        var details = cfg.myDetails || {};
        var rooms = (cfg.pickerRooms || []).map(function (r, i) {
            return { id: Number(r.id), name: String(r.name), color: ROOM_COLORS[i % ROOM_COLORS.length] };
        });
        var multiRoom = rooms.length > 1;
        var roomNames = Object.assign({}, cfg.roomNames || {});
        rooms.forEach(function (r) { roomNames[r.id] = r.name; });
        function roomOf(id) { return rooms.filter(function (r) { return r.id === Number(id); })[0] || null; }
        function roomName(id) { return roomNames[id] || ('部屋' + id); }

        var initialRoom = roomOf(cfg.calRoomId) || roomOf(cfg.roomId) || rooms[0] || null;
        var t = parse(today);
        var state = {
            roomId: initialRoom ? initialRoom.id : null,
            meal: null,
            selected: {}, // date => roomId
            year: t.getFullYear(),
            month: t.getMonth(),
            busy: false,
            progress: ''
        };
        var minKey = state.year * 12 + state.month;
        var maxKey = minKey + MONTHS_AHEAD;

        var $ = function (id) { return document.getElementById(id); };
        var toast = function (msg, type) { if (window.pageToast) window.pageToast(msg, type); };
        var selectedDates = function () { return Object.keys(state.selected).sort(); };

        function bandHtml(date) {
            var d = details[date];
            var any = d && (d.breakfast || d.lunch || d.dinner || d.bento);
            if (!any) return '<div class="cybozu-band none">未予約</div>';
            var mark = function (k) { return d[k] ? '○' : '×'; };
            var html = '<div class="cybozu-band res">朝' + mark('breakfast') + ' 昼' + mark('lunch') +
                '<br>夜' + mark('dinner') + ' 弁' + mark('bento') + '</div>';
            if (!multiRoom) return html;
            // 予約の部屋名。食事ごとに部屋が違う日は「朝:ナザレ 夜:ガリラヤ」
            var used = [['breakfast', '朝'], ['lunch', '昼'], ['dinner', '夜'], ['bento', '弁']]
                .filter(function (p) { return d[p[0]] && d[p[0] + 'Room']; })
                .map(function (p) { return { s: p[1], id: d[p[0] + 'Room'] }; });
            var ids = used.map(function (u) { return String(u.id); }).filter(function (v, i, a) { return a.indexOf(v) === i; });
            if (ids.length === 0) return html;
            var label = ids.length === 1 ? roomName(ids[0])
                : used.map(function (u) { return u.s + ':' + roomName(u.id); }).join(' ');
            return html + '<div class="cybozu-room">' + esc(label) + '</div>';
        }

        function renderRooms() {
            $('cybozuRoomRow').hidden = !multiRoom;
            $('cybozuRooms').innerHTML = rooms.map(function (r) {
                var on = r.id === state.roomId;
                return '<button type="button" class="cybozu-pill" data-room="' + r.id + '" aria-pressed="' + on + '" style="' +
                    (on ? 'background:' + r.color + ';color:#fff;border-color:' + r.color : 'color:' + r.color + ';border-color:' + r.color) +
                    '">● ' + esc(r.name) + '</button>';
            }).join('');
        }

        function renderMeals() {
            $('cybozuMeals').innerHTML = MEALS.map(function (m) {
                var on = m.id === state.meal;
                return '<button type="button" class="cybozu-meal' + (on ? ' on' : '') + '" data-meal="' + m.id +
                    '" aria-pressed="' + on + '">' + m.icon + ' ' + m.label + '</button>';
            }).join('');
            $('cybozuHint').textContent = rooms.length === 0
                ? ''
                : (multiRoom ? '① 部屋を選ぶ → ② 食事を選ぶ → ③ 日付をクリック → 登録する。' : '① 食事を選ぶ → ② 日付をクリック → 登録する。') +
                  '🔒＝選んだ食事が登録済みの日（選べません）';
        }

        function renderCalendar() {
            $('cybozuMonth').textContent = state.year + '年' + (state.month + 1) + '月';
            var key = state.year * 12 + state.month;
            $('cybozuPrev').disabled = key <= minKey;
            $('cybozuNext').disabled = key >= maxKey;
            var html = '<table class="cybozu-cal"><thead><tr>' +
                ['日', '月', '火', '水', '木', '金', '土'].map(function (w, i) {
                    return '<th class="' + (i === 0 ? 'sun' : (i === 6 ? 'sat' : '')) + '">' + w + '</th>';
                }).join('') + '</tr></thead><tbody>';
            monthCells(state.year, state.month).forEach(function (week) {
                html += '<tr>';
                week.forEach(function (c) {
                    var past = !c.inMonth || c.date < today;
                    var hol = holidayName(c.date);
                    var lock = !past && state.meal ? lockReason(details[c.date], state.meal) : null;
                    var selRoom = state.selected[c.date] ? roomOf(state.selected[c.date]) : null;
                    var cls = ['cybozu-day'];
                    if (c.dow === 0 || hol) cls.push('sun');
                    if (c.dow === 6) cls.push('sat');
                    if (past) cls.push('past');
                    if (c.date === today) cls.push('today');
                    if (lock) cls.push('lock');
                    if (selRoom) cls.push('sel');
                    var title = lock === 'bento' ? 'お弁当を登録済みのため、昼食は選べません'
                        : (lock ? 'この食事は登録済みです' : '');
                    html += '<td class="' + cls.join(' ') + '" data-date="' + c.date + '"' +
                        (past || lock ? ' aria-disabled="true"' : ' role="button" tabindex="0"') +
                        (selRoom ? ' style="background:' + selRoom.color + '"' : '') +
                        (title ? ' title="' + esc(title) + '"' : '') + '>' +
                        (hol && c.inMonth ? '<div class="cybozu-hol">' + esc(hol) + '</div>' : '') +
                        '<div class="cybozu-num">' + parse(c.date).getDate() +
                        (selRoom ? '<span class="cybozu-ck">✓</span>' : '') +
                        (lock ? '<span class="cybozu-lk">🔒</span>' : '') + '</div>' +
                        (c.inMonth ? bandHtml(c.date) : '') + '</td>';
                });
                html += '</tr>';
            });
            $('cybozuCalendar').innerHTML = html + '</tbody></table>';
        }

        function renderSummary() {
            var dates = selectedDates();
            var meal = mealById(state.meal);
            var counts = rooms.map(function (r) {
                var n = dates.filter(function (d) { return state.selected[d] === r.id; }).length;
                return n ? esc(r.name) + ' ' + n + '日' : '';
            }).filter(Boolean);
            var html = '';
            if (rooms.length === 0) {
                html = '<span class="text-danger fw-bold">所属している部屋がありません。部屋を選択してください。</span>';
            } else {
                html = '✅ <b>' + dates.length + '日</b> 選択中　' +
                    (meal ? '<span class="cybozu-tag">' + meal.icon + ' ' + meal.label + '</span>'
                          : '<span class="text-danger fw-bold">食事を選んでください</span>') +
                    (multiRoom && counts.length ? '（' + counts.join('・') + '）' : '') +
                    (dates.length >= MAX_DATES ? ' <span class="text-danger small">最大' + MAX_DATES + '件まで選べます</span>' : '');
            }
            $('cybozuSummary').innerHTML = html;
            $('cybozuChips').innerHTML = rooms.map(function (r) {
                var mine = dates.filter(function (d) { return state.selected[d] === r.id; });
                if (!mine.length) return '';
                return '<div class="cybozu-chips">' +
                    (multiRoom ? '<b style="color:' + r.color + '">' + esc(r.name) + '</b>' : '') +
                    mine.map(function (d) {
                        return '<span class="cybozu-chip" style="border-color:' + r.color + ';color:' + r.color + '">' + shortDate(d) +
                            ' <button type="button" class="cybozu-chip-x" data-remove="' + d + '" aria-label="' + shortDate(d) + 'を外す">×</button></span>';
                    }).join('') + '</div>';
            }).join('');
            $('cybozuLastMinute').hidden = !!cfg.isAdmin || !dates.some(function (d) { return isWithin14(d, today); });
            var btn = $('cybozuSubmit');
            btn.disabled = state.busy || !meal || dates.length === 0 || rooms.length === 0;
            btn.textContent = state.busy ? '登録中…' + (state.progress || '') : '✅ 登録する（' + dates.length + '件）';
        }

        function render() { renderRooms(); renderMeals(); renderCalendar(); renderSummary(); }

        function clickDate(date) {
            if (state.busy || !state.roomId) return;
            if (date < today) return;
            if (state.meal && lockReason(details[date], state.meal)) return;
            var cur = state.selected[date];
            if (cur === state.roomId) {
                delete state.selected[date];
            } else if (cur) {
                state.selected[date] = state.roomId; // 別の部屋で選択中の日は、今の部屋に付け替える
            } else {
                if (selectedDates().length >= MAX_DATES) {
                    toast('選べる日付は最大' + MAX_DATES + '件です。', 'warning');
                    return;
                }
                state.selected[date] = state.roomId;
            }
            renderCalendar(); renderSummary();
        }

        function chooseMeal(id) {
            state.meal = id;
            var dropped = selectedDates().filter(function (d) { return lockReason(details[d], id); });
            dropped.forEach(function (d) { delete state.selected[d]; });
            if (dropped.length) {
                toast(dropped.map(shortDate).join('・') + 'は' + mealById(id).label + 'が登録済みのため、選択から外しました。', 'warning');
            }
            render();
        }

        function post(date, roomId) {
            var csrf = window.__csrfToken || cfg.csrfToken || '';
            return fetch(cfg.directRegisterUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ date: date, roomId: roomId, meals: [state.meal], userId: cfg.userId })
            }).then(function (res) {
                return res.text().then(function (text) {
                    var body = {};
                    try { body = JSON.parse(text) || {}; } catch (e) { body = {}; }
                    return { status: res.status, body: body };
                });
            }).catch(function () { return { status: 0, body: {} }; });
        }

        function submit() {
            var dates = selectedDates();
            var meal = mealById(state.meal);
            if (state.busy || !meal || !dates.length) return Promise.resolve();
            state.busy = true;
            renderSummary();
            var done = [], skipped = [], failed = [];
            var chain = Promise.resolve();
            // サーバーの負荷と競合を避けるため、1日ずつ順番に登録する
            dates.forEach(function (date) {
                chain = chain.then(function () {
                    var roomId = state.selected[date];
                    state.progress = '（' + (done.length + skipped.length + failed.length + 1) + '/' + dates.length + '）';
                    renderSummary();
                    return post(date, roomId).then(function (r) {
                        var data = r.body && r.body.data;
                        if (r.status === 200 && r.body.ok === true && data) {
                            ((data.registered || []).indexOf(meal.id) !== -1 ? done : skipped).push({ date: date, roomId: roomId });
                            return;
                        }
                        failed.push({ date: date, message: r.status === 0 ? NETWORK_ERROR : (r.body.message || ('登録できませんでした（' + r.status + '）')) });
                    });
                });
            });
            return chain.then(function () {
                // 登録できた日は🔒にして、背景のカレンダーにもすぐ反映する（再読込すると選んだ月や食事が消えるため、しない）
                done.forEach(function (x) {
                    var d = details[x.date] = details[x.date] || {};
                    d[meal.key] = 1;
                    d[meal.key + 'Room'] = x.roomId;
                    if (typeof window.__applyReservationToCalendar === 'function') {
                        window.__applyReservationToCalendar(x.date, meal.id, x.roomId);
                    }
                });
                // 失敗した日だけを選択に残し、もう一度「登録する」でやり直せるようにする
                // スキップの日は選択から外すだけ（理由が昼食と弁当の重なりのこともあるため）
                done.concat(skipped).forEach(function (x) { delete state.selected[x.date]; });
                var list = function (xs) { return xs.map(function (x) { return shortDate(x.date); }).join('・'); };
                var parts = [];
                if (done.length) parts.push('登録 ' + done.length + '件（' + list(done) + '）');
                if (skipped.length) parts.push('スキップ ' + skipped.length + '件（' + list(skipped) + '：登録済み、または昼食とお弁当が重なる日）');
                if (failed.length) parts.push('失敗 ' + failed.length + '件（' + failed.map(function (f) { return shortDate(f.date) + ' ' + f.message; }).join(' / ') + '）');
                var type = failed.length ? 'danger' : (skipped.length ? 'warning' : 'success');
                toast(meal.label + '：' + (parts.join('。') || '登録 0件'), type);
                state.busy = false;
                state.progress = '';
                render();
            });
        }

        function showModal() {
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        modalEl.addEventListener('click', function (e) {
            var el = e.target.closest('[data-room],[data-meal],[data-date],[data-remove]');
            if (!el || state.busy) return;
            if (el.hasAttribute('data-remove')) {
                delete state.selected[el.getAttribute('data-remove')];
                renderCalendar(); renderSummary();
            } else if (el.hasAttribute('data-room')) {
                state.roomId = Number(el.getAttribute('data-room'));
                renderRooms();
            } else if (el.hasAttribute('data-meal')) {
                chooseMeal(Number(el.getAttribute('data-meal')));
            } else {
                clickDate(el.getAttribute('data-date'));
            }
        });
        modalEl.addEventListener('keydown', function (e) {
            var td = e.target.closest && e.target.closest('td[data-date]');
            if (td && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); clickDate(td.getAttribute('data-date')); }
        });
        $('cybozuPrev').addEventListener('click', function () {
            if (state.year * 12 + state.month <= minKey) return;
            state.month -= 1; if (state.month < 0) { state.month = 11; state.year -= 1; }
            renderCalendar();
        });
        $('cybozuNext').addEventListener('click', function () {
            if (state.year * 12 + state.month >= maxKey) return;
            state.month += 1; if (state.month > 11) { state.month = 0; state.year += 1; }
            renderCalendar();
        });
        $('cybozuSubmit').addEventListener('click', submit);

        var entry = document.getElementById('cybozuModeButton');
        if (entry) {
            entry.addEventListener('click', function (e) { e.preventDefault(); showModal(); });
        }

        render();
        if (rooms.length === 0) toast('所属している部屋がありません。部屋を選択してください。', 'warning');
        showModal();

        window.CybozuMode.state = state;
        window.CybozuMode.submit = submit;
    }

    window.CybozuMode = { lockReason: lockReason, monthCells: monthCells, isWithin14: isWithin14, init: init };
    document.addEventListener('DOMContentLoaded', init);
})();
