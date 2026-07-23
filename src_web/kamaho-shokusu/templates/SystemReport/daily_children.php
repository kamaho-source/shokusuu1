<?php
/** @var \App\View\AppView $this */
/** @var array<array{user_id:int, user_name:string, is_child:bool}> $allUsers */
/** @var array<int> $excludeUserIds */
$allUsers       = $allUsers       ?? [];
$excludeUserIds = $excludeUserIds ?? [];
$basePath       = rtrim($this->request->getAttribute('base') ?? '', '/');
$dataUrl        = $basePath . '/SystemReport/dailyChildrenData';
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
.chart-wrap { position: relative; height: 380px; }
.exclude-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; max-height: 160px; overflow-y: auto; padding: 4px; }
.exclude-item { display: flex; align-items: center; gap: 4px; font-size: .82rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 3px 8px; cursor: pointer; user-select: none; }
.exclude-item.active { background: #f8d7da; border-color: #f5c6cb; }
.exclude-item .badge-child { font-size: .65rem; background: #0d6efd; color: #fff; border-radius: 4px; padding: 1px 4px; }
.exclude-item .badge-adult { font-size: .65rem; background: #198754; color: #fff; border-radius: 4px; padding: 1px 4px; }
.sub-nav { display: flex; gap: 8px; margin-bottom: 20px; }
#loadingOverlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,.6); z-index: 9999; align-items: center; justify-content: center; }
#loadingOverlay.show { display: flex; }
</style>

<div id="loadingOverlay"><div class="spinner-border text-primary"></div></div>

<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">システムレポート — 日別子供総数</h1>
            <div class="page-subtitle">日ごとの子供の予約件数を集計します。システム管理者専用。</div>
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
        <span class="btn btn-primary btn-sm disabled" aria-disabled="true">
            <i class="bi bi-graph-up"></i> 日別子供総数
        </span>
        <a href="<?= h($basePath) ?>/SystemReport/loginReport" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person-check"></i> ログイン情報
        </a>
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

        <!-- 除外ユーザー（子供のみ表示） -->
        <div class="mt-3">
            <div class="filter-title">集計から除外するユーザー（子供）
                <span class="text-muted fw-normal small">(クリックで選択)</span>
            </div>
            <div class="exclude-list" id="excludeList">
                <?php foreach ($allUsers as $u): ?>
                    <?php if (!$u['is_child']) continue; ?>
                    <?php $checked = in_array($u['user_id'], $excludeUserIds, true); ?>
                    <label class="exclude-item <?= $checked ? 'active' : '' ?>"
                           data-uid="<?= (int)$u['user_id'] ?>">
                        <input type="checkbox" value="<?= (int)$u['user_id'] ?>"
                               <?= $checked ? 'checked' : '' ?> hidden>
                        <span class="badge-child">子</span>
                        <?= h($u['user_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-2 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" id="btnClearExclude">除外をリセット</button>
                <span class="text-muted small align-self-center" id="excludeCount">
                    除外中: <?= count($excludeUserIds) ?> 人
                </span>
            </div>
        </div>
    </div>

    <!-- グラフ -->
    <div class="mui-paper">
        <div class="section-title">日別 子供 予約総数</div>
        <div class="chart-wrap">
            <canvas id="chartDaily"></canvas>
        </div>
        <div id="noDataMsg" class="text-center text-muted py-4" style="display:none">
            集計対象データがありません。
        </div>
    </div>

    <!-- テーブル -->
    <div class="mui-paper">
        <div class="section-title">日別 詳細データ</div>
        <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
            <table class="table table-sm table-hover">
                <thead class="table-dark" style="position:sticky;top:0;z-index:1;">
                    <tr>
                        <th>日付</th>
                        <th class="text-end">子供 予約件数</th>
                    </tr>
                </thead>
                <tbody id="dailyTableBody">
                    <tr><td colspan="2" class="text-center text-muted py-3">読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const DATA_URL   = <?= json_encode($dataUrl) ?>;
    const CSRF_TOKEN = document.querySelector('meta[name="csrfToken"]')?.content ?? '';
    let chartDaily   = null;
    let currentStats = [];

    const excludeList  = document.getElementById('excludeList');
    const excludeCount = document.getElementById('excludeCount');

    function getExcludeIds() {
        return [...excludeList.querySelectorAll('input[type=checkbox]:checked')]
            .map(cb => parseInt(cb.value, 10));
    }
    function updateExcludeCount() {
        excludeCount.textContent = `除外中: ${getExcludeIds().length} 人`;
    }
    excludeList.addEventListener('click', e => {
        const item = e.target.closest('.exclude-item');
        if (!item) return;
        const cb = item.querySelector('input');
        cb.checked = !cb.checked;
        item.classList.toggle('active', cb.checked);
        updateExcludeCount();
    });
    document.getElementById('btnClearExclude').addEventListener('click', () => {
        excludeList.querySelectorAll('.exclude-item').forEach(item => {
            item.querySelector('input').checked = false;
            item.classList.remove('active');
        });
        updateExcludeCount();
    });

    async function fetchStats() {
        const dateFrom   = document.getElementById('dateFrom').value;
        const dateTo     = document.getElementById('dateTo').value;
        const excludeIds = getExcludeIds();
        const url = new URL(DATA_URL, location.origin);
        url.searchParams.set('date_from', dateFrom);
        url.searchParams.set('date_to', dateTo);
        excludeIds.forEach(id => url.searchParams.append('exclude[]', id));

        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.add('show');
        try {
            const res  = await fetch(url.toString(), { headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Accept': 'application/json' } });
            const json = await res.json();
            if (!res.ok) throw new Error(json.error ?? 'エラーが発生しました');
            return json.stats ?? [];
        } finally {
            overlay.classList.remove('show');
        }
    }

    function renderChart(stats) {
        const canvas    = document.getElementById('chartDaily');
        const noDataMsg = document.getElementById('noDataMsg');
        if (chartDaily) { chartDaily.destroy(); chartDaily = null; }

        if (!stats.length || stats.every(d => d.child_count === 0)) {
            canvas.style.display = 'none';
            noDataMsg.style.display = '';
            return;
        }
        canvas.style.display = '';
        noDataMsg.style.display = 'none';

        chartDaily = new Chart(canvas, {
            type: 'line',
            data: {
                labels: stats.map(d => d.date),
                datasets: [{
                    label: '子供 予約件数',
                    data: stats.map(d => d.child_count),
                    borderColor: 'rgba(13, 110, 253, 0.9)',
                    backgroundColor: 'rgba(13, 110, 253, 0.15)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: stats.length <= 31 ? 4 : 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { mode: 'index', intersect: false },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    x: { ticks: { maxRotation: 45, maxTicksLimit: 31 } },
                },
            },
        });
    }

    function renderTable(stats) {
        const tbody = document.getElementById('dailyTableBody');
        if (!stats.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">データなし</td></tr>';
            return;
        }
        tbody.innerHTML = stats.map(d => `
            <tr>
                <td>${escHtml(d.date)}</td>
                <td class="text-end fw-bold">${d.child_count}</td>
            </tr>
        `).join('');
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.getElementById('btnExcel').addEventListener('click', async () => {
        if (!currentStats.length) return;
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo   = document.getElementById('dateTo').value;
        const btn      = document.getElementById('btnExcel');
        btn.disabled = true; btn.textContent = '出力中...';
        try {
            const wb = new ExcelJS.Workbook();
            wb.creator = '日別子供総数'; wb.created = new Date();

            const ws = wb.addWorksheet('日別子供総数');
            const hdr = ws.addRow(['日付', '子供 予約件数']);
            hdr.eachCell(cell => {
                cell.font  = { bold:true, color:{ argb:'FFFFFFFF' } };
                cell.fill  = { type:'pattern', pattern:'solid', fgColor:{ argb:'FF0d6efd' } };
                cell.alignment = { horizontal:'center' };
            });
            currentStats.forEach((d, i) => {
                const row = ws.addRow([d.date, d.child_count]);
                if (i % 2 === 1) row.eachCell(c => { c.fill = { type:'pattern', pattern:'solid', fgColor:{ argb:'FFF2F2F2' } }; });
                row.getCell(2).alignment = { horizontal:'right' };
            });
            ws.getColumn(1).width = 16; ws.getColumn(2).width = 16;

            const gs = wb.addWorksheet('グラフ');
            gs.addRow(['日別 子供 予約総数']).getCell(1).font = { bold:true, size:13 };
            gs.addRow([`集計期間: ${dateFrom} ～ ${dateTo}`]).getCell(1).font = { color:{ argb:'FF666666' } };
            gs.addRow([]);
            const imgId = wb.addImage({ base64: document.getElementById('chartDaily').toDataURL('image/png').replace('data:image/png;base64,',''), extension:'png' });
            gs.addImage(imgId, { tl:{ col:0, row:3 }, ext:{ width:900, height:380 } });
            for (let i=0;i<22;i++) gs.addRow([]);

            const buf = await wb.xlsx.writeBuffer();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([buf], { type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
            a.download = `daily_children_${dateFrom}_${dateTo}.xlsx`;
            a.click();
        } catch(e) { alert('Excel出力エラー: '+e.message); }
        finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-excel"></i> Excel出力'; }
    });

    async function applyStats() {
        try {
            const stats = await fetchStats();
            currentStats = stats;
            renderChart(stats);
            renderTable(stats);
            document.getElementById('btnExcel').disabled = stats.length === 0;
        } catch(e) { alert('集計エラー: '+e.message); }
    }

    document.getElementById('btnApply').addEventListener('click', applyStats);

    // ページ表示時に自動集計
    document.addEventListener('DOMContentLoaded', applyStats);
})();
</script>
