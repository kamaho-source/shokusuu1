/**
 * サイボウズモード（複数日まとめて予約）
 * 食事ごとに部屋・日付を複数クリックで選ぶ。食事を切り替えても、他の食事で選んだ日は残る。
 * 「登録する」で、日付と部屋ごとに direct-register を順番に呼び、全部の食事をまとめて登録する。
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
            meal: MEALS[0].id, // 今、日付を選んでいる食事
            selected: { 1: {}, 2: {}, 3: {} }, // mealId => { date => roomId }
            year: t.getFullYear(),
            month: t.getMonth(),
            busy: false,
            progress: ''
        };
        var minKey = state.year * 12 + state.month;
        var maxKey = minKey + MONTHS_AHEAD;

        var $ = function (id) { return document.getElementById(id); };
        var toast = function (msg, type) { if (window.pageToast) window.pageToast(msg, type); };
        var selectedDates = function (mealId) { return Object.keys(state.selected[mealId || state.meal]).sort(); };
        /** 選択中の全件。[{date, meal, roomId}]（日付順・食事順） */
        function allPicks() {
            var picks = [];
            MEALS.forEach(function (m) {
                selectedDates(m.id).forEach(function (d) { picks.push({ date: d, meal: m.id, roomId: state.selected[m.id][d] }); });
            });
            return picks.sort(function (a, b) { return a.date < b.date ? -1 : (a.date > b.date ? 1 : a.meal - b.meal); });
        }

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
                var n = selectedDates(m.id).length;
                return '<button type="button" class="cybozu-meal' + (on ? ' on' : '') + '" data-meal="' + m.id +
                    '" aria-pressed="' + on + '">' + m.icon + ' ' + m.label +
                    (n ? ' <span class="cybozu-meal-n">' + n + '日</span>' : '') + '</button>';
            }).join('');
            $('cybozuHint').textContent = rooms.length === 0
                ? ''
                : (multiRoom ? '① 部屋と食事を選ぶ → ② 日付をクリック。' : '① 食事を選ぶ → ② 日付をクリック。') +
                  '食事を切り替えても、選んだ日は残ります。最後に「登録する」でまとめて登録します。🔒＝その食事が登録済みの日';
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
                    var lock = !past ? lockReason(details[c.date], state.meal) : null;
                    var selRoom = state.selected[state.meal][c.date] ? roomOf(state.selected[state.meal][c.date]) : null;
                    // 他の食事で選んだ日も分かるよう、選択中の食事をセルに並べる（例：朝 夜）
                    var picks = c.inMonth ? MEALS.filter(function (m) { return state.selected[m.id][c.date]; }) : [];
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
                        (c.inMonth ? bandHtml(c.date) : '') +
                        (picks.length ? '<div class="cybozu-picks">' + picks.map(function (m) {
                            var r = roomOf(state.selected[m.id][c.date]);
                            return '<span class="' + (m.id === state.meal ? 'cur' : '') + '" style="background:' + (r ? r.color : '#28a745') + '">' + m.short + '</span>';
                        }).join('') + '</div>' : '') + '</td>';
                });
                html += '</tr>';
            });
            $('cybozuCalendar').innerHTML = html + '</tbody></table>';
        }

        function renderSummary() {
            var picks = allPicks();
            var html = '';
            if (rooms.length === 0) {
                html = '<span class="text-danger fw-bold">所属している部屋がありません。部屋を選択してください。</span>';
            } else {
                var perMeal = MEALS.map(function (m) {
                    var n = selectedDates(m.id).length;
                    return n ? m.icon + ' ' + m.label + ' ' + n + '日' : '';
                }).filter(Boolean);
                html = '✅ <b>' + picks.length + '件</b> 選択中' + (perMeal.length ? '（' + perMeal.join('・') + '）' : '') +
                    (selectedDates().length >= MAX_DATES ? ' <span class="text-danger small">1つの食事につき最大' + MAX_DATES + '日まで選べます</span>' : '');
            }
            $('cybozuSummary').innerHTML = html;
            // 食事ごと・部屋ごとに、選んだ日を並べる
            $('cybozuChips').innerHTML = MEALS.map(function (m) {
                return rooms.map(function (r) {
                    var mine = selectedDates(m.id).filter(function (d) { return state.selected[m.id][d] === r.id; });
                    if (!mine.length) return '';
                    return '<div class="cybozu-chips"><b>' + m.icon + ' ' + m.label + '</b>' +
                        (multiRoom ? '<b style="color:' + r.color + '">' + esc(r.name) + '</b>' : '') +
                        mine.map(function (d) {
                            return '<span class="cybozu-chip" style="border-color:' + r.color + ';color:' + r.color + '">' + shortDate(d) +
                                ' <button type="button" class="cybozu-chip-x" data-remove="' + d + '" data-remove-meal="' + m.id + '" aria-label="' +
                                m.label + ' ' + shortDate(d) + 'を外す">×</button></span>';
                        }).join('') + '</div>';
                }).join('');
            }).join('');
            $('cybozuLastMinute').hidden = !!cfg.isAdmin || !picks.some(function (p) { return isWithin14(p.date, today); });
            var btn = $('cybozuSubmit');
            btn.disabled = state.busy || picks.length === 0 || rooms.length === 0;
            btn.textContent = state.busy ? '登録中…' + (state.progress || '') : '✅ 登録する（' + picks.length + '件）';
        }

        function render() { renderRooms(); renderMeals(); renderCalendar(); renderSummary(); }

        function clickDate(date) {
            if (state.busy || !state.roomId) return;
            if (date < today) return;
            if (lockReason(details[date], state.meal)) return;
            var sel = state.selected[state.meal];
            var cur = sel[date];
            if (cur === state.roomId) {
                delete sel[date];
            } else if (cur) {
                sel[date] = state.roomId; // 別の部屋で選択中の日は、今の部屋に付け替える
            } else {
                if (selectedDates().length >= MAX_DATES) {
                    toast('選べる日付は、1つの食事につき最大' + MAX_DATES + '日です。', 'warning');
                    return;
                }
                sel[date] = state.roomId;
            }
            renderMeals(); renderCalendar(); renderSummary();
        }

        function chooseMeal(id) {
            // 他の食事で選んだ日はそのまま残す
            state.meal = id;
            render();
        }

        function post(date, roomId, meals) {
            var csrf = window.__csrfToken || cfg.csrfToken || '';
            return fetch(cfg.directRegisterUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ date: date, roomId: roomId, meals: meals, userId: cfg.userId })
            }).then(function (res) {
                return res.text().then(function (text) {
                    var body = {};
                    try { body = JSON.parse(text) || {}; } catch (e) { body = {}; }
                    return { status: res.status, body: body };
                });
            }).catch(function () { return { status: 0, body: {} }; });
        }

        function submit() {
            var picks = allPicks();
            if (state.busy || !picks.length) return Promise.resolve();
            // 同じ日・同じ部屋の食事は1回でまとめて送る
            var groups = [];
            picks.forEach(function (p) {
                var g = groups.filter(function (x) { return x.date === p.date && x.roomId === p.roomId; })[0];
                if (!g) groups.push(g = { date: p.date, roomId: p.roomId, meals: [] });
                g.meals.push(p.meal);
            });
            state.busy = true;
            var done = [], skipped = [], failed = [];
            var chain = Promise.resolve();
            // サーバーの負荷と競合を避けるため、1回ずつ順番に登録する
            groups.forEach(function (g, i) {
                chain = chain.then(function () {
                    state.progress = '（' + (i + 1) + '/' + groups.length + '）';
                    renderSummary();
                    return post(g.date, g.roomId, g.meals).then(function (r) {
                        var data = r.body && r.body.data;
                        var ok = r.status === 200 && r.body.ok === true && data;
                        var message = r.status === 0 ? NETWORK_ERROR : ((r.body && r.body.message) || ('登録できませんでした（' + r.status + '）'));
                        g.meals.forEach(function (mealId) {
                            var x = { date: g.date, meal: mealId, roomId: g.roomId };
                            if (!ok) { x.message = message; failed.push(x); return; }
                            ((data.registered || []).indexOf(mealId) !== -1 ? done : skipped).push(x);
                        });
                    });
                });
            });
            return chain.then(function () {
                // 登録できた日は🔒にして、背景のカレンダーにもすぐ反映する（再読込すると選んだ内容が消えるため、しない）
                done.forEach(function (x) {
                    var key = mealById(x.meal).key;
                    var d = details[x.date] = details[x.date] || {};
                    d[key] = 1;
                    d[key + 'Room'] = x.roomId;
                    if (typeof window.__applyReservationToCalendar === 'function') {
                        window.__applyReservationToCalendar(x.date, x.meal, x.roomId);
                    }
                });
                // 失敗した分だけを選択に残し、もう一度「登録する」でやり直せるようにする
                // スキップは選択から外すだけ（理由が昼食と弁当の重なりのこともあるため）
                done.concat(skipped).forEach(function (x) { delete state.selected[x.meal][x.date]; });
                // 「朝食 10/20（火）・10/21（水）／夕食 10/20（火）」の形で並べる
                var list = function (xs) {
                    return MEALS.map(function (m) {
                        var mine = xs.filter(function (x) { return x.meal === m.id; });
                        return mine.length ? m.label + ' ' + mine.map(function (x) { return shortDate(x.date); }).join('・') : '';
                    }).filter(Boolean).join('／');
                };
                var parts = [];
                if (done.length) parts.push('登録 ' + done.length + '件（' + list(done) + '）');
                if (skipped.length) parts.push('スキップ ' + skipped.length + '件（' + list(skipped) + '：登録済み、または昼食とお弁当が重なる日）');
                if (failed.length) {
                    var msgs = failed.map(function (f) { return shortDate(f.date) + ' ' + f.message; })
                        .filter(function (v, i, a) { return a.indexOf(v) === i; });
                    parts.push('失敗 ' + failed.length + '件（' + list(failed) + '：' + msgs.join(' / ') + '）');
                }
                var type = failed.length ? 'danger' : (skipped.length ? 'warning' : 'success');
                toast(parts.join('。') || '登録 0件', type);
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
                delete state.selected[Number(el.getAttribute('data-remove-meal'))][el.getAttribute('data-remove')];
                render();
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
