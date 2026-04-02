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
    <style>
        :root {
            --mui-bg: #f4f7fb;
            --mui-surface: #ffffff;
            --mui-border: #dbe3ef;
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
            max-width: 1480px;
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
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
        .summary-card.success { background: linear-gradient(135deg, rgba(46,125,50,.10), rgba(46,125,50,.04)); }
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
        .section-paper {
            padding: 12px;
            margin-bottom: 18px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 8px 10px 14px;
            flex-wrap: wrap;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 700;
        }
        .section-subtitle {
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
            background: #f6f9fc;
            color: var(--mui-text-sub);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 14px 12px;
            border-bottom: 1px solid var(--mui-border);
        }
        .approval-table tbody td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }
        .approval-table tbody tr:hover {
            background: #fbfdff;
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
        .status-chip.pending { background: #fff4e5; color: #9a5800; }
        .status-chip.block { background: #e3f2fd; color: #0f5da8; }
        .status-chip.admin { background: #e8f5e9; color: #2e7d32; }
        .status-chip.reject { background: #ffebee; color: #c62828; }
        .meal-chip.eat { background: #e8f5e9; color: #2e7d32; }
        .meal-chip.skip { background: #f1f5f9; color: #475569; }
        .mui-btn {
            min-height: 38px;
            border-radius: 12px;
            padding: 0 16px;
            font-weight: 700;
        }
        .badge-status { font-size: .75rem; padding: .3em .6em; border-radius: .4rem; }
        .summary-table th, .summary-table td { text-align: center; }
        #toast-container { position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; }
        .app-toast { min-width: 260px; border-radius: .6rem; padding: .9rem 1.2rem; font-size: .9rem; font-weight: 500;
                     box-shadow: 0 4px 16px rgba(0,0,0,.15); display: flex; align-items: center; gap: .6rem;
                     animation: toast-in .2s ease; }
        .app-toast.success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .app-toast.error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        @keyframes toast-in { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
        .custom-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.18);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1055;
            padding: 1rem;
            backdrop-filter: blur(4px);
        }
        .custom-modal-backdrop.is-open { display: flex; }
        .custom-modal-card {
            width: min(100%, 360px);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 20px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.18);
            overflow: hidden;
            transform: translateY(8px) scale(0.98);
            opacity: 0;
            transition: transform .18s ease, opacity .18s ease;
        }
        .custom-modal-backdrop.is-open .custom-modal-card {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        .custom-modal-header,
        .custom-modal-footer {
            padding: .9rem 1rem;
        }
        .custom-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: none;
            padding-bottom: .25rem;
        }
        .custom-modal-body {
            padding: .25rem 1rem 1rem;
            color: #475569;
            line-height: 1.7;
            font-size: .93rem;
        }
        .custom-modal-footer {
            display: flex;
            justify-content: stretch;
            gap: .6rem;
            border-top: none;
            padding-top: 0;
        }
        .custom-modal-footer .btn {
            flex: 1;
            border-radius: 999px;
            font-weight: 600;
            padding: .6rem .9rem;
        }
        .reflect-popup-mark {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 1.1rem;
            margin-bottom: .75rem;
        }
        .reflect-popup-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .reflect-popup-close {
            opacity: .7;
        }
        @media (max-width: 1100px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 760px) {
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
            <h1 class="page-title">承認管理</h1>
            <div class="page-subtitle">管理者向けに、ブロック長承認済の申請確認、最終承認、差し戻し、食数反映までを行います。</div>
        </div>
        <a href="<?= h($basePath) ?>/MUserInfo/logout" class="btn btn-outline-secondary mui-btn">ログアウト</a>
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
