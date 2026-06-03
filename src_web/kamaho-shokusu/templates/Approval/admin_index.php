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
$pendingCount = 0;
$approvedCount = 0;
foreach ($records as $record) {
    $status = (int)($record->i_approval_status ?? 0);
    if ($status === 1) {
        $pendingCount++;
    } elseif ($status === 2) {
        $approvedCount++;
    }
}

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
    <?= $this->Html->css('pages/approval_admin_index.css') ?>
</head>
<body>
<div id="toast-container"></div>
<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">承認管理</h1>
            <div class="page-subtitle">管理者向けに、ブロック長承認済の申請確認、最終承認、差し戻し、食数反映までを行います。</div>
        </div>
        <a href="<?= h($basePath) ?>/" class="btn btn-outline-secondary mui-btn">戻る</a>
    </div>

    <div class="summary-grid">
        <div class="mui-paper summary-card primary">
            <div class="summary-label">最終承認待ち</div>
            <div class="summary-value"><?= h((string)$pendingCount) ?></div>
            <div class="summary-note">ブロック長承認済で管理者判断待ちの件数</div>
        </div>
        <div class="mui-paper summary-card success">
            <div class="summary-label">管理者承認済</div>
            <div class="summary-value"><?= h((string)$approvedCount) ?></div>
            <div class="summary-note">現在の絞り込み条件に含まれる承認済件数</div>
        </div>
        <div class="mui-paper summary-card soft">
            <div class="summary-label">表示件数</div>
            <div class="summary-value"><?= h((string)count($records)) ?></div>
            <div class="summary-note">一覧テーブルに表示している申請件数</div>
        </div>
        <div class="mui-paper summary-card soft">
            <div class="summary-label">期間</div>
            <div class="summary-value" style="font-size:1.15rem; line-height:1.35;"><?= h($dateFrom) ?><br><?= h($dateTo) ?></div>
            <div class="summary-note">集計と一覧で共通の期間条件</div>
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
                <input type="date" id="date-from-input" name="date_from" value="<?= h($dateFrom) ?>" class="form-control auto-submit">
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label mb-1 small">終了日</label>
                <input type="date" id="date-to-input" name="date_to" value="<?= h($dateTo) ?>" class="form-control auto-submit">
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

    <!-- 集計サマリ -->
    <?php if (!empty($summary)): ?>
    <div class="mui-paper section-paper">
        <div class="section-head" id="summary-toggle" style="cursor:pointer;">
            <div>
                <div class="section-title">集計サマリ</div>
                <div class="section-subtitle">日付ごとの管理者承認済食数を確認できます。</div>
            </div>
            <span class="text-secondary small fw-semibold">▼ 開閉</span>
        </div>
        <div class="px-2 pb-2" id="summary-body">
            <table class="table table-sm table-bordered summary-table mb-0">
                <thead class="table-light">
                <tr>
                    <th>日付</th>
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
                        <td><?= h($row['reservation_date']) ?></td>
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
                    <td colspan="2" class="text-start">期間合計</td>
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
    <div class="mui-paper section-paper">
        <div class="section-head">
            <div>
                <div class="section-title">申請一覧</div>
                <div class="section-subtitle">最終承認待ちを中心に、必要に応じて差し戻しや食数反映まで行います。</div>
            </div>
            <label class="small text-secondary fw-semibold"><input type="checkbox" id="check-all-top"> 全選択</label>
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
                        <td><input type="checkbox" class="row-check" data-status="<?= h((string)$rec->i_approval_status) ?>" data-key='<?= h($dataKey) ?>'></td>
                        <td><?= h($rec->d_reservation_date) ?></td>
                        <td><?= h($rec->m_room_info->c_room_name ?? '') ?></td>
                        <td><?= h($rec->m_user_info->c_user_name ?? '') ?></td>
                        <td><?= h($mealLabels[(int)$rec->i_reservation_type] ?? '') ?></td>
                        <td><span class="meal-chip <?= $effectiveEatFlag === 1 ? 'eat' : 'skip' ?>"><?= $effectiveEatFlag === 1 ? '食べる' : '食べない' ?></span></td>
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

        <div class="d-flex justify-content-between align-items-center mt-2">
            <div>
                <label class="small text-secondary fw-semibold"><input type="checkbox" id="check-all-bottom"> 全選択</label>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="reject-btn"  class="btn btn-outline-danger mui-btn">差し戻し</button>
                <button type="button" id="approve-block-leader-btn" class="btn btn-outline-success mui-btn">ブロック長承認済を承認</button>
                <button type="button" id="approve-btn" class="btn btn-primary mui-btn">選択項目を一括承認</button>
                <button type="button" id="reflect-btn" class="btn btn-outline-primary mui-btn">承認済みを食数へ反映</button>
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

