/**
 * Excelエクスポートモジュール
 * 食数予定表・実施食数表の Excel 出力機能を提供する。
 * treservation_toast.js の後にロードすること（pageToast を使用）。
 * ExcelJS (exceljs.min.js) の後にロードすること。
 */
(function () {
    var exportBtn = document.getElementById('exportNow');
    if (!exportBtn) return;

    function setExportLoading(loading) {
        var btn = document.getElementById('exportNow');
        var spn = document.getElementById('exportSpinner');
        if (!btn || !spn) return;
        btn.disabled = !!loading;
        spn.classList.toggle('d-none', !loading);
    }

    function showToast(message, type) {
        type = type || 'success';
        if (window.pageToast) { window.pageToast(message, type); return; }
        // pageToast が未ロードの場合のフォールバック
        var wrap = document.getElementById('toastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'toastWrap';
            wrap.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(wrap);
        }
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' +
            (type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'danger')) + ' border-0';
        toastEl.role = 'alert'; toastEl.ariaLive = 'assertive'; toastEl.ariaAtomic = 'true';
        toastEl.innerHTML = '<div class="d-flex"><div class="toast-body">' + String(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>';
        wrap.appendChild(toastEl);
        var t = window.bootstrap && window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3000 });
        if (t) t.show();
        toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
    }

    function setRangePreset(preset) {
        var from = document.getElementById('fromDate');
        var to   = document.getElementById('toDate');
        var chip = document.getElementById('rangeChip');
        if (!from || !to) return;

        var today = new Date(); today.setHours(0, 0, 0, 0);
        var firstDay = function (y, m) { return new Date(y, m, 1); };
        var lastDay  = function (y, m) { return new Date(y, m + 1, 0); };

        var s, e;
        switch (preset) {
            case 'this-week': {
                var d = new Date(today);
                var day = d.getDay();
                var mon = new Date(d); mon.setDate(d.getDate() - ((day + 6) % 7));
                var sun = new Date(mon); sun.setDate(mon.getDate() + 6);
                s = mon; e = sun; break;
            }
            case 'this-month': {
                s = firstDay(today.getFullYear(), today.getMonth());
                e = lastDay(today.getFullYear(), today.getMonth()); break;
            }
            case 'next-month': {
                var y = today.getFullYear(), m = today.getMonth() + 1;
                s = firstDay(y, m); e = lastDay(y, m); break;
            }
            case 'last-month': {
                var y2 = today.getFullYear(), m2 = today.getMonth() - 1;
                s = firstDay(y2, m2); e = lastDay(y2, m2); break;
            }
            default: return;
        }
        var fmt = function (d) { return d.toISOString().slice(0, 10); };
        from.value = fmt(s);
        to.value   = fmt(e);
        if (chip) chip.textContent = from.value + ' 〜 ' + to.value;
    }

    document.querySelectorAll('[data-range-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () { setRangePreset(btn.dataset.rangePreset); });
    });

    ['fromDate', 'toDate'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function () {
            var f = document.getElementById('fromDate') && document.getElementById('fromDate').value;
            var t = document.getElementById('toDate') && document.getElementById('toDate').value;
            if (f && t) {
                var chip = document.getElementById('rangeChip');
                if (chip) chip.textContent = f + ' 〜 ' + t;
            }
        });
    });

    async function downloadWorkbook(workbook, filename) {
        workbook.worksheets.forEach(function (ws) {
            ws.columns.forEach(function (col, idx) {
                var maxLen = 10;
                ws.eachRow({ includeEmpty: true }, function (row) {
                    var v = row.getCell(idx + 1).value;
                    if (v) {
                        var text = typeof v === 'object'
                            ? String(v.text || (v.richText ? v.richText.map(function (rt) { return rt.text; }).join('') : ''))
                            : String(v);
                        var len = Array.from(text).reduce(function (sum, ch) { return sum + (/[ -~]/.test(ch) ? 1 : 2); }, 0);
                        if (len > maxLen) maxLen = len;
                    }
                });
                col.width = maxLen + 2;
            });
        });
        var buffer = await workbook.xlsx.writeBuffer();
        var blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    }

    document.getElementById('exportNow').addEventListener('click', async function () {
        try {
            var csrfToken = (document.querySelector('meta[name="csrfToken"]') || {}).getAttribute('content') || '';
            var from = (document.getElementById('fromDate') || {}).value;
            var to   = (document.getElementById('toDate') || {}).value;
            if (!from || !to) { showToast('開始日・終了日を入力してください。', 'warning'); return; }
            if (from > to) { showToast('開始日は終了日以前の日付を指定してください。', 'warning'); return; }

            var isPlan   = document.getElementById('typePlan') && document.getElementById('typePlan').checked;
            var endpoint = isPlan ? window.__TRESP.exportJsonUrl : window.__TRESP.exportJsonRankUrl;

            setExportLoading(true);

            var res = await fetch(endpoint + '?from=' + from + '&to=' + to, { headers: { 'X-CSRF-Token': csrfToken } });
            if (!res.ok) throw new Error('APIエラー: ' + res.status);
            var raw = await res.json();
            if (raw && raw.ok === false) throw new Error(raw.message || 'APIエラー');

            var normalized = window.normalizeApiPayload ? window.normalizeApiPayload(raw) : raw;
            var json = (raw && typeof raw === 'object' && Object.prototype.hasOwnProperty.call(raw, 'ok'))
                ? (Object.prototype.hasOwnProperty.call(raw, 'data') ? raw.data : normalized)
                : raw;

            var isEmpty = (function () {
                if (isPlan) {
                    return !(json.rooms && Object.keys(json.rooms).length > 0) &&
                           !(json.overall && Object.keys(json.overall).length > 0);
                }
                return (Array.isArray(json) ? json : Object.values(json)).length === 0;
            })();
            if (isEmpty) { showToast('出力対象データがありません。', 'warning'); return; }

            if (isPlan) {
                var wb = new ExcelJS.Workbook();
                wb.creator = '食数予約システム'; wb.created = new Date(); wb.modified = new Date();

                var addHeader = function (sheet, withRoom) {
                    var header = withRoom
                        ? ['日付', '部屋名', '朝食', '昼食', '夕食', '弁当', '合計']
                        : ['日付', '朝食', '昼食', '夕食', '弁当', '合計'];
                    var row = sheet.addRow(header); row.font = { bold: true };
                    sheet.views = [{ state: 'frozen', ySplit: 1 }];
                };
                var addTotalRow = function (sheet, withRoom) {
                    var totals = [0, 0, 0, 0];
                    sheet.eachRow(function (row, i) {
                        if (i === 1) return;
                        var off = withRoom ? 2 : 1;
                        for (var k = 0; k < totals.length; k++) { totals[k] += Number(row.getCell(off + k + 1).value || 0); }
                    });
                    var grand = totals.reduce(function (a, b) { return a + b; }, 0);
                    var vals = withRoom ? ['合計', ''].concat(totals).concat([grand]) : ['合計'].concat(totals).concat([grand]);
                    var trow = sheet.addRow(vals); trow.font = { bold: true };
                    trow.eachCell(function (c) { c.border = { top: { style: 'thin' }, bottom: { style: 'double' } }; });
                };

                var hasRooms   = json.rooms   && Object.keys(json.rooms).length > 0;
                var hasOverall = json.overall  && Object.keys(json.overall).length > 0;

                var sh = wb.addWorksheet('全体'); addHeader(sh, true);
                if (hasRooms) {
                    var allDates = new Set(); var rooms = Object.keys(json.rooms).sort();
                    rooms.forEach(function (r) { Object.keys(json.rooms[r] || {}).forEach(function (d) { allDates.add(d); }); });
                    Array.from(allDates).sort().forEach(function (date) {
                        rooms.forEach(function (r) {
                            var c = (json.rooms[r] || {})[date] || {};
                            var total = (c['朝'] || 0) + (c['昼'] || 0) + (c['夜'] || 0) + (c['弁当'] || 0);
                            sh.addRow([date, r, c['朝'] || 0, c['昼'] || 0, c['夜'] || 0, c['弁当'] || 0, total]);
                        });
                    });
                } else if (hasOverall) {
                    Object.keys(json.overall).sort().forEach(function (date) {
                        var c = json.overall[date] || {};
                        var total = (c['朝'] || 0) + (c['昼'] || 0) + (c['夜'] || 0) + (c['弁当'] || 0);
                        sh.addRow([date, '全体', c['朝'] || 0, c['昼'] || 0, c['夜'] || 0, c['弁当'] || 0, total]);
                    });
                }
                addTotalRow(sh, true);

                if (hasRooms) {
                    Object.keys(json.rooms).forEach(function (room) {
                        var name = room.replace(/[:\\/?*[\]]/g, '').substring(0, 31) || '部屋';
                        var ws = wb.addWorksheet(name); addHeader(ws);
                        var rdata = json.rooms[room];
                        Object.keys(rdata).sort().forEach(function (date) {
                            var mc = rdata[date];
                            var total = (mc['朝'] || 0) + (mc['昼'] || 0) + (mc['夜'] || 0) + (mc['弁当'] || 0);
                            ws.addRow([date, mc['朝'] || 0, mc['昼'] || 0, mc['夜'] || 0, mc['弁当'] || 0, total]);
                        });
                        addTotalRow(ws);
                    });
                }

                await downloadWorkbook(wb, '食数予定表_' + from + '〜' + to + '.xlsx');
            } else {
                var rows = Array.isArray(json) ? json : Object.values(json);
                var wb2 = new ExcelJS.Workbook();
                var ws2 = wb2.addWorksheet('実施食数表');
                var cols = [
                    { key: 'reservation_date', header: '日付' },
                    { key: 'rank_name',        header: 'ランク' },
                    { key: 'gender',           header: '性別' },
                    { key: 'breakfast',        header: '朝食' },
                    { key: 'lunch',            header: '昼食' },
                    { key: 'dinner',           header: '夕食' },
                    { key: 'bento',            header: '弁当' },
                    { key: 'total_eaters',     header: '合計' }
                ];
                ws2.addRow(cols.map(function (c) { return c.header; })).font = { bold: true };
                rows.forEach(function (r) { ws2.addRow(cols.map(function (c) { return r[c.key] !== undefined ? r[c.key] : ''; })); });

                ws2.columns.forEach(function (col, idx) {
                    var maxLen = 10;
                    ws2.eachRow({ includeEmpty: true }, function (row) {
                        var v = row.getCell(idx + 1).value;
                        if (v) {
                            var text = typeof v === 'object'
                                ? String(v.text || (v.richText ? v.richText.map(function (rt) { return rt.text; }).join('') : ''))
                                : String(v);
                            var len = Array.from(text).reduce(function (sum, ch) { return sum + (/[ -~]/.test(ch) ? 1 : 2); }, 0);
                            if (len > maxLen) maxLen = len;
                        }
                    });
                    col.width = maxLen + 2;
                });

                var buffer2 = await wb2.xlsx.writeBuffer();
                var blob2 = new Blob([buffer2], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                var a2 = document.createElement('a');
                a2.href = URL.createObjectURL(blob2); a2.download = '実施食数表_' + from + '〜' + to + '.xlsx';
                document.body.appendChild(a2); a2.click(); document.body.removeChild(a2);
                URL.revokeObjectURL(a2.href);
            }

            showToast('エクスポートが完了しました。', 'success');
        } catch (err) {
            console.error(err);
            var msg = 'エクスポートに失敗しました。';
            if (err && err.message) msg += '\n' + err.message;
            showToast(msg, 'danger');
        } finally {
            setExportLoading(false);
        }
    });
})();
