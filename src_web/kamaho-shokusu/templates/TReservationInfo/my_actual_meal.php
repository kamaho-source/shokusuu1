<?php
/**
 * 個人向け実食入力画面
 *
 * 職員が自分の実食（i_change_flag）を週単位で入力する。
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', '実食入力');

$user      = $this->request->getAttribute('identity');
$csrfToken = $this->request->getAttribute('csrfToken') ?? '';

$dates    = $gridData['dates']    ?? [];
$meals    = $gridData['meals']    ?? [1 => '朝', 2 => '昼', 3 => '夜'];
$grid     = $gridData['grid']     ?? [];
$versions = $gridData['versions'] ?? [];
$statuses = $gridData['statuses'] ?? [];
$targetUsers = $targetUsers ?? [];
$selectedUserId = $selectedUserId ?? ($user ? (int)$user->get('i_id_user') : 0);
$selectedTargetUser = $selectedTargetUser ?? null;
$isBlockLeader = $isBlockLeader ?? false;

$userId = $user ? (int)$user->get('i_id_user') : 0;

$dow       = ['日', '月', '火', '水', '木', '金', '土'];
$weekRangeLabel = '';
if (!empty($dates)) {
    $fmt = fn(string $d) => (new \DateTimeImmutable($d))->format('n/j') . '(' . $dow[(int)(new \DateTimeImmutable($d))->format('w')] . ')';
    $weekRangeLabel = $fmt($dates[0]) . ' 〜 ' . $fmt($dates[count($dates) - 1]);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>実食入力</title>
    <meta name="csrfToken" content="<?= h($csrfToken) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        window.__SAVE_URL   = <?= json_encode($this->Url->build('/TReservationInfo/actual-meal-save'), JSON_UNESCAPED_SLASHES) ?>;
        window.__CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
        window.__USER_ID    = <?= json_encode($selectedUserId) ?>;
        window.__ROOM_ID    = <?= json_encode($selectedRoomId) ?>;
    </script>
    <?= $this->Html->css('pages/t_reservation_my_actual_meal.css') ?>
</head>
<body>

<div class="page-header">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <a href="<?= $this->Url->build('/') ?>" class="btn btn-outline-secondary btn-sm">ホームへ戻る</a>
        <div>
            <h5>実食入力</h5>
            <div class="small text-muted">当週の実食結果を確認し、必要な箇所だけ更新してください。</div>
        </div>
    </div>
    <div class="header-meta">
        <?php if ($user): ?>
            <span class="header-chip">対象者 <strong><?= h($selectedTargetUser['name'] ?? $user->get('c_user_name')) ?></strong></span>
        <?php endif; ?>
        <span class="header-chip">期間 <strong><?= h($weekRangeLabel) ?></strong></span>
    </div>
</div>

<div class="page-shell">
    <div class="layout-grid">
        <aside class="sidebar-stack">
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">入力対象</div>
                        <div class="panel-subtitle">担当者と対象週を確認しながら入力します。</div>
                    </div>
                </div>
                <div class="info-list">
                    <div class="info-item">
                        <div class="info-item-label">対象者</div>
                        <div class="info-item-value"><?= h($selectedTargetUser['name'] ?? $user?->get('c_user_name') ?? '') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label">期間</div>
                        <div class="info-item-value"><?= h($weekRangeLabel) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label">使い方</div>
                        <div class="info-item-value">食べた食事だけをオンにして、最後にまとめて保存します。</div>
                    </div>
                </div>
                <div class="legend-list">
                    <div class="legend-item"><span class="legend-chip approved">承認済み</span><span>承認済の内容が現在値です。</span></div>
                    <div class="legend-item"><span class="legend-chip pending">申請中</span><span>承認待ちの状態です。</span></div>
                    <div class="legend-item"><span class="legend-chip editing">変更中</span><span>保存前の変更があります。</span></div>
                    <div class="legend-item"><span class="legend-chip rejected">差し戻し</span><span>再確認が必要な入力です。</span></div>
                    <div class="legend-item"><span class="legend-chip empty">未入力</span><span>まだ登録されていません。</span></div>
                </div>
            </div>

            <?php if (count($rooms) > 1 || ($isBlockLeader && count($targetUsers) > 1)): ?>
            <div class="panel">
                <div class="control-row">
                    <div>
                        <div class="panel-title">対象の切り替え</div>
                        <div class="panel-subtitle">
                            <?= $isBlockLeader ? '担当部屋と対象者を切り替えて実食を入力できます。' : '所属部屋が複数ある場合のみ切り替えできます。'; ?>
                        </div>
                    </div>
                    <form method="GET" id="room-form" class="control-stack">
                        <input type="hidden" name="week" value="<?= h($weekMondayStr) ?>">
                        <?php if (count($rooms) > 1): ?>
                            <select class="form-select form-select-sm" name="room_id" style="min-width:220px;"
                                    onchange="document.getElementById('room-form').submit()">
                                <?php foreach ($rooms as $rid => $rname): ?>
                                    <option value="<?= h($rid) ?>" <?= (string)$rid === (string)$selectedRoomId ? 'selected' : '' ?>>
                                        <?= h($rname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="room_id" value="<?= h((string)$selectedRoomId) ?>">
                        <?php endif; ?>
                        <?php if ($isBlockLeader && count($targetUsers) > 1): ?>
                            <select class="form-select form-select-sm" name="user_id" style="min-width:220px;"
                                    onchange="document.getElementById('room-form').submit()">
                                <?php foreach ($targetUsers as $targetUser): ?>
                                    <option value="<?= h((string)$targetUser['id']) ?>" <?= (string)$targetUser['id'] === (string)$selectedUserId ? 'selected' : '' ?>>
                                        <?= h($targetUser['name']) ?><?= !empty($targetUser['staff_id']) ? ' / ' . h($targetUser['staff_id']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($selectedUserId): ?>
                            <input type="hidden" name="user_id" value="<?= h((string)$selectedUserId) ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php elseif (empty($rooms)): ?>
                <div class="alert alert-warning mb-0">所属部屋が設定されていません。管理者にお問い合わせください。</div>
            <?php endif; ?>
        </aside>

        <main class="content-stack">
            <div class="week-nav-bar">
                <?php if ($canGoPrev): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= $this->Url->build(['action' => 'myActualMeal', '?' => ['room_id' => $selectedRoomId, 'user_id' => $selectedUserId, 'week' => $prevMonday->format('Y-m-d')]]) ?>">
                        &laquo; 前週
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm" disabled>&laquo; 前週</button>
                <?php endif; ?>
                <div class="text-center">
                    <div class="week-label"><?= h($weekRangeLabel) ?></div>
                    <div class="section-caption">PCでは週全体を横に見渡し、スマホでは日別カードで入力できます。</div>
                </div>
                <?php if ($canGoNext): ?>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= $this->Url->build(['action' => 'myActualMeal', '?' => ['room_id' => $selectedRoomId, 'user_id' => $selectedUserId, 'week' => $nextMonday->format('Y-m-d')]]) ?>">
                        次週 &raquo;
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary btn-sm" disabled>次週 &raquo;</button>
                <?php endif; ?>
            </div>

            <?php if (!$selectedRoomId): ?>
                <div class="alert alert-info">部屋を選択してください。</div>
            <?php else: ?>

            <div class="action-bar sticky">
                <div class="action-copy">
                    <span class="action-title">実食の変更をまとめて保存</span>
                    <span class="pending-msg" id="pending-msg"></span>
                </div>
                <button id="confirm-btn" class="btn btn-primary px-4" disabled>確定して保存<span class="desktop-only">する</span></button>
            </div>

            <!-- 日別カード / 週ボード -->
            <?php
            $todayStr  = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            $mealNames = [1 => '朝食', 2 => '昼食', 3 => '夕食'];
            ?>
            <div class="board-scroll">
            <div class="day-cards" id="day-cards">
            <?php foreach ($dates as $d):
                $dt      = new \DateTimeImmutable($d);
                $dowIdx  = (int)$dt->format('w');
                $isToday = ($d === $todayStr);
                $dowLabel = $dow[$dowIdx];
                $badgeClass = $isToday ? 'today' : ($dowIdx === 6 ? 'sat' : ($dowIdx === 0 ? 'sun' : ''));
            ?>
                <div class="day-card" data-date="<?= h($d) ?>">
                    <div class="day-card-header">
                        <div class="day-heading">
                            <span class="day-badge <?= $badgeClass ?>">
                                <?= $isToday ? '今日' : $dowLabel ?>
                            </span>
                            <span class="day-date"><?= h($dt->format('n月j日')) . '（' . $dowLabel . '）' ?></span>
                        </div>
                    </div>
                    <div class="meal-row">
                    <?php foreach ($meals as $mealType => $mealLabel):
                        $checked = !empty($grid[$selectedUserId][$d][$mealType]);
                        $version = (int)($versions[$selectedUserId][$d][$mealType] ?? 1);
                        $status  = (int)($statuses[$selectedUserId][$d][$mealType] ?? 0);
                        $isRejected = $status === 3;
                        $isPending  = $checked && in_array($status, [0, 1], true);
                        if ($isRejected) {
                            $statusText = '差し戻し';
                        } elseif ($isPending) {
                            $statusText = '申請中';
                        } elseif ($checked) {
                            $statusText = '承認済み';
                        } else {
                            $statusText = '未入力';
                        }
                        $mealCode = [1 => 'AM', 2 => 'LN', 3 => 'PM'][$mealType] ?? '--';
                    ?>
                        <label class="meal-toggle <?= $checked ? 'active' : '' ?> <?= $isRejected ? 'rejected' : '' ?> <?= $isPending ? 'pending' : '' ?>"
                               data-uid="<?= $selectedUserId ?>"
                               data-date="<?= h($d) ?>"
                               data-meal="<?= $mealType ?>"
                               data-version="<?= $version ?>"
                               data-original="<?= $checked ? '1' : '0' ?>"
                               data-status-text="<?= h($statusText) ?>">
                            <input type="checkbox" <?= $checked ? 'checked' : '' ?>>
                            <span class="meal-toggle-top">
                                <span class="meal-code"><?= h($mealCode) ?></span>
                                <span class="meal-marker"></span>
                            </span>
                            <span class="meal-label"><?= $mealNames[$mealType] ?></span>
                            <span class="meal-status"><?= h($statusText) ?></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            </div>

            <div class="action-bar">
                <div class="action-copy">
                    <span class="action-title">変更内容を確認して保存</span>
                    <span class="pending-msg" id="pending-msg-bottom"></span>
                </div>
                <button id="confirm-btn-bottom" class="btn btn-primary px-4" disabled>確定して保存<span class="desktop-only">する</span></button>
            </div>

            <?php endif; ?>
        </main>
    </div>
</div>

<div id="saving-overlay">
    <div class="spinner-border text-light" role="status"><span class="visually-hidden">保存中...</span></div>
</div>
<div id="result-popup">
    <span id="result-icon"></span>
    <span id="result-msg"></span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const saveUrl   = window.__SAVE_URL;
    const csrfToken = window.__CSRF_TOKEN;
    const roomId    = window.__ROOM_ID;
    const overlay   = document.getElementById('saving-overlay');
    const popup     = document.getElementById('result-popup');
    let popupTimer  = null;

    // pending変更: key => {label, version, checked}
    const pending = new Map();

    function showResult(msg, ok) {
        document.getElementById('result-icon').textContent = ok ? '✅' : '❌';
        document.getElementById('result-msg').textContent  = msg;
        popup.className = ok ? 'show success' : 'show error';
        clearTimeout(popupTimer);
        popupTimer = setTimeout(() => { popup.className = ''; }, 2200);
    }

    function updateUI() {
        const count = pending.size;
        const msg   = count > 0 ? `${count} 件の変更があります` : '';
        ['pending-msg', 'pending-msg-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = msg;
        });
        ['confirm-btn', 'confirm-btn-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = count === 0;
        });
    }

    function syncToggleStyle(label, checked, isChanged) {
        const status = label.querySelector('.meal-status');
        label.classList.toggle('active',   checked && !isChanged);
        label.classList.toggle('changed',  isChanged);
        if (status) {
            if (isChanged) {
                status.textContent = '変更中';
            } else if (checked) {
                status.textContent = label.dataset.statusText || '承認済み';
            } else {
                status.textContent = '未入力';
            }
        }
    }

    // トグルクリック
    document.querySelectorAll('.meal-toggle').forEach(label => {
        label.addEventListener('click', () => {
            const cb       = label.querySelector('input[type=checkbox]');
            cb.checked     = !cb.checked;
            const key      = `${label.dataset.uid}-${label.dataset.date}-${label.dataset.meal}`;
            const original = label.dataset.original === '1';
            const isChanged = cb.checked !== original;

            if (isChanged) {
                pending.set(key, { label, version: parseInt(label.dataset.version, 10), checked: cb.checked });
            } else {
                pending.delete(key);
            }
            syncToggleStyle(label, cb.checked, isChanged);
            updateUI();
        });
    });

    async function doSave() {
        if (pending.size === 0) return;
        ['confirm-btn', 'confirm-btn-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = true;
        });
        overlay.classList.add('active');

        let ok = 0, ng = [];
        for (const [key, { label, version, checked }] of [...pending.entries()]) {
            const uid     = parseInt(label.dataset.uid,  10);
            const date    = label.dataset.date;
            const meal    = parseInt(label.dataset.meal, 10);
            try {
                const res  = await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ user_id: uid, date, meal_type: meal,
                                          checked: checked ? 1 : 0, version, room_id: roomId }),
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    label.dataset.version  = String(data.data?.version ?? version + 1);
                    label.dataset.original = checked ? '1' : '0';
                    const cb = label.querySelector('input');
                    if (cb) cb.checked = checked;
                    syncToggleStyle(label, checked, false);
                    pending.delete(key);
                    ok++;
                } else {
                    ng.push(data.message || '保存に失敗しました。');
                    // ロールバック
                    const cb = label.querySelector('input');
                    const orig = label.dataset.original === '1';
                    if (cb) cb.checked = orig;
                    syncToggleStyle(label, orig, false);
                    pending.delete(key);
                }
            } catch (e) {
                ng.push('通信エラーが発生しました。');
                const cb = label.querySelector('input');
                const orig = label.dataset.original === '1';
                if (cb) cb.checked = orig;
                syncToggleStyle(label, orig, false);
                pending.delete(key);
            }
        }

        overlay.classList.remove('active');
        updateUI();
        if (ok > 0) showResult(`${ok} 件保存しました。`, true);
        if (ng.length > 0) showResult(ng[0], false);
    }

    document.getElementById('confirm-btn')?.addEventListener('click', doSave);
    document.getElementById('confirm-btn-bottom')?.addEventListener('click', doSave);
});
</script>
</body>
</html>
