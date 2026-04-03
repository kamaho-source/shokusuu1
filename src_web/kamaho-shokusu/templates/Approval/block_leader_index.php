<?php
/** @var \App\Controller\ApprovalController $this */
$user          = $this->request->getAttribute('identity');
$records       = $records ?? [];
$rooms         = $rooms ?? [];
$filterRoomId  = $filterRoomId ?? '';
$filterStatus  = $filterStatus ?? '';
$dateFrom      = $dateFrom ?? date('Y-m-d', strtotime('monday this week'));
$dateTo        = $dateTo   ?? date('Y-m-d', strtotime('sunday this week'));

$statusLabels = [
    0 => ['label' => '未承認',           'class' => 'bg-warning text-dark'],
    1 => ['label' => 'ブロック長承認済', 'class' => 'bg-info text-dark'],
    2 => ['label' => '管理者承認済',     'class' => 'bg-success text-white'],
    3 => ['label' => '差し戻し',         'class' => 'bg-danger text-white'],
];
$mealLabels = [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁当'];
$basePath = $this->request->getAttribute('base') ?? '';
$pendingCount = 0;
$rejectedCount = 0;
foreach ($records as $record) {
    $status = (int)($record->i_approval_status ?? 0);
    if ($status === 0) {
        $pendingCount++;
    } elseif ($status === 3) {
        $rejectedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>承認一覧（ブロック長）</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --mui-bg: #f4f7fb;
            --mui-surface: #ffffff;
            --mui-surface-soft: #f8fafc;
            --mui-border: #dbe3ef;
            --mui-border-strong: #b7c4d6;
            --mui-text: #142033;
            --mui-text-sub: #5b6b82;
            --mui-primary: #1976d2;
            --mui-primary-soft: #e9f3ff;
            --mui-success: #2e7d32;
            --mui-warning: #ed6c02;
            --mui-danger: #d32f2f;
            --mui-shadow: 0 14px 38px rgba(15, 23, 42, 0.08);
        }
        body {
            background:
                radial-gradient(circle at top left, rgba(25,118,210,.08), transparent 26%),
                linear-gradient(180deg, #f8fbff 0%, var(--mui-bg) 100%);
            color: var(--mui-text);
        }
        .page-shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 28px 20px 40px;
        }
        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
        }
        .page-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .page-subtitle {
            margin-top: 6px;
            color: var(--mui-text-sub);
            font-size: .95rem;
        }
        .mui-paper {
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(219,227,239,.95);
            border-radius: 24px;
            box-shadow: var(--mui-shadow);
            backdrop-filter: blur(10px);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .summary-card {
            padding: 18px 20px;
        }
        .summary-label {
            color: var(--mui-text-sub);
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .summary-value {
            margin-top: 10px;
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        .summary-note {
            margin-top: 8px;
            color: var(--mui-text-sub);
            font-size: .84rem;
        }
        .summary-card.primary { background: linear-gradient(135deg, rgba(25,118,210,.10), rgba(25,118,210,.04)); }
        .summary-card.warning { background: linear-gradient(135deg, rgba(237,108,2,.10), rgba(237,108,2,.04)); }
        .summary-card.soft { background: linear-gradient(135deg, rgba(15,23,42,.04), rgba(15,23,42,.02)); }
        .filter-paper {
            padding: 18px 20px 12px;
            margin-bottom: 18px;
        }
        .filter-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .filter-form .form-label {
            color: var(--mui-text-sub);
            font-weight: 700;
        }
        .filter-form .form-select,
        .filter-form .form-control {
            min-height: 40px;
            border-radius: 12px;
            border-color: var(--mui-border);
            background: #fff;
            box-shadow: none;
        }
        .table-paper {
            padding: 10px;
        }
        .table-toolbar {
            padding: 10px 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .table-toolbar-title {
            font-size: 1rem;
            font-weight: 700;
        }
        .table-toolbar-subtitle {
            color: var(--mui-text-sub);
            font-size: .84rem;
            margin-top: 4px;
        }
        .approval-table {
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .approval-table thead th {
            position: sticky;
            top: 0;
            background: #f6f9fc;
            color: var(--mui-text-sub);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid var(--mui-border);
            padding: 14px 12px;
        }
        .approval-table tbody td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }
        .approval-table tbody tr:hover {
            background: #fbfdff;
        }
        .cell-primary {
            font-weight: 700;
        }
        .cell-secondary {
            display: block;
            color: var(--mui-text-sub);
            font-size: .82rem;
            margin-top: 3px;
        }
        .status-chip,
        .meal-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: .77rem;
            font-weight: 700;
        }
        .status-chip {
            border: 1px solid transparent;
        }
        .status-chip.pending { background: #fff4e5; color: #9a5800; border-color: #ffd8a8; }
        .status-chip.block { background: #e3f2fd; color: #0f5da8; border-color: #bbdefb; }
        .status-chip.admin { background: #e8f5e9; color: #2e7d32; border-color: #c8e6c9; }
        .status-chip.reject { background: #ffebee; color: #c62828; border-color: #ffcdd2; }
        .meal-chip.eat { background: #e8f5e9; color: #2e7d32; }
        .meal-chip.skip { background: #f1f5f9; color: #475569; }
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 16px 8px 6px;
        }
        .mui-btn {
            min-height: 38px;
            border-radius: 12px;
            padding: 0 16px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .badge-status { font-size: .75rem; padding: .3em .6em; border-radius: .4rem; }
        #toast-container { position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; }
        .app-toast { min-width: 260px; border-radius: .6rem; padding: .9rem 1.2rem; font-size: .9rem; font-weight: 500;
                     box-shadow: 0 4px 16px rgba(0,0,0,.15); display: flex; align-items: center; gap: .6rem;
                     animation: toast-in .2s ease; }
        .app-toast.success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .app-toast.error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        @keyframes toast-in { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr; }
            .page-shell { padding-inline: 14px; }
        }
    </style>
</head>
<body>
<div id="toast-container"></div>
<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">承認一覧</h1>
            <div class="page-subtitle">ブロック長向けに、担当部屋の申請を確認して承認または差し戻しを行います。</div>
        </div>
        <a href="<?= h($basePath) ?>/MUserInfo/logout" class="btn btn-outline-secondary mui-btn">ログアウト</a>
    </div>

    <div class="summary-grid">
        <div class="mui-paper summary-card primary">
            <div class="summary-label">未承認</div>
            <div class="summary-value"><?= h((string)$pendingCount) ?></div>
            <div class="summary-note">この画面で優先して確認する件数</div>
        </div>
        <div class="mui-paper summary-card warning">
            <div class="summary-label">差し戻し</div>
            <div class="summary-value"><?= h((string)$rejectedCount) ?></div>
            <div class="summary-note">現在の絞り込み条件に含まれる差し戻し</div>
        </div>
        <div class="mui-paper summary-card soft">
            <div class="summary-label">表示件数</div>
            <div class="summary-value"><?= h((string)count($records)) ?></div>
            <div class="summary-note"><?= h($dateFrom) ?> から <?= h($dateTo) ?> の抽出結果</div>
        </div>
    </div>

    <div class="mui-paper filter-paper">
        <div class="filter-title">絞り込み</div>
        <form method="get" action="" id="filter-form" class="row g-3 align-items-end filter-form">
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label mb-1 small">部屋名</label>
                <select name="room_id" class="form-select auto-submit">
                    <option value="">全部屋</option>
                    <?php foreach ($rooms as $rid => $rname): ?>
                        <option value="<?= h($rid) ?>" <?= (string)$rid === (string)$filterRoomId ? 'selected' : '' ?>>
                            <?= h($rname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label mb-1 small">開始日</label>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control auto-submit">
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label mb-1 small">終了日</label>
                <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control auto-submit">
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label mb-1 small">ステータス</label>
                <select name="status" class="form-select auto-submit">
                    <option value="">全ステータス</option>
                    <?php foreach ($statusLabels as $val => $info): ?>
                        <option value="<?= $val ?>" <?= (string)$val === (string)$filterStatus ? 'selected' : '' ?>>
                            <?= h($info['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <div class="mui-paper table-paper">
        <div class="table-toolbar">
            <div>
                <div class="table-toolbar-title">申請一覧</div>
                <div class="table-toolbar-subtitle">チェックした申請をまとめて承認または差し戻しできます。</div>
            </div>
            <label class="small text-secondary fw-semibold">
                <input type="checkbox" id="check-all-top"> 全選択
            </label>
        </div>

        <form id="approval-form">
        <div class="table-responsive">
        <table class="table approval-table align-middle">
            <thead>
            <tr>
                <th style="width:40px;"><input type="checkbox" id="check-all"></th>
                <th>予約日</th>
                <th>部屋名</th>
                <th>利用者名</th>
                <th>食種</th>
                <th>食べる</th>
                <th>ステータス</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">対象データがありません</td></tr>
            <?php else: ?>
                <?php foreach ($records as $rec): ?>
                    <?php
                        $statusInfo = $statusLabels[(int)$rec->i_approval_status] ?? ['label' => '不明', 'class' => 'bg-secondary text-white'];
                        $effectiveEatFlag = $rec->i_change_flag !== null ? (int)$rec->i_change_flag : (int)($rec->eat_flag ?? 0);
                        $dataKey = json_encode([
                            'i_id_user'          => $rec->i_id_user,
                            'd_reservation_date' => $rec->d_reservation_date->format('Y-m-d'),
                            'i_id_room'          => $rec->i_id_room,
                            'i_reservation_type' => $rec->i_reservation_type,
                        ], JSON_UNESCAPED_UNICODE);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" data-key='<?= h($dataKey) ?>'></td>
                        <td>
                            <span class="cell-primary"><?= h($rec->d_reservation_date) ?></span>
                        </td>
                        <td>
                            <span class="cell-primary"><?= h($rec->m_room_info->c_room_name ?? '') ?></span>
                        </td>
                        <td>
                            <span class="cell-primary"><?= h($rec->m_user_info->c_user_name ?? '') ?></span>
                        </td>
                        <td><span class="cell-primary"><?= h($mealLabels[(int)$rec->i_reservation_type] ?? '') ?></span></td>
                        <td>
                            <span class="meal-chip <?= $effectiveEatFlag === 1 ? 'eat' : 'skip' ?>">
                                <?= $effectiveEatFlag === 1 ? '食べる' : '食べない' ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-chip <?= match ((int)$rec->i_approval_status) {
                                0 => 'pending',
                                1 => 'block',
                                2 => 'admin',
                                3 => 'reject',
                                default => 'pending',
                            } ?>">
                                <?= h($statusInfo['label']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="action-bar">
            <div>
                <label class="small text-secondary fw-semibold">
                    <input type="checkbox" id="check-all-bottom"> 全選択
                </label>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="reject-btn" class="btn btn-outline-danger mui-btn">差し戻し</button>
                <button type="button" id="approve-btn" class="btn btn-primary mui-btn">選択項目を承認</button>
            </div>
        </div>
        </form>
    </div>
</div>

<!-- 完了モーダル -->
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div style="font-size:2.5rem;">✅</div>
                <div id="success-modal-message" class="fs-5 fw-bold mt-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- 差し戻しモーダル -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">差し戻し理由</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea id="reject-reason" class="form-control" rows="3" placeholder="差し戻し理由を記入してください（任意）"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" id="reject-confirm-btn" class="btn btn-danger">差し戻す</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const BASE_PATH   = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN  = document.querySelector('meta[name="csrfToken"]').getAttribute('content');

    function showToast(message, type = 'success') {
        const icon = type === 'success' ? '✅' : '❌';
        const el = document.createElement('div');
        el.className = `app-toast ${type}`;
        el.innerHTML = `<span>${icon}</span><span>${message}</span>`;
        document.getElementById('toast-container').appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
    function showSuccessModal(message) {
        document.getElementById('success-modal-message').textContent = message;
        successModal.show();
        setTimeout(() => { successModal.hide(); location.reload(); }, 1500);
    }

    // フィルタ自動送信
    document.querySelectorAll('#filter-form .auto-submit').forEach(el => {
        el.addEventListener('change', () => document.getElementById('filter-form').submit());
    });

    // 全選択
    ['check-all', 'check-all-top', 'check-all-bottom'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        });
    });

    function getSelectedKeys() {
        return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => JSON.parse(cb.dataset.key));
    }

    async function postApproval(endpoint, body) {
        const res = await fetch(BASE_PATH + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(body),
        });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            console.error('Non-JSON response:', text);
            return { success: false, error: `HTTP ${res.status}` };
        }
    }

    // 承認
    document.getElementById('approve-btn').addEventListener('click', async () => {
        const keys = getSelectedKeys();
        if (keys.length === 0) { showToast('対象を選択してください', 'error'); return; }
        const result = await postApproval('/Approval/blockLeaderApprove', { keys });
        if (result.success) {
            showSuccessModal('承認しました。');
        } else {
            showToast('承認に失敗しました: ' + (result.error ?? ''), 'error');
        }
    });

    // 差し戻しモーダル表示
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    document.getElementById('reject-btn').addEventListener('click', () => {
        if (getSelectedKeys().length === 0) { showToast('対象を選択してください', 'error'); return; }
        rejectModal.show();
    });

    // 差し戻し実行
    document.getElementById('reject-confirm-btn').addEventListener('click', async () => {
        const keys   = getSelectedKeys();
        const reason = document.getElementById('reject-reason').value.trim();
        const result = await postApproval('/Approval/blockLeaderReject', { keys, reason });
        rejectModal.hide();
        if (result.success) {
            showSuccessModal('差し戻しました。');
        } else {
            showToast('差し戻しに失敗しました: ' + (result.error ?? ''), 'error');
        }
    });
</script>
</body>
</html>
