<?php
/**
 * 実食確認管理テンプレート（大人限定）
 *
 * 週単位のグリッドで職員の実食実績（i_change_flag）を管理する。
 * 対象: i_id_staff を持つ大人ユーザーのみ。
 * 管理者: 過去2ヶ月まで遡って編集可能。
 *
 * 受け取るビュー変数:
 *   - $rooms          : array  部屋リスト [id => name]
 *   - $selectedRoomId : int|null 選択中の部屋ID
 *   - $adultUsers     : array  大人ユーザーリスト [{id, name, staff_id}]
 *   - $gridData       : array  週グリッドデータ {dates, meals, grid, versions}
 *   - $weekMondayStr  : string 表示中の週の月曜日 (YYYY-MM-DD)
 *   - $prevMonday     : DateTimeImmutable 前週月曜日
 *   - $nextMonday     : DateTimeImmutable 次週月曜日
 *   - $canGoPrev      : bool   前週ナビが使用可能か
 *   - $canGoNext      : bool   次週ナビが使用可能か
 *   - $isAdmin        : bool   管理者フラグ
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', '実食確認管理');

$user = $this->request->getAttribute('identity');
$csrfToken = $this->request->getAttribute('csrfToken') ?? '';

$dates    = $gridData['dates']    ?? [];
$meals    = $gridData['meals']    ?? [1 => '朝', 2 => '昼', 3 => '夜'];
$grid     = $gridData['grid']     ?? [];
$versions = $gridData['versions'] ?? [];
$mealNames = [1 => '朝食', 2 => '昼食', 3 => '夕食'];

// 週の表示ラベル
$dow = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabels = [];
foreach ($dates as $d) {
    try {
        $dt = new \DateTimeImmutable($d);
        $dateLabels[$d] = $dt->format('n/j') . '(' . $dow[(int)$dt->format('w')] . ')';
    } catch (\Throwable $e) {
        $dateLabels[$d] = $d;
    }
}

// 週範囲文字列 (例: 3/2(月) 〜 3/8(日))
$weekRangeLabel = '';
if (!empty($dates)) {
    $first = $dateLabels[$dates[0]] ?? $dates[0];
    $last  = $dateLabels[$dates[count($dates) - 1]] ?? $dates[count($dates) - 1];
    $weekRangeLabel = $first . ' 〜 ' . $last;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>実食確認管理</title>
    <meta name="csrfToken" content="<?= h($csrfToken) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= $this->Html->css('pages/t_reservation_actual_meal_management.css') ?>
    <script>
        window.__BASE_PATH = <?= json_encode($this->request->getAttribute('base') ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_SAVE_URL = <?= json_encode($this->Url->build('/TReservationInfo/actual-meal-save'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_REQUEST_APPROVAL_URL = <?= json_encode($this->Url->build('/TReservationInfo/actual-meal-request-approval'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_USERS = <?= json_encode($adultUsers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_GRID = <?= json_encode($grid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_VERSIONS = <?= json_encode($versions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_DATES = <?= json_encode($dates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_LABELS = <?= json_encode($dateLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_MEALS = <?= json_encode($meals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_MEAL_NAMES = <?= json_encode($mealNames, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body>
<div class="page-header d-flex align-items-center gap-3 flex-wrap">
    <a href="<?= $this->Url->build('/') ?>" class="btn btn-outline-secondary btn-sm">← ホームへ</a>
    <h5 class="mb-0 fw-bold">実食確認管理</h5>
    <span class="badge bg-warning text-dark">大人（職員）限定</span>
    <small class="text-muted ms-2">※ i_id_staff を保持する職員ユーザーのみ対象です。</small>
</div>

<div class="container-fluid py-3">

    <?php /* ---- 部屋選択 ---- */ ?>
    <div class="card mb-3">
        <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-semibold mb-0" for="room-select">部屋選択：</label>
            <form method="GET" id="room-form" class="d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="week" value="<?= h($weekMondayStr) ?>">
                <select class="form-select form-select-sm" id="room-select" name="room_id"
                        style="max-width:200px;"
                        onchange="document.getElementById('room-form').submit()">
                    <option value="">部屋を選択</option>
                    <?php foreach ($rooms as $rid => $rname): ?>
                        <option value="<?= h($rid) ?>" <?= ((string)$rid === (string)$selectedRoomId) ? 'selected' : '' ?>>
                            <?= h($rname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php /* ---- 週ナビゲーション ---- */ ?>
            <div class="d-flex align-items-center week-nav ms-auto">
                <?php if ($canGoPrev): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= $this->Url->build(['action' => 'actualMealManagement', '?' => ['room_id' => $selectedRoomId, 'week' => $prevMonday->format('Y-m-d')]]) ?>">
                        &laquo; 前週
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm" disabled>&laquo; 前週</button>
                <?php endif; ?>
                <span class="fw-semibold mx-2 text-nowrap"><?= h($weekRangeLabel) ?></span>
                <?php if ($canGoNext): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= $this->Url->build(['action' => 'actualMealManagement', '?' => ['room_id' => $selectedRoomId, 'week' => $nextMonday->format('Y-m-d')]]) ?>">
                        次週 &raquo;
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm" disabled>次週 &raquo;</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$selectedRoomId): ?>
        <div class="alert alert-info">部屋を選択してください。</div>
    <?php elseif (empty($adultUsers)): ?>
        <div class="alert alert-warning">この部屋に大人（職員）ユーザーがいません。<br>
            <small>※ 職員IDが設定されているユーザーのみ表示されます。</small></div>
    <?php else: ?>

        <?php /* ---- モバイルカード表示 ---- */ ?>
        <div class="mobile-user-cards d-md-none">
            <?php foreach ($adultUsers as $u):
                $uid = (int)$u['id'];
            ?>
                <section class="user-card">
                    <header class="user-card-header">
                        <div>
                            <div class="user-card-name"><?= h($u['name']) ?></div>
                            <?php if (!empty($u['staff_id'])): ?>
                                <div class="staff-id">ID: <?= h($u['staff_id']) ?></div>
                            <?php endif; ?>
                        </div>
                        <button type="button"
                                class="btn btn-outline-primary btn-sm user-action-btn open-user-modal-btn"
                                data-uid="<?= (int)$uid ?>">
                            入力
                        </button>
                    </header>

                    <div class="user-card-body">
                        <?php foreach ($dates as $d):
                            $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                            $dayClass = $dow_idx === 6 ? 'sat-col' : ($dow_idx === 0 ? 'sun-col' : '');
                        ?>
                            <div class="meal-day-block <?= $dayClass ?>">
                                <div class="meal-day-label"><?= h($dateLabels[$d] ?? $d) ?></div>
                                <div class="meal-day-checks">
                                    <?php foreach ($meals as $mealType => $mealLabel):
                                        $checked  = !empty($grid[$uid][$d][$mealType]);
                                        $version  = (int)($versions[$uid][$d][$mealType] ?? 1);
                                    ?>
                                        <label class="meal-check-item">
                                            <span><?= h($mealLabel) ?></span>
                                            <input type="checkbox"
                                                   class="actual-cb"
                                                   data-uid="<?= (int)$uid ?>"
                                                   data-date="<?= h($d) ?>"
                                                   data-meal="<?= (int)$mealType ?>"
                                                   data-version="<?= $version ?>"
                                                   data-room="<?= (int)$selectedRoomId ?>"
                                                   <?= $checked ? 'checked' : '' ?>
                                                   title="<?= h($u['name']) ?> <?= h($d) ?> <?= h($mealLabel) ?>">
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <?php /* ---- グリッドテーブル ---- */ ?>
        <div class="card d-none d-md-block">
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-bordered grid-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th rowspan="2" class="align-middle" style="min-width:120px;">職員名</th>
                            <?php foreach ($dates as $d):
                                $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                                $colClass = $dow_idx === 6 ? 'sat-col' : ($dow_idx === 0 ? 'sun-col' : '');
                            ?>
                                <th colspan="3" class="text-center date-header <?= $colClass ?>"><?= h($dateLabels[$d] ?? $d) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($dates as $d):
                                $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                                $colClass = $dow_idx === 6 ? 'sat-col' : ($dow_idx === 0 ? 'sun-col' : '');
                                foreach ($meals as $mealType => $mealLabel):
                            ?>
                                <th class="text-center meal-header check-cell <?= $colClass ?>"><?= h($mealLabel) ?></th>
                            <?php endforeach; endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($adultUsers as $u):
                            $uid = (int)$u['id'];
                        ?>
                            <tr>
                                <td class="user-name">
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <div>
                                            <div><?= h($u['name']) ?></div>
                                            <?php if (!empty($u['staff_id'])): ?>
                                                <div class="staff-id">ID: <?= h($u['staff_id']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-outline-primary btn-sm user-action-btn open-user-modal-btn"
                                                data-uid="<?= (int)$uid ?>">
                                            入力
                                        </button>
                                    </div>
                                </td>
                                <?php foreach ($dates as $d):
                                    $dow_idx = (int)(new \DateTimeImmutable($d))->format('w');
                                    $colClass = $dow_idx === 6 ? 'sat-col' : ($dow_idx === 0 ? 'sun-col' : '');
                                    foreach ($meals as $mealType => $mealLabel):
                                        $checked  = !empty($grid[$uid][$d][$mealType]);
                                        $version  = (int)($versions[$uid][$d][$mealType] ?? 1);
                                ?>
                                    <td class="text-center check-cell <?= $colClass ?>">
                                        <input type="checkbox"
                                               class="actual-cb"
                                               data-uid="<?= (int)$uid ?>"
                                               data-date="<?= h($d) ?>"
                                               data-meal="<?= (int)$mealType ?>"
                                               data-version="<?= $version ?>"
                                               data-room="<?= (int)$selectedRoomId ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               title="<?= h($u['name']) ?> <?= h($d) ?> <?= h($mealLabel) ?>">
                                    </td>
                                <?php endforeach; endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
            <button id="confirm-request-btn" class="btn btn-primary" disabled>登録して承認申請</button>
            <span id="pending-count" class="text-muted small"></span>
        </div>
        <div class="mt-1 small text-muted">
            ※ チェックを変更後、「確定」ボタンを押すと保存されます。競合が発生した場合は画面を再読み込みしてください。
        </div>

    <?php endif; ?>
</div>

<?php /* ---- 保存中オーバーレイ ---- */ ?>
<div class="saving-overlay" id="saving-overlay">
    <div class="spinner-border text-light" role="status">
        <span class="visually-hidden">保存中...</span>
    </div>
</div>

<?php /* ---- 通知エリア ---- */ ?>
<div class="notice-area" id="notice-area"></div>

<div class="modal fade" id="userMealEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="user-meal-editor-title">実食入力</h5>
                    <div class="small text-muted" id="user-meal-editor-subtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div id="user-meal-editor-body" class="meal-editor-grid"></div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="user-meal-editor-pending"></span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">閉じる</button>
                <button type="button" class="btn btn-primary" id="user-meal-editor-request-btn" disabled>登録して承認申請</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const saveUrl    = window.__ACTUAL_MEAL_SAVE_URL;
    const requestApprovalUrl = window.__ACTUAL_MEAL_REQUEST_APPROVAL_URL;
    const csrfToken  = window.__CSRF_TOKEN;
    const overlay    = document.getElementById('saving-overlay');
    const noticeArea = document.getElementById('notice-area');
    const confirmRequestBtn = document.getElementById('confirm-request-btn');
    const pendingCount = document.getElementById('pending-count');
    const users = window.__ACTUAL_MEAL_USERS || [];
    const gridState = window.__ACTUAL_MEAL_GRID || {};
    const versionState = window.__ACTUAL_MEAL_VERSIONS || {};
    const dates = window.__ACTUAL_MEAL_DATES || [];
    const dateLabels = window.__ACTUAL_MEAL_LABELS || {};
    const meals = window.__ACTUAL_MEAL_MEALS || {};
    const mealNames = window.__ACTUAL_MEAL_MEAL_NAMES || {};
    const editorModalEl = document.getElementById('userMealEditorModal');
    const editorModal = editorModalEl ? new bootstrap.Modal(editorModalEl) : null;
    const editorBody = document.getElementById('user-meal-editor-body');
    const editorTitle = document.getElementById('user-meal-editor-title');
    const editorSubtitle = document.getElementById('user-meal-editor-subtitle');
    const editorPending = document.getElementById('user-meal-editor-pending');
    const editorRequestBtn = document.getElementById('user-meal-editor-request-btn');
    const modalPendingChanges = new Map();
    let modalUserId = null;

    // key: "uid-date-meal", value: {el, checked}
    const pendingChanges = new Map();

    function showNotice(msg, type) {
        const toast = document.createElement('div');
        toast.className = `notice-toast ${type || ''}`;
        toast.textContent = msg;
        noticeArea.appendChild(toast);
        setTimeout(() => toast.remove(), 2800);
    }

    function setOverlay(on) {
        if (overlay) overlay.classList.toggle('active', on);
    }

    function updatePendingUI() {
        const count = pendingChanges.size;
        if (confirmRequestBtn) confirmRequestBtn.disabled = count === 0;
        if (pendingCount) {
            pendingCount.textContent = count > 0 ? `${count} 件の変更があります` : '';
        }
    }

    function getCellChecked(uid, date, meal) {
        return Boolean(gridState?.[uid]?.[date]?.[meal]);
    }

    function getCellVersion(uid, date, meal) {
        return parseInt(versionState?.[uid]?.[date]?.[meal] ?? 1, 10);
    }

    function updateEditorPendingUI() {
        const count = modalPendingChanges.size;
        if (editorRequestBtn) editorRequestBtn.disabled = count === 0;
        if (editorPending) editorPending.textContent = count > 0 ? `${count} 件の変更があります` : '';
    }

    function syncEditorToggleState(button, checked, changed) {
        button.classList.toggle('active', checked && !changed);
        button.classList.toggle('pending', changed);
        const status = button.querySelector('.meal-editor-status');
        if (status) {
            status.textContent = changed ? '変更中' : (checked ? '入力済み' : '未入力');
        }
    }

    function renderUserEditor(uid) {
        if (!editorBody) return;
        modalPendingChanges.clear();
        modalUserId = uid;
        const targetUser = users.find((row) => parseInt(row.id, 10) === uid);
        if (editorTitle) {
            editorTitle.textContent = targetUser ? `${targetUser.name} の実食入力` : '実食入力';
        }
        if (editorSubtitle) {
            editorSubtitle.textContent = targetUser && targetUser.staff_id
                ? `職員ID: ${targetUser.staff_id}`
                : '対象者の週次実食を入力します。';
        }

        editorBody.innerHTML = dates.map((date) => {
            const mealButtons = Object.keys(meals).map((mealKey) => {
                const meal = parseInt(mealKey, 10);
                const checked = getCellChecked(uid, date, meal);
                const mealName = mealNames[meal] || meals[meal] || '';
                return `
                    <button type="button"
                            class="meal-editor-toggle ${checked ? 'active' : ''}"
                            data-uid="${uid}"
                            data-date="${date}"
                            data-meal="${meal}"
                            data-version="${getCellVersion(uid, date, meal)}"
                            data-original="${checked ? '1' : '0'}">
                        ${mealName}
                        <span class="meal-editor-status">${checked ? '入力済み' : '未入力'}</span>
                    </button>
                `;
            }).join('');

            return `
                <div class="meal-editor-day">
                    <div class="meal-editor-head">
                        <div class="meal-editor-title">${dateLabels[date] || date}</div>
                    </div>
                    <div class="meal-editor-row">${mealButtons}</div>
                </div>
            `;
        }).join('');

        editorBody.querySelectorAll('.meal-editor-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const original = button.dataset.original === '1';
                const currentChecked = modalPendingChanges.has(`${button.dataset.uid}-${button.dataset.date}-${button.dataset.meal}`)
                    ? modalPendingChanges.get(`${button.dataset.uid}-${button.dataset.date}-${button.dataset.meal}`).checked
                    : original;
                const nextChecked = !currentChecked;
                const key = `${button.dataset.uid}-${button.dataset.date}-${button.dataset.meal}`;
                const changed = nextChecked !== original;

                if (changed) {
                    modalPendingChanges.set(key, {
                        uid: parseInt(button.dataset.uid, 10),
                        date: button.dataset.date,
                        meal: parseInt(button.dataset.meal, 10),
                        version: parseInt(button.dataset.version, 10),
                        checked: nextChecked,
                        button,
                    });
                } else {
                    modalPendingChanges.delete(key);
                }

                syncEditorToggleState(button, nextChecked, changed);
            });
        });

        updateEditorPendingUI();
    }

    document.querySelectorAll('.actual-cb').forEach((cb) => {
        // 初期値を記憶する
        cb.dataset.originalChecked = cb.checked ? '1' : '0';

        cb.addEventListener('change', (e) => {
            const el  = e.target;
            const uid  = el.dataset.uid;
            const date = el.dataset.date;
            const meal = el.dataset.meal;
            const key  = `${uid}-${date}-${meal}`;
            const isOriginal = (el.checked ? '1' : '0') === el.dataset.originalChecked;

            if (isOriginal) {
                // 元の状態に戻ったので保留から削除
                pendingChanges.delete(key);
                el.classList.remove('border', 'border-warning');
            } else {
                pendingChanges.set(key, { el });
                el.classList.add('border', 'border-warning');
            }
            updatePendingUI();
        });
    });

    async function requestApproval(keys) {
        const res = await fetch(requestApprovalUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({ _csrfToken: csrfToken, keys }),
        });

        return res.json();
    }

    async function saveGridChanges(shouldRequestApproval = false) {
        if (pendingChanges.size === 0) return;

        if (confirmRequestBtn) confirmRequestBtn.disabled = true;
        setOverlay(true);

        const entries = Array.from(pendingChanges.entries());
        let successCount = 0;
        let errorMessages = [];
        const approvalKeys = [];

        for (const [key, { el }] of entries) {
            const uid     = parseInt(el.dataset.uid, 10);
            const date    = el.dataset.date;
            const meal    = parseInt(el.dataset.meal, 10);
            const version = parseInt(el.dataset.version, 10);
            const roomId  = parseInt(el.dataset.room, 10);
            const checked = el.checked ? 1 : 0;

            try {
                const res  = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        _csrfToken: csrfToken,
                        user_id: uid,
                        date: date,
                        meal_type: meal,
                        checked: checked,
                        version: version,
                        room_id: roomId,
                    }),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    el.dataset.version = String(data.data?.version ?? version + 1);
                    el.dataset.originalChecked = checked ? '1' : '0';
                    el.classList.remove('border', 'border-warning');
                    pendingChanges.delete(key);
                    successCount++;
                    approvalKeys.push({ user_id: uid, date, meal_type: meal, room_id: roomId });
                } else {
                    errorMessages.push(data.message || '保存に失敗しました。');
                    el.checked = el.dataset.originalChecked === '1';
                    el.classList.remove('border', 'border-warning');
                    pendingChanges.delete(key);
                }
            } catch (err) {
                errorMessages.push('通信エラーが発生しました。');
                el.checked = el.dataset.originalChecked === '1';
                el.classList.remove('border', 'border-warning');
                pendingChanges.delete(key);
                console.error(err);
            }
        }

        if (shouldRequestApproval && approvalKeys.length > 0) {
            const approvalResult = await requestApproval(approvalKeys);
            if (!approvalResult.ok) {
                errorMessages.push(approvalResult.message || '承認申請に失敗しました。');
            }
        }

        setOverlay(false);
        updatePendingUI();

        if (successCount > 0) {
            showNotice(shouldRequestApproval ? `${successCount} 件保存して申請しました。` : `${successCount} 件保存しました。`, 'success');
        }
        if (errorMessages.length > 0) {
            errorMessages.forEach(msg => showNotice(msg, 'error'));
        }
    }

    confirmRequestBtn?.addEventListener('click', () => saveGridChanges(true));

    document.querySelectorAll('.open-user-modal-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const uid = parseInt(button.dataset.uid, 10);
            renderUserEditor(uid);
            editorModal?.show();
        });
    });

    async function saveModalChanges(shouldRequestApproval = false) {
        if (modalPendingChanges.size === 0 || modalUserId === null) return;

        if (editorRequestBtn) editorRequestBtn.disabled = true;
        setOverlay(true);

        let successCount = 0;
        const errorMessages = [];
        const approvalKeys = [];

        for (const [key, payload] of Array.from(modalPendingChanges.entries())) {
            try {
                const res = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        _csrfToken: csrfToken,
                        user_id: payload.uid,
                        date: payload.date,
                        meal_type: payload.meal,
                        checked: payload.checked ? 1 : 0,
                        version: payload.version,
                        room_id: <?= json_encode((int)$selectedRoomId) ?>,
                    }),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    if (!gridState[payload.uid]) gridState[payload.uid] = {};
                    if (!gridState[payload.uid][payload.date]) gridState[payload.uid][payload.date] = {};
                    if (!versionState[payload.uid]) versionState[payload.uid] = {};
                    if (!versionState[payload.uid][payload.date]) versionState[payload.uid][payload.date] = {};

                    gridState[payload.uid][payload.date][payload.meal] = payload.checked;
                    versionState[payload.uid][payload.date][payload.meal] = parseInt(data.data?.version ?? payload.version + 1, 10);

                    payload.button.dataset.original = payload.checked ? '1' : '0';
                    payload.button.dataset.version = String(versionState[payload.uid][payload.date][payload.meal]);
                    syncEditorToggleState(payload.button, payload.checked, false);

                    const rowCheckbox = document.querySelector(`.actual-cb[data-uid="${payload.uid}"][data-date="${payload.date}"][data-meal="${payload.meal}"]`);
                    if (rowCheckbox) {
                        rowCheckbox.checked = payload.checked;
                        rowCheckbox.dataset.originalChecked = payload.checked ? '1' : '0';
                        rowCheckbox.dataset.version = String(versionState[payload.uid][payload.date][payload.meal]);
                        rowCheckbox.classList.remove('border', 'border-warning');
                        pendingChanges.delete(key);
                    }

                    modalPendingChanges.delete(key);
                    successCount++;
                    approvalKeys.push({
                        user_id: payload.uid,
                        date: payload.date,
                        meal_type: payload.meal,
                        room_id: <?= json_encode((int)$selectedRoomId) ?>,
                    });
                } else {
                    errorMessages.push(data.message || '保存に失敗しました。');
                }
            } catch (error) {
                errorMessages.push('通信エラーが発生しました。');
                console.error(error);
            }
        }

        if (shouldRequestApproval && approvalKeys.length > 0) {
            const approvalResult = await requestApproval(approvalKeys);
            if (!approvalResult.ok) {
                errorMessages.push(approvalResult.message || '承認申請に失敗しました。');
            }
        }

        setOverlay(false);
        updatePendingUI();
        updateEditorPendingUI();
        if (successCount > 0) {
            showNotice(shouldRequestApproval ? `${successCount} 件保存して申請しました。` : `${successCount} 件保存しました。`, 'success');
        }
        if (errorMessages.length > 0) {
            showNotice(errorMessages[0], 'error');
        }
        if (modalPendingChanges.size === 0) {
            editorModal?.hide();
        } else {
            if (editorRequestBtn) editorRequestBtn.disabled = false;
        }
    }

    editorRequestBtn?.addEventListener('click', () => saveModalChanges(true));
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
