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
        .badge-status { font-size: .75rem; padding: .3em .6em; border-radius: .4rem; }
        #toast-container { position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; }
        .app-toast { min-width: 260px; border-radius: .6rem; padding: .9rem 1.2rem; font-size: .9rem; font-weight: 500;
                     box-shadow: 0 4px 16px rgba(0,0,0,.15); display: flex; align-items: center; gap: .6rem;
                     animation: toast-in .2s ease; }
        .app-toast.success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .app-toast.error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        @keyframes toast-in { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    </style>
</head>
<body>
<div id="toast-container"></div>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">承認一覧（ブロック長）</h4>
        <a href="<?= h($basePath) ?>/MUserInfo/logout" class="btn btn-outline-secondary btn-sm">ログアウト</a>
    </div>

    <!-- フィルタ -->
    <form method="get" action="" id="filter-form" class="row g-2 mb-3 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-1 small">部屋名</label>
            <select name="room_id" class="form-select form-select-sm auto-submit">
                <option value="">全部屋</option>
                <?php foreach ($rooms as $rid => $rname): ?>
                    <option value="<?= h($rid) ?>" <?= (string)$rid === (string)$filterRoomId ? 'selected' : '' ?>>
                        <?= h($rname) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label mb-1 small">開始日</label>
            <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control form-control-sm auto-submit">
        </div>
        <div class="col-auto">
            <label class="form-label mb-1 small">終了日</label>
            <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control form-control-sm auto-submit">
        </div>
        <div class="col-auto">
            <label class="form-label mb-1 small">ステータス</label>
            <select name="status" class="form-select form-select-sm auto-submit">
                <option value="">全ステータス</option>
                <?php foreach ($statusLabels as $val => $info): ?>
                    <option value="<?= $val ?>" <?= (string)$val === (string)$filterStatus ? 'selected' : '' ?>>
                        <?= h($info['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <!-- 一覧テーブル -->
    <form id="approval-form">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
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
                        $dataKey = json_encode([
                            'i_id_user'          => $rec->i_id_user,
                            'd_reservation_date' => $rec->d_reservation_date->format('Y-m-d'),
                            'i_id_room'          => $rec->i_id_room,
                            'i_reservation_type' => $rec->i_reservation_type,
                        ], JSON_UNESCAPED_UNICODE);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" data-key='<?= h($dataKey) ?>'></td>
                        <td><?= h($rec->d_reservation_date) ?></td>
                        <td><?= h($rec->m_room_info->c_room_name ?? '') ?></td>
                        <td><?= h($rec->m_user_info->c_user_name ?? '') ?></td>
                        <td><?= h($mealLabels[(int)$rec->i_reservation_type] ?? '') ?></td>
                        <td><?= (int)$rec->eat_flag === 1 ? '○' : '×' ?></td>
                        <td><span class="badge badge-status <?= h($statusInfo['class']) ?>"><?= h($statusInfo['label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="d-flex justify-content-between align-items-center mt-2">
            <div>
                <label class="small">
                    <input type="checkbox" id="check-all-bottom"> 全選択
                </label>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="reject-btn" class="btn btn-outline-danger btn-sm">差し戻し</button>
                <button type="button" id="approve-btn" class="btn btn-success btn-sm">選択項目を承認</button>
            </div>
        </div>
    </form>
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
    ['check-all', 'check-all-bottom'].forEach(id => {
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
