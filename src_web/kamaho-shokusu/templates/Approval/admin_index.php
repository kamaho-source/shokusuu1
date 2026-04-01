<?php
/** @var \App\Controller\ApprovalController $this */
$records      = $records ?? [];
$summary      = $summary ?? [];
$rooms        = $rooms ?? [];
$filterRoomId = $filterRoomId ?? '';
$filterStatus = $filterStatus ?? '';
$dateFrom     = $dateFrom ?? date('Y-m-d', strtotime('monday this week'));
$dateTo       = $dateTo   ?? date('Y-m-d', strtotime('sunday this week'));

$statusLabels = [
    0 => ['label' => '未承認',           'class' => 'bg-warning text-dark'],
    1 => ['label' => 'ブロック長承認済', 'class' => 'bg-info text-dark'],
    2 => ['label' => '管理者承認済',     'class' => 'bg-success text-white'],
    3 => ['label' => '差し戻し',         'class' => 'bg-danger text-white'],
];
$mealLabels = [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁当'];
$basePath = $this->request->getAttribute('base') ?? '';

// 合計行の計算
$total = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 0];
foreach ($summary as $row) {
    $total['breakfast'] += $row['breakfast'];
    $total['lunch']     += $row['lunch'];
    $total['dinner']    += $row['dinner'];
    $total['bento']     += $row['bento'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>承認管理（管理者）</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .badge-status { font-size: .75rem; padding: .3em .6em; border-radius: .4rem; }
        .summary-table th, .summary-table td { text-align: center; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">承認管理（管理者）</h4>
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

    <!-- 集計サマリ -->
    <?php if (!empty($summary)): ?>
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center" id="summary-toggle" style="cursor:pointer;">
            <span class="fw-bold small">▼ 集計サマリ（管理者承認済）</span>
        </div>
        <div class="card-body p-2" id="summary-body">
            <table class="table table-sm table-bordered summary-table mb-0">
                <thead class="table-light">
                <tr>
                    <th>部屋名</th>
                    <th>朝</th>
                    <th>昼</th>
                    <th>夕</th>
                    <th>弁当</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($summary as $row): ?>
                    <tr>
                        <td class="text-start"><?= h($row['room_name']) ?></td>
                        <td><?= $row['breakfast'] ?>名</td>
                        <td><?= $row['lunch'] ?>名</td>
                        <td><?= $row['dinner'] ?>名</td>
                        <td><?= $row['bento'] ?>名</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                <tr>
                    <td class="text-start">合計</td>
                    <td><?= $total['breakfast'] ?>名</td>
                    <td><?= $total['lunch'] ?>名</td>
                    <td><?= $total['dinner'] ?>名</td>
                    <td><?= $total['bento'] ?>名</td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
                            'd_reservation_date' => (string)$rec->d_reservation_date,
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
                <label class="small"><input type="checkbox" id="check-all-bottom"> 全選択</label>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="reject-btn"  class="btn btn-outline-danger btn-sm">差し戻し</button>
                <button type="button" id="approve-btn" class="btn btn-success btn-sm">選択項目を一括承認</button>
                <button type="button" id="reflect-btn" class="btn btn-primary btn-sm">承認済みを食数へ反映</button>
            </div>
        </div>
    </form>
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

<!-- 食数反映確認モーダル -->
<div class="modal fade" id="reflectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">食数への反映</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                現在フィルタ中の日付・部屋名で管理者承認済みのレコードを<br>
                食数テーブルへ反映します。よろしいですか？
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" id="reflect-confirm-btn" class="btn btn-primary">反映する</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const BASE_PATH  = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = document.querySelector('meta[name="csrfToken"]').getAttribute('content');
    const FILTER_ROOM_ID = <?= json_encode($filterRoomId ?: null) ?>;
    const FILTER_DATE_FROM = <?= json_encode($dateFrom) ?>;
    const FILTER_DATE_TO   = <?= json_encode($dateTo) ?>;

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

    // サマリ折りたたみ
    document.getElementById('summary-toggle')?.addEventListener('click', () => {
        const body = document.getElementById('summary-body');
        body.style.display = body.style.display === 'none' ? '' : 'none';
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
        return res.json();
    }

    // 一括承認
    document.getElementById('approve-btn').addEventListener('click', async () => {
        const keys = getSelectedKeys();
        if (keys.length === 0) { alert('対象を選択してください'); return; }
        const result = await postApproval('/Approval/adminApprove', { keys });
        if (result.success) { location.reload(); } else { alert('承認に失敗しました'); }
    });

    // 差し戻しモーダル
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    document.getElementById('reject-btn').addEventListener('click', () => {
        if (getSelectedKeys().length === 0) { alert('対象を選択してください'); return; }
        rejectModal.show();
    });
    document.getElementById('reject-confirm-btn').addEventListener('click', async () => {
        const keys   = getSelectedKeys();
        const reason = document.getElementById('reject-reason').value.trim();
        const result = await postApproval('/Approval/adminReject', { keys, reason });
        rejectModal.hide();
        if (result.success) { location.reload(); } else { alert('差し戻しに失敗しました'); }
    });

    // 食数反映モーダル
    const reflectModal = new bootstrap.Modal(document.getElementById('reflectModal'));
    document.getElementById('reflect-btn').addEventListener('click', () => reflectModal.show());
    document.getElementById('reflect-confirm-btn').addEventListener('click', async () => {
        const body = { room_id: FILTER_ROOM_ID, date: null };
        const result = await postApproval('/Approval/adminReflect', body);
        reflectModal.hide();
        if (result.success) {
            alert(result.count + ' 件のブロックを食数に反映しました');
            location.reload();
        } else {
            alert('反映に失敗しました');
        }
    });
</script>
</body>
</html>