<!-- 食数反映確認モーダル -->
<div id="reflect-modal" class="custom-modal-backdrop" aria-hidden="true">
    <div class="custom-modal-card" role="dialog" aria-modal="true" aria-labelledby="reflect-modal-title">
        <div class="custom-modal-header">
            <div>
                <div class="reflect-popup-mark">↻</div>
                <h5 id="reflect-modal-title" class="reflect-popup-title">食数へ反映しますか？</h5>
            </div>
            <button type="button" id="reflect-close-btn" class="btn-close reflect-popup-close" aria-label="閉じる"></button>
        </div>
        <div class="custom-modal-body">
            現在の絞り込み条件に一致する管理者承認済みデータを、
            食数テーブルへまとめて反映します。
        </div>
        <div class="custom-modal-footer">
            <button type="button" id="reflect-cancel-btn" class="btn btn-secondary">キャンセル</button>
            <button type="button" id="reflect-confirm-btn" class="btn btn-primary">反映する</button>
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

    const dateFromInput = document.getElementById('date-from-input');
    const dateToInput = document.getElementById('date-to-input');
    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', () => {
            if (!dateFromInput.value) {
                return;
            }
            const startDate = new Date(`${dateFromInput.value}T00:00:00`);
            if (Number.isNaN(startDate.getTime())) {
                return;
            }
            startDate.setDate(startDate.getDate() + 6);
            const yyyy = startDate.getFullYear();
            const mm = String(startDate.getMonth() + 1).padStart(2, '0');
            const dd = String(startDate.getDate()).padStart(2, '0');
            dateToInput.value = `${yyyy}-${mm}-${dd}`;
        });
    }

    // 全選択
    ['check-all', 'check-all-top', 'check-all-bottom'].forEach(id => {
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

    function syncCheckAllState() {
        const rows = Array.from(document.querySelectorAll('.row-check'));
        const allChecked = rows.length > 0 && rows.every(cb => cb.checked);
        ['check-all', 'check-all-bottom'].forEach(id => {
            const toggle = document.getElementById(id);
            if (toggle) {
                toggle.checked = allChecked;
            }
        });
    }

    function selectBlockLeaderApprovedRows() {
        const rows = Array.from(document.querySelectorAll('.row-check'));
        let count = 0;
        rows.forEach(cb => {
            const shouldCheck = cb.dataset.status === '1';
            cb.checked = shouldCheck;
            if (shouldCheck) {
                count++;
            }
        });
        syncCheckAllState();

        return count;
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

    // 一括承認
    document.getElementById('approve-btn').addEventListener('click', async () => {
        const keys = getSelectedKeys();
        if (keys.length === 0) { showToast('対象を選択してください', 'error'); return; }
        const result = await postApproval('/Approval/adminApprove', { keys });
        if (result.success) {
            showSuccessModal('承認しました。');
        } else {
            showToast('承認に失敗しました: ' + (result.error ?? ''), 'error');
        }
    });

    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', syncCheckAllState);
    });

    document.getElementById('approve-block-leader-btn').addEventListener('click', async () => {
        const count = selectBlockLeaderApprovedRows();
        if (count === 0) {
            showToast('ブロック長承認済の対象がありません', 'error');
            return;
        }

        const keys = getSelectedKeys();
        const result = await postApproval('/Approval/adminApprove', { keys });
        if (result.success) {
            showSuccessModal('ブロック長承認済の対象を承認しました。');
        } else {
            showToast('承認に失敗しました: ' + (result.error ?? ''), 'error');
        }
    });

    // 差し戻しモーダル
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    document.getElementById('reject-btn').addEventListener('click', () => {
        if (getSelectedKeys().length === 0) { showToast('対象を選択してください', 'error'); return; }
        rejectModal.show();
    });
    document.getElementById('reject-confirm-btn').addEventListener('click', async () => {
        const keys   = getSelectedKeys();
        const reason = document.getElementById('reject-reason').value.trim();
        const result = await postApproval('/Approval/adminReject', { keys, reason });
        rejectModal.hide();
        if (result.success) {
            showSuccessModal('差し戻しました。');
        } else {
            showToast('差し戻しに失敗しました: ' + (result.error ?? ''), 'error');
        }
    });

    // 食数反映モーダル
    const reflectModal = document.getElementById('reflect-modal');
    const openReflectModal = () => {
        reflectModal.classList.add('is-open');
        reflectModal.setAttribute('aria-hidden', 'false');
    };
    const closeReflectModal = () => {
        reflectModal.classList.remove('is-open');
        reflectModal.setAttribute('aria-hidden', 'true');
    };
    document.getElementById('reflect-btn').addEventListener('click', openReflectModal);
    document.getElementById('reflect-close-btn').addEventListener('click', closeReflectModal);
    document.getElementById('reflect-cancel-btn').addEventListener('click', closeReflectModal);
    reflectModal.addEventListener('click', (event) => {
        if (event.target === reflectModal) {
            closeReflectModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && reflectModal.classList.contains('is-open')) {
            closeReflectModal();
        }
    });
    document.getElementById('reflect-confirm-btn').addEventListener('click', async () => {
        const body = { room_id: FILTER_ROOM_ID, date: null };
        const result = await postApproval('/Approval/adminReflect', body);
        closeReflectModal();
        if (result.success) {
            showSuccessModal(result.count + ' 件を食数に反映しました。');
        } else {
            showToast('反映に失敗しました: ' + (result.error ?? ''), 'error');
        }
    });
</script>
</body>
</html>
