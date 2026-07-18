<?php
/** @var \App\View\AppView $this */
/** @var array<array{user_id:int, user_name:string}> $allUsers */
/** @var array<int> $excludeUserIds */
$allUsers       = $allUsers       ?? [];
$excludeUserIds = $excludeUserIds ?? [];
$basePath       = rtrim($this->request->getAttribute('base') ?? '', '/');
$dataUrl        = $basePath . '/SystemReport/data';
?>
<?= $this->Html->script('https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', ['block' => true]) ?>
<?= $this->Html->script('https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js', ['block' => true]) ?>

<style>
.page-shell { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
.page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
.page-title { font-size: 1.4rem; font-weight: 700; margin: 0; }
.page-subtitle { font-size: .85rem; color: #6c757d; margin-top: 2px; }
.mui-paper { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 20px; margin-bottom: 20px; }
.filter-title { font-size: .8rem; font-weight: 600; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }
.section-title { font-size: 1rem; font-weight: 700; margin-bottom: 12px; }
.chart-wrap { position: relative; height: 420px; }
.exclude-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; max-height: 180px; overflow-y: auto; padding: 4px; }
.exclude-item { display: flex; align-items: center; gap: 4px; font-size: .85rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 3px 8px; cursor: pointer; user-select: none; transition: background .15s; }
.exclude-item.active { background: #f8d7da; border-color: #f5c6cb; }
.exclude-item input[type=checkbox] { accent-color: #dc3545; }
.stat-badge { display: inline-block; min-width: 28px; text-align: center; }
#loadingOverlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,.6); z-index: 9999; align-items: center; justify-content: center; }
#loadingOverlay.show { display: flex; }
</style>

<div id="loadingOverlay"><div class="spinner-border text-primary"></div></div>

<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">システムレポート</h1>
            <div class="page-subtitle">ユーザーごとの予約数・使用数を集計します。システム管理者専用。</div>
        </div>
        <div class="d-flex gap-2">
            <button id="btnExcel" class="btn btn-success btn-sm" disabled>
                <i class="bi bi-file-earmark-excel"></i> Excel出力
            </button>
            <a href="<?= h($basePath) ?>/" class="btn btn-outline-secondary btn-sm">戻る</a>
        </div>
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

        <!-- 集計除外ユーザー -->
        <div class="mt-3">
            <div class="filter-title">集計から除外するユーザー <span class="text-muted fw-normal">(クリックで選択)</span></div>
            <div class="exclude-list" id="excludeList">
                <?php foreach ($allUsers as $u): ?>
                    <?php $checked = in_array($u['user_id'], $excludeUserIds, true); ?>
                    <label class="exclude-item <?= $checked ? 'active' : '' ?>"
                           data-uid="<?= (int)$u['user_id'] ?>">
                        <input type="checkbox" value="<?= (int)$u['user_id'] ?>"
                               <?= $checked ? 'checked' : '' ?> hidden>
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
        <div class="section-title">予約数・使用数グラフ</div>
        <div class="chart-wrap">
            <canvas id="reportChart"></canvas>
        </div>
        <div id="noDataMsg" class="text-center text-muted py-4" style="display:none">
            集計対象データがありません。
        </div>
    </div>

    <!-- テーブル -->
    <div class="mui-paper">
        <div class="section-title">詳細テーブル</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="reportTable">
                <thead class="table-dark">
                    <tr>
                        <th>ユーザー名</th>
                        <th class="text-end">予約数</th>
                        <th class="text-end">使用数（承認済）</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <tr><td colspan="3" class="text-center text-muted py-3">「集計」ボタンを押してください</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const DATA_URL    = <?= json_encode($dataUrl) ?>;
    const CSRF_TOKEN  = document.querySelector('meta[name="csrfToken"]')?.content ?? '';

    let chart         = null;
    let currentStats  = [];

    // ---------- 除外ユーザー管理 ----------
    const excludeList  = document.getElementById('excludeList');
    const excludeCount = document.getElementById('excludeCount');

    function getExcludeIds() {
        return [...excludeList.querySelectorAll('input[type=checkbox]:checked')]
            .map(cb => parseInt(cb.value, 10));
    }

    function updateExcludeCount() {
        const n = getExcludeIds().length;
        excludeCount.textContent = `除外中: ${n} 人`;
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

    // ---------- 集計データ取得 ----------
    async function fetchStats() {
        const dateFrom    = document.getElementById('dateFrom').value;
        const dateTo      = document.getElementById('dateTo').value;
        const excludeIds  = getExcludeIds();

        const url = new URL(DATA_URL, location.origin);
        url.searchParams.set('date_from', dateFrom);
        url.searchParams.set('date_to', dateTo);
        excludeIds.forEach(id => url.searchParams.append('exclude[]', id));

        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.add('show');
        try {
            const res  = await fetch(url.toString(), {
                headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Accept': 'application/json' }
            });
            const json = await res.json();
            if (!res.ok) throw new Error(json.error ?? 'エラーが発生しました');
            return json.stats ?? [];
        } finally {
            overlay.classList.remove('show');
        }
    }

    // ---------- グラフ描画 ----------
    function renderChart(stats) {
        const noDataMsg = document.getElementById('noDataMsg');
        const canvas    = document.getElementById('reportChart');

        if (chart) { chart.destroy(); chart = null; }

        if (!stats.length) {
            canvas.style.display = 'none';
            noDataMsg.style.display = '';
            return;
        }
        canvas.style.display = '';
        noDataMsg.style.display = 'none';

        const labels   = stats.map(s => s.user_name);
        const reserves = stats.map(s => s.reservation_count);
        const usages   = stats.map(s => s.usage_count);

        chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: '予約数',
                        data: reserves,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor:     'rgba(54, 162, 235, 1)',
                        borderWidth: 1,
                    },
                    {
                        label: '使用数（承認済）',
                        data: usages,
                        backgroundColor: 'rgba(75, 192, 192, 0.7)',
                        borderColor:     'rgba(75, 192, 192, 1)',
                        borderWidth: 1,
                    },
                ],
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
                    x: { ticks: { maxRotation: 45, minRotation: 0, autoSkip: false } },
                },
            },
        });
    }

    // ---------- テーブル描画 ----------
    function renderTable(stats) {
        const tbody = document.getElementById('reportTableBody');
        if (!stats.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">データなし</td></tr>';
            return;
        }
        tbody.innerHTML = stats.map(s => `
            <tr>
                <td>${escHtml(s.user_name)}</td>
                <td class="text-end">${s.reservation_count}</td>
                <td class="text-end">${s.usage_count}</td>
            </tr>
        `).join('');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---------- Excel出力（SheetJS） ----------
    document.getElementById('btnExcel').addEventListener('click', () => {
        if (!currentStats.length) return;

        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo   = document.getElementById('dateTo').value;

        const wsData = [
            ['ユーザー名', '予約数', '使用数（承認済）'],
            ...currentStats.map(s => [s.user_name, s.reservation_count, s.usage_count]),
        ];

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(wsData);

        // 列幅設定
        ws['!cols'] = [{ wch: 30 }, { wch: 10 }, { wch: 18 }];

        XLSX.utils.book_append_sheet(wb, ws, 'システムレポート');

        // 集計情報シート
        const infoData = [
            ['集計期間', `${dateFrom} ～ ${dateTo}`],
            ['出力日時', new Date().toLocaleString('ja-JP')],
            ['総ユーザー数', currentStats.length],
        ];
        const wsInfo = XLSX.utils.aoa_to_sheet(infoData);
        wsInfo['!cols'] = [{ wch: 14 }, { wch: 30 }];
        XLSX.utils.book_append_sheet(wb, wsInfo, '集計情報');

        XLSX.writeFile(wb, `system_report_${dateFrom}_${dateTo}.xlsx`);
    });

    // ---------- 集計ボタン ----------
    document.getElementById('btnApply').addEventListener('click', async () => {
        try {
            const stats = await fetchStats();
            currentStats = stats;
            renderChart(stats);
            renderTable(stats);
            document.getElementById('btnExcel').disabled = stats.length === 0;
        } catch (err) {
            alert('集計中にエラーが発生しました: ' + err.message);
        }
    });
})();
</script>
