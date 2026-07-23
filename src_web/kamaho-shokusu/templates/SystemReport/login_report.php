<?php
/** @var \App\View\AppView $this */
$basePath = rtrim($this->request->getAttribute('base') ?? '', '/');
$dataUrl  = $basePath . '/SystemReport/loginReportData';
?>
<?= $this->Html->script('https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', ['block' => true]) ?>
<?= $this->Html->script('https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js', ['block' => true]) ?>

<style>
.page-shell { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
.page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
.page-title { font-size: 1.4rem; font-weight: 700; margin: 0; }
.page-subtitle { font-size: .85rem; color: #6c757d; margin-top: 2px; }
.mui-paper { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 20px; margin-bottom: 20px; }
.filter-title { font-size: .8rem; font-weight: 600; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }
.section-title { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
.chart-wrap { position: relative; }
.sub-nav { display: flex; gap: 8px; margin-bottom: 20px; }
#loadingOverlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,.6); z-index: 9999; align-items: center; justify-content: center; }
#loadingOverlay.show { display: flex; }
</style>

<div id="loadingOverlay"><div class="spinner-border text-primary"></div></div>

<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">システムレポート — ログイン情報</h1>
            <div class="page-subtitle">システムへのログイン履歴を表示します。システム管理者専用。</div>
        </div>
        <div class="d-flex gap-2">
            <button id="btnExcel" class="btn btn-success btn-sm" disabled>
                <i class="bi bi-file-earmark-excel"></i> Excel出力
            </button>
            <a href="<?= h($basePath) ?>/" class="btn btn-outline-secondary btn-sm">戻る</a>
        </div>
    </div>

    <!-- サブナビ -->
    <div class="sub-nav">
        <a href="<?= h($basePath) ?>/SystemReport" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-building"></i> 部屋別使用率
        </a>
        <a href="<?= h($basePath) ?>/SystemReport/dailyChildren" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-graph-up"></i> 日別子供総数
        </a>
        <span class="btn btn-secondary btn-sm disabled" aria-disabled="true">
            <i class="bi bi-person-check"></i> ログイン情報
        </span>
    </div>

    <!-- フィルター -->
    <div class="mui-paper">
        <div class="filter-title">集計条件</div>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1 small">開始日</label>
                <input type="date" id="dateFrom" class="form-control form-control-sm"
                       value="<?= h(date('Y-m-01')) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1 small">終了日</label>
                <input type="date" id="dateTo" class="form-control form-control-sm"
                       value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-12 col-md-2">
                <button id="btnApply" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search"></i> 集計
                </button>
            </div>
        </div>
    </div>

    <!-- サマリーカード -->
    <div class="row g-3 mb-3" id="summaryCards" style="display:none!important;">
        <div class="col-6 col-md-4">
            <div class="mui-paper text-center py-3">
                <div class="text-muted small">ログイン成功 総数</div>
                <div class="fs-3 fw-bold text-success" id="cardTotal">—</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="mui-paper text-center py-3">
                <div class="text-muted small">ユニークユーザー数</div>
                <div class="fs-3 fw-bold text-primary" id="cardUsers">—</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="mui-paper text-center py-3">
                <div class="text-muted small">集計日数</div>
                <div class="fs-3 fw-bold" id="cardDays">—</div>
            </div>
        </div>
    </div>

    <!-- グラフ: ユーザー別ログイン回数 -->
    <div class="mui-paper">
        <div class="section-title">ユーザー別 ログイン回数</div>
        <div class="chart-wrap" id="chartWrap">
            <canvas id="chartLogin"></canvas>
        </div>
        <div id="noDataMsg" class="text-center text-muted py-4" style="display:none">
            対象期間のログインデータがありません。
        </div>
    </div>

    <!-- テーブル: ユーザー別ログイン集計 -->
    <div class="mui-paper">
        <div class="section-title">ユーザー別 ログイン集計
            <span class="text-muted fw-normal small ms-2" id="userCount"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ユーザー名</th>
                        <th>ログインID</th>
                        <th class="text-end">ログイン回数</th>
                        <th>最終ログイン</th>
                    </tr>
                </thead>
                <tbody id="userTableBody">
                    <tr><td colspan="4" class="text-center text-muted py-3">読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- テーブル: ログイン履歴 -->
    <div class="mui-paper">
        <div class="section-title">ログイン履歴
            <span class="text-muted fw-normal small ms-2" id="logCount"></span>
        </div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
            <table class="table table-sm table-hover">
                <thead class="table-dark" style="position:sticky;top:0;z-index:1;">
                    <tr>
                        <th>日時</th>
                        <th>ユーザー名</th>
                        <th>ログインID</th>
                        <th>IPアドレス</th>
                    </tr>
                </thead>
                <tbody id="logTableBody">
                    <tr><td colspan="4" class="text-center text-muted py-3">読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const DATA_URL   = <?= json_encode($dataUrl) ?>;
    const CSRF_TOKEN = document.querySelector('meta[name="csrfToken"]')?.content ?? '';
    let chartLogin   = null;
    let currentDaily = [];
    let currentLogs  = [];   // 成功ログのみ
    let currentUsers = [];

    async function fetchStats() {
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo   = document.getElementById('dateTo').value;
        const url = new URL(DATA_URL, location.origin);
        url.searchParams.set('date_from', dateFrom);
        url.searchParams.set('date_to', dateTo);

        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.add('show');
        try {
            const res  = await fetch(url.toString(), { headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Accept': 'application/json' } });
            const json = await res.json();
            if (!res.ok) throw new Error(json.error ?? 'エラーが発生しました');
            return json;
        } finally {
            overlay.classList.remove('show');
        }
    }

    // ログから成功ログのみ抽出してユーザー別に集計
    function buildUserStats(logs) {
        const successLogs = logs.filter(l => l.result === 1);
        const map = new Map();
        for (const l of successLogs) {
            const key = l.login_id || l.user_name;
            if (!map.has(key)) {
                map.set(key, { user_name: l.user_name, login_id: l.login_id, count: 0, last_dt: '' });
            }
            const u = map.get(key);
            u.count++;
            if (!u.last_dt || l.dt > u.last_dt) { u.last_dt = l.dt; }
        }
        return [...map.values()].sort((a, b) => b.count - a.count);
    }

    function renderSummary(daily, users, successLogs) {
        document.getElementById('cardTotal').textContent = successLogs.length;
        document.getElementById('cardUsers').textContent = users.length;
        document.getElementById('cardDays').textContent  = daily.length;
        document.getElementById('summaryCards').style.setProperty('display', 'flex', 'important');
    }

    function renderChart(users) {
        const canvas    = document.getElementById('chartLogin');
        const chartWrap = document.getElementById('chartWrap');
        const noDataMsg = document.getElementById('noDataMsg');
        if (chartLogin) { chartLogin.destroy(); chartLogin = null; }

        if (!users.length) {
            canvas.style.display = 'none';
            noDataMsg.style.display = '';
            return;
        }
        canvas.style.display = '';
        noDataMsg.style.display = 'none';

        // ユーザー数に応じて高さを動的に設定（最低 280px、1人あたり 36px）
        const height = Math.max(280, users.length * 36);
        chartWrap.style.height = height + 'px';

        chartLogin = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: users.map(u => u.user_name),
                datasets: [{
                    label: 'ログイン回数',
                    data: users.map(u => u.count),
                    backgroundColor: 'rgba(13, 110, 253, 0.7)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => `ログイン回数: ${ctx.parsed.x} 回`,
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'ログイン回数' } },
                    y: { ticks: { font: { size: 12 } } },
                },
            },
        });
    }

    function renderUserTable(users) {
        const tbody     = document.getElementById('userTableBody');
        const userCount = document.getElementById('userCount');
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">データなし</td></tr>';
            userCount.textContent = '';
            return;
        }
        userCount.textContent = `(${users.length} 人)`;
        tbody.innerHTML = users.map(u => `
            <tr>
                <td class="fw-bold">${escHtml(u.user_name)}</td>
                <td class="text-muted small">${escHtml(u.login_id)}</td>
                <td class="text-end"><span class="badge bg-primary">${u.count} 回</span></td>
                <td class="text-nowrap text-muted small">${escHtml(u.last_dt)}</td>
            </tr>
        `).join('');
    }

    function renderLogTable(successLogs) {
        const tbody = document.getElementById('logTableBody');
        document.getElementById('logCount').textContent = `(${successLogs.length} 件)`;
        if (!successLogs.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">データなし</td></tr>';
            return;
        }
        tbody.innerHTML = successLogs.map(l => `
            <tr>
                <td class="text-nowrap">${escHtml(l.dt)}</td>
                <td>${escHtml(l.user_name)}</td>
                <td>${escHtml(l.login_id)}</td>
                <td class="text-nowrap text-muted small">${escHtml(l.ip)}</td>
            </tr>
        `).join('');
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ---------- Excel出力 ----------
    document.getElementById('btnExcel').addEventListener('click', async () => {
        if (!currentLogs.length) return;
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo   = document.getElementById('dateTo').value;
        const btn      = document.getElementById('btnExcel');
        btn.disabled = true; btn.textContent = '出力中...';
        try {
            const wb = new ExcelJS.Workbook();
            wb.creator = 'ログイン情報'; wb.created = new Date();

            // ユーザー別集計シート
            const us = wb.addWorksheet('ユーザー別集計');
            const uh = us.addRow(['ユーザー名', 'ログインID', 'ログイン回数', '最終ログイン']);
            uh.eachCell(cell => {
                cell.font  = { bold:true, color:{ argb:'FFFFFFFF' } };
                cell.fill  = { type:'pattern', pattern:'solid', fgColor:{ argb:'FF0d6efd' } };
                cell.alignment = { horizontal:'center' };
            });
            currentUsers.forEach((u, i) => {
                const row = us.addRow([u.user_name, u.login_id, u.count, u.last_dt]);
                if (i % 2 === 1) row.eachCell(c => { c.fill = { type:'pattern', pattern:'solid', fgColor:{ argb:'FFF2F2F2' } }; });
                row.getCell(3).alignment = { horizontal:'right' };
            });
            [20, 20, 14, 22].forEach((w,i) => us.getColumn(i+1).width = w);

            // グラフシート
            const gs = wb.addWorksheet('グラフ');
            gs.addRow(['ユーザー別 ログイン回数']).getCell(1).font = { bold:true, size:13 };
            gs.addRow([`集計期間: ${dateFrom} ～ ${dateTo}`]).getCell(1).font = { color:{ argb:'FF666666' } };
            gs.addRow([]);
            const chartHeight = Math.max(320, currentUsers.length * 36);
            const imgId = wb.addImage({
                base64: document.getElementById('chartLogin').toDataURL('image/png').replace('data:image/png;base64,',''),
                extension: 'png',
            });
            gs.addImage(imgId, { tl:{ col:0, row:3 }, ext:{ width:700, height:chartHeight } });
            const rowCount = Math.ceil(chartHeight / 20) + 4;
            for (let i = 0; i < rowCount; i++) gs.addRow([]);

            // ログイン履歴シート
            const ls = wb.addWorksheet('ログイン履歴');
            const lh = ls.addRow(['日時', 'ユーザー名', 'ログインID', 'IPアドレス']);
            lh.eachCell(cell => {
                cell.font  = { bold:true, color:{ argb:'FFFFFFFF' } };
                cell.fill  = { type:'pattern', pattern:'solid', fgColor:{ argb:'FF343a40' } };
            });
            currentLogs.forEach((l, i) => {
                const row = ls.addRow([l.dt, l.user_name, l.login_id, l.ip]);
                if (i % 2 === 1) row.eachCell(c => { c.fill = { type:'pattern', pattern:'solid', fgColor:{ argb:'FFF2F2F2' } }; });
            });
            [22, 20, 20, 18].forEach((w,i) => ls.getColumn(i+1).width = w);

            const buf = await wb.xlsx.writeBuffer();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([buf], { type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
            a.download = `login_report_${dateFrom}_${dateTo}.xlsx`;
            a.click();
        } catch(e) { alert('Excel出力エラー: '+e.message); }
        finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-excel"></i> Excel出力'; }
    });

    async function applyStats() {
        try {
            const json       = await fetchStats();
            currentDaily     = json.daily ?? [];
            const allLogs    = json.logs  ?? [];
            currentLogs      = allLogs.filter(l => l.result === 1);
            currentUsers     = buildUserStats(allLogs);
            renderSummary(currentDaily, currentUsers, currentLogs);
            renderChart(currentUsers);
            renderUserTable(currentUsers);
            renderLogTable(currentLogs);
            document.getElementById('btnExcel').disabled = currentLogs.length === 0;
        } catch(e) { alert('集計エラー: '+e.message); }
    }

    document.getElementById('btnApply').addEventListener('click', applyStats);

    // ページ表示時に自動集計
    document.addEventListener('DOMContentLoaded', applyStats);
})();
</script>
