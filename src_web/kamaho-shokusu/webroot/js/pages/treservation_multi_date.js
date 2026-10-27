/**
 * 複数日予約
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
    var MAX_DATES = 31;
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
        var modalEl = document.getElementById('multiDateModal');
        if (!modalEl) return;

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
            skipLock: { 1: {}, 2: {}, 3: {} }, // スキップされた日（別の部屋で登録済みなど。部屋が分からないので myDetails には書かない）
            year: t.getFullYear(),
            month: t.getMonth(),
            busy: false,
            progress: ''
        };
        var minKey = state.year * 12 + state.month;
        // 児童は子どもUIと同じく、今日から1か月後の前日までしか予約できない（kid_section.php と同じ基準）
        var lastDate = cfg.isChild ? (function () {
            var e = new Date(t.getFullYear(), t.getMonth() + 1, t.getDate() - 1);
            return ymd(e.getFullYear(), e.getMonth(), e.getDate());
        })() : null;
        var maxKey = lastDate ? Math.min(minKey + MONTHS_AHEAD, parse(lastDate).getFullYear() * 12 + parse(lastDate).getMonth()) : minKey + MONTHS_AHEAD;
        var beyond = function (date) { return !!lastDate && date > lastDate; };

        var $ = function (id) { return document.getElementById(id); };
        var toast = function (msg, type) { if (window.pageToast) window.pageToast(msg, type); };
        function lockOf(date, mealId) {
            return lockReason(details[date], mealId) || (state.skipLock[mealId][date] ? 'skipped' : null);
        }
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
            if (!any) return '<div class="multi-date-band none">未予約</div>';
            var mark = function (k) { return d[k] ? '○' : '×'; };
            var html = '<div class="multi-date-band res">朝' + mark('breakfast') + ' 昼' + mark('lunch') +
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
            return html + '<div class="multi-date-room">' + esc(label) + '</div>';
        }

        function renderRooms() {
            $('multiDateRoomRow').hidden = !multiRoom;
            $('multiDateRooms').innerHTML = rooms.map(function (r) {
                var on = r.id === state.roomId;
                return '<button type="button" class="multi-date-pill" data-room="' + r.id + '" aria-pressed="' + on + '" style="' +
                    (on ? 'background:' + r.color + ';color:#fff;border-color:' + r.color : 'color:' + r.color + ';border-color:' + r.color) +
                    '">● ' + esc(r.name) + '</button>';
            }).join('');
        }

        function renderMeals() {
            $('multiDateMeals').innerHTML = MEALS.map(function (m) {
                var on = m.id === state.meal;
                var n = selectedDates(m.id).length;
                return '<button type="button" class="multi-date-meal' + (on ? ' on' : '') + '" data-meal="' + m.id +
                    '" aria-pressed="' + on + '">' + m.icon + ' ' + m.label +
                    (n ? ' <span class="multi-date-meal-n">' + n + '日</span>' : '') + '</button>';
            }).join('');
            $('multiDateHint').textContent = rooms.length === 0
                ? ''
                : (multiRoom ? '① 部屋と食事を選ぶ → ② 日付をクリック。' : '① 食事を選ぶ → ② 日付をクリック。') +
                  '食事を切り替えても、選んだ日は残ります。最後に「登録する」でまとめて登録します。🔒＝その食事が登録済みの日';
        }

        function renderCalendar() {
            $('multiDateMonth').textContent = state.year + '年' + (state.month + 1) + '月';
            var key = state.year * 12 + state.month;
            $('multiDatePrev').disabled = key <= minKey;
            $('multiDateNext').disabled = key >= maxKey;
            var html = '<table class="multi-date-cal"><thead><tr>' +
                ['日', '月', '火', '水', '木', '金', '土'].map(function (w, i) {
                    return '<th class="' + (i === 0 ? 'sun' : (i === 6 ? 'sat' : '')) + '">' + w + '</th>';
                }).join('') + '</tr></thead><tbody>';
            monthCells(state.year, state.month).forEach(function (week) {
                html += '<tr>';
                week.forEach(function (c) {
                    var past = !c.inMonth || c.date < today || beyond(c.date);
                    var hol = holidayName(c.date);
                    var lock = !past ? lockOf(c.date, state.meal) : null;
                    var selRoom = state.selected[state.meal][c.date] ? roomOf(state.selected[state.meal][c.date]) : null;
                    // 他の食事で選んだ日も分かるよう、選択中の食事をセルに並べる（例：朝 夜）
                    var picks = c.inMonth ? MEALS.filter(function (m) { return state.selected[m.id][c.date]; }) : [];
                    var cls = ['multi-date-day'];
                    if (c.dow === 0 || hol) cls.push('sun');
                    if (c.dow === 6) cls.push('sat');
                    if (past) cls.push('past');
                    if (c.date === today) cls.push('today');
                    if (lock) cls.push('lock');
                    if (selRoom) cls.push('sel');
                    var title = lock === 'bento' ? 'お弁当を登録済みのため、昼食は選べません'
                        : (lock === 'skipped' ? '登録済み、または昼食とお弁当が重なるため、登録しませんでした'
                        : (lock ? 'この食事は登録済みです' : ''));
                    var d0 = parse(c.date);
                    // aria-label は中身の読み上げを置き換えるので、予約状況と他の食事の選択も入れる
                    var dd = details[c.date] || {};
                    var label = (d0.getMonth() + 1) + '月' + d0.getDate() + '日 ' + mealById(state.meal).label +
                        (selRoom ? ' 選択中' : '') + (title ? ' ' + title : '') +
                        '。予約 朝' + (dd.breakfast ? '○' : '×') + ' 昼' + (dd.lunch ? '○' : '×') + ' 夜' + (dd.dinner ? '○' : '×') + ' 弁' + (dd.bento ? '○' : '×') +
                        (picks.length ? '。選択中の食事 ' + picks.map(function (m) { return m.label; }).join('・') : '');
                    // 月の外の日は選べない（data-date を付けない。表示中の月より先は myDetails の範囲外のこともあるため）
                    html += '<td class="' + cls.join(' ') + '"' + (c.inMonth ? ' data-date="' + c.date + '"' : '') +
                        (past || lock ? ' aria-disabled="true"'
                            : ' role="button" tabindex="0" aria-pressed="' + !!selRoom + '" aria-label="' + esc(label) + '"') +
                        (selRoom ? ' style="background:' + selRoom.color + '"' : '') +
                        (title ? ' title="' + esc(title) + '"' : '') + '>' +
                        (hol && c.inMonth ? '<div class="multi-date-hol">' + esc(hol) + '</div>' : '') +
                        '<div class="multi-date-num">' + parse(c.date).getDate() +
                        (selRoom ? '<span class="multi-date-ck">✓</span>' : '') +
                        (lock ? '<span class="multi-date-lk">🔒</span>' : '') + '</div>' +
                        (c.inMonth ? bandHtml(c.date) : '') +
                        (picks.length ? '<div class="multi-date-picks">' + picks.map(function (m) {
                            var r = roomOf(state.selected[m.id][c.date]);
                            return '<span class="' + (m.id === state.meal ? 'cur' : '') + '" style="background:' + (r ? r.color : '#28a745') + '">' + m.short + '</span>';
                        }).join('') + '</div>' : '') + '</td>';
                });
                html += '</tr>';
            });
            $('multiDateCalendar').innerHTML = html + '</tbody></table>';
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
            $('multiDateSummary').innerHTML = html;
            // 食事ごと・部屋ごとに、選んだ日を並べる
            $('multiDateChips').innerHTML = MEALS.map(function (m) {
                return rooms.map(function (r) {
                    var mine = selectedDates(m.id).filter(function (d) { return state.selected[m.id][d] === r.id; });
                    if (!mine.length) return '';
                    return '<div class="multi-date-chips"><b>' + m.icon + ' ' + m.label + '</b>' +
                        (multiRoom ? '<b style="color:' + r.color + '">' + esc(r.name) + '</b>' : '') +
                        mine.map(function (d) {
                            return '<span class="multi-date-chip" style="border-color:' + r.color + ';color:' + r.color + '">' + shortDate(d) +
                                ' <button type="button" class="multi-date-chip-x" data-remove="' + d + '" data-remove-meal="' + m.id + '" aria-label="' +
                                m.label + ' ' + shortDate(d) + 'を外す">×</button></span>';
                        }).join('') + '</div>';
                }).join('');
            }).join('');
            $('multiDateLastMinute').hidden = !!cfg.isAdmin || !picks.some(function (p) { return isWithin14(p.date, today); });
            var btn = $('multiDateSubmit');
            btn.disabled = state.busy || picks.length === 0 || rooms.length === 0;
            btn.textContent = state.busy ? '登録中…' + (state.progress || '') : '✅ 登録する（' + picks.length + '件）';
        }

        /** 描き直してもキーボードのフォーカスを同じ日付・食事・部屋に戻す */
        function keepFocus(fn) {
            var a = document.activeElement;
            var attr = a && a.closest && modalEl.contains(a) && ['data-date', 'data-meal', 'data-room'].filter(function (n) { return a.hasAttribute(n); })[0];
            var val = attr ? a.getAttribute(attr) : null;
            fn();
            if (!attr) return;
            var el = modalEl.querySelector('[' + attr + '="' + val + '"]');
            if (el && el.focus) el.focus({ preventScroll: true });
        }

        function render() { keepFocus(function () { renderRooms(); renderMeals(); renderCalendar(); renderSummary(); }); }

        function clickDate(date) {
            if (state.busy || !state.roomId) return;
            if (date < today || beyond(date)) return;
            if (lockOf(date, state.meal)) return;
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
            render();
        }

        function chooseMeal(id) {
            // 他の食事で選んだ日はそのまま残す
            state.meal = id;
            render();
        }

        function post(date, roomId, meals) {
            var csrf = window.__csrfToken || cfg.csrfToken || '';
            // treservation_index.js の fetch は 409 で例外を投げて競合モーダルを出すので、元の fetch を使う
            var send = window.__fetchWithoutConflictModal || window.fetch;
            return send(cfg.directRegisterUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ date: date, roomId: roomId, meals: meals, userId: cfg.userId, noSwap: true })
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
            $('multiDateResult').hidden = true;
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
                        // 途中の食事だけ失敗しても、先の食事は登録済みで返る（data.failed）
                        var failedMeals = ok ? (data.failed || []) : [];
                        g.meals.forEach(function (mealId) {
                            var x = { date: g.date, meal: mealId, roomId: g.roomId };
                            var f = failedMeals.filter(function (v) { return Number(v.meal) === mealId; })[0];
                            if (!ok || f) { x.message = f ? String(f.message || '') : message; failed.push(x); return; }
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
                    // 背景への反映で例外が出ても、残りの日の🔒・選択の解除は続ける（選択が残ると二重に送るため）
                    try {
                        if (typeof window.__applyReservationToCalendar === 'function') {
                            window.__applyReservationToCalendar(x.date, x.meal, x.roomId, true);
                        }
                    } catch (e) { /* 背景の表示だけの問題。再読込で直る */ }
                });
                // スキップした日も、もう一度選んで同じスキップにならないよう🔒にする
                skipped.forEach(function (x) { state.skipLock[x.meal][x.date] = true; });
                // 失敗した分だけを選択に残し、もう一度「登録する」でやり直せるようにする
                done.concat(skipped).forEach(function (x) { delete state.selected[x.meal][x.date]; });
                // 選択を外した後に、背景のカレンダーをまとめて1回だけ取り直す
                if (done.length && window.__reservationCalendar && typeof window.__reservationCalendar.refetchEvents === 'function') {
                    window.__reservationCalendar.refetchEvents();
                }
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
                    var msgs = failed.map(function (f) { return f.message; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
                    parts.push('失敗 ' + failed.length + '件（' + list(failed) + '）：' + msgs.join(' / '));
                }
                finish(parts.join('。') || '登録 0件', failed.length ? 'danger' : (skipped.length ? 'warning' : 'success'));
            }).catch(function () {
                // 想定外の例外でも「登録中…」のまま固まらないようにする
                finish('登録の途中でエラーが発生しました。画面を再読み込みして、登録できたかを確かめてください。', 'danger');
            });
        }

        /** 結果をトーストと、モーダル内（トーストが消えても読めるように）に出して、操作できる状態に戻す */
        function finish(message, type) {
            toast(message, type);
            var box = $('multiDateResult');
            box.hidden = false;
            box.className = 'small mt-2 mb-0 alert py-2 alert-' + type;
            box.textContent = message;
            state.busy = false;
            state.progress = '';
            render();
        }

        modalEl.addEventListener('click', function (e) {
            var el = e.target.closest('[data-room],[data-meal],[data-date],[data-remove]');
            if (!el || state.busy) return;
            if (el.hasAttribute('data-remove')) {
                delete state.selected[Number(el.getAttribute('data-remove-meal'))][el.getAttribute('data-remove')];
                render();
            } else if (el.hasAttribute('data-room')) {
                state.roomId = Number(el.getAttribute('data-room'));
                render();
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
        $('multiDatePrev').addEventListener('click', function () {
            if (state.year * 12 + state.month <= minKey) return;
            state.month -= 1; if (state.month < 0) { state.month = 11; state.year -= 1; }
            renderCalendar();
        });
        $('multiDateNext').addEventListener('click', function () {
            if (state.year * 12 + state.month >= maxKey) return;
            state.month += 1; if (state.month > 11) { state.month = 0; state.year += 1; }
            renderCalendar();
        });
        $('multiDateSubmit').addEventListener('click', submit);
        // 閉じている間に背景の画面で登録・取消されても、開いたときの🔒が最新になるよう描き直す
        // スキップの🔒（別の部屋で登録済みなど）も、背景で取り消されたかもしれないので開き直したら外す
        modalEl.addEventListener('shown.bs.modal', function () {
            state.skipLock = { 1: {}, 2: {}, 3: {} };
            $('multiDateResult').hidden = true; // 前回の結果は、開き直したら出さない
            render();
            if (rooms.length === 0) toast('所属している部屋がありません。部屋を選択してください。', 'warning');
        });

        render();
        window.MultiDateReserve.state = state;
        window.MultiDateReserve.submit = submit;
    }

    window.MultiDateReserve = { lockReason: lockReason, monthCells: monthCells, isWithin14: isWithin14, init: init };
    document.addEventListener('DOMContentLoaded', init);
})();
