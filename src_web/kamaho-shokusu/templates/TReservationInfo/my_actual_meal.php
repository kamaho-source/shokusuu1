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
        window.__USER_ID    = <?= json_encode($userId) ?>;
        window.__ROOM_ID    = <?= json_encode($selectedRoomId) ?>;
    </script>
    <style>
        body { background: #f5f7fa; }

        /* ヘッダー */
        .page-header {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .page-header h5 { font-weight: 700; color: #1e293b; }

        /* 週ナビ */
        .week-nav-bar {
            background: #fff;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
        }
        .week-label {
            font-weight: 600;
            font-size: .95rem;
            color: #374151;
        }

        /* 日別カード */
        .day-cards { display: flex; flex-direction: column; gap: 12px; }
        .day-card {
            background: #fff;
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: 0 1px 6px rgba(0,0,0,.07);
        }
        .day-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        .day-badge {
            font-size: .8rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            background: #eff6ff;
            color: #3b82f6;
        }
        .day-badge.sat { background: #eff6ff; color: #2563eb; }
        .day-badge.sun { background: #fff0f0; color: #ef4444; }
        .day-badge.today { background: #d1fae5; color: #059669; }
        .day-date { font-size: .88rem; color: #6b7280; }

        /* 食事トグル行 */
        .meal-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .meal-toggle {
            flex: 1;
            min-width: 80px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 10px 8px;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: #f9fafb;
            cursor: pointer;
            transition: all .15s;
            user-select: none;
        }
        .meal-toggle.active {
            border-color: #6366f1;
            background: #eef2ff;
        }
        .meal-toggle.changed {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .meal-toggle .meal-icon { font-size: 1.4rem; }
        .meal-toggle .meal-label { font-size: .8rem; font-weight: 600; color: #374151; }
        .meal-toggle .meal-status {
            font-size: .72rem;
            color: #9ca3af;
            font-weight: 500;
        }
        .meal-toggle.active .meal-status { color: #6366f1; }
        .meal-toggle.changed .meal-status { color: #d97706; }
        .meal-toggle input { display: none; }

        /* 確定ボタンエリア */
        .action-bar {
            background: #fff;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
        }
        .pending-msg { font-size: .85rem; color: #d97706; font-weight: 600; }

        /* 完了ポップアップ */
        #result-popup {
            position: fixed;
            bottom: 24px; right: 24px;
            display: flex; align-items: center; gap: 10px;
            background: #fff;
            border-radius: 14px;
            padding: 13px 18px;
            font-size: .9rem;
            font-weight: 600;
            box-shadow: 0 8px 32px rgba(0,0,0,.16);
            z-index: 9999;
            transform: translateY(20px);
            opacity: 0;
            pointer-events: none;
            transition: transform .22s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;
        }
        #result-popup.show { transform: translateY(0); opacity: 1; pointer-events: auto; }
        #result-popup.success { border-left: 4px solid #10b981; color: #065f46; }
        #result-popup.error   { border-left: 4px solid #ef4444; color: #991b1b; }

        /* 保存中オーバーレイ */
        #saving-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.15); z-index: 9000;
            align-items: center; justify-content: center;
        }
        #saving-overlay.active { display: flex; }
    </style>
</head>
<body>

<div class="page-header">
    <a href="<?= $this->Url->build('/') ?>" class="btn btn-outline-secondary btn-sm">← ホームへ</a>
    <h5 class="mb-0">実食入力</h5>
    <?php if ($user): ?>
        <span class="badge bg-secondary"><?= h($user->get('c_user_name')) ?></span>
    <?php endif; ?>
</div>

<div class="container py-3" style="max-width:640px;">

    <!-- 部屋選択 -->
    <?php if (count($rooms) > 1): ?>
    <div class="week-nav-bar mb-3">
        <span class="week-label">部屋</span>
        <form method="GET" id="room-form" class="d-flex align-items-center gap-2">
            <input type="hidden" name="week" value="<?= h($weekMondayStr) ?>">
            <select class="form-select form-select-sm" name="room_id" style="max-width:180px;"
                    onchange="document.getElementById('room-form').submit()">
                <?php foreach ($rooms as $rid => $rname): ?>
                    <option value="<?= h($rid) ?>" <?= (string)$rid === (string)$selectedRoomId ? 'selected' : '' ?>>
                        <?= h($rname) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php elseif (empty($rooms)): ?>
        <div class="alert alert-warning">所属部屋が設定されていません。管理者にお問い合わせください。</div>
    <?php endif; ?>

    <!-- 週ナビ -->
    <div class="week-nav-bar mb-3">
        <?php if ($canGoPrev): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= $this->Url->build(['action' => 'myActualMeal', '?' => ['room_id' => $selectedRoomId, 'week' => $prevMonday->format('Y-m-d')]]) ?>">
                &laquo; 前週
            </a>
        <?php else: ?>
            <button class="btn btn-outline-secondary btn-sm" disabled>&laquo; 前週</button>
        <?php endif; ?>
        <span class="week-label"><?= h($weekRangeLabel) ?></span>
        <?php if ($canGoNext): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= $this->Url->build(['action' => 'myActualMeal', '?' => ['room_id' => $selectedRoomId, 'week' => $nextMonday->format('Y-m-d')]]) ?>">
                次週 &raquo;
            </a>
        <?php else: ?>
            <button class="btn btn-outline-secondary btn-sm" disabled>次週 &raquo;</button>
        <?php endif; ?>
    </div>

    <?php if (!$selectedRoomId): ?>
        <div class="alert alert-info">部屋を選択してください。</div>
    <?php else: ?>

    <!-- 確定ボタン -->
    <div class="action-bar mb-3">
        <span class="pending-msg" id="pending-msg"></span>
        <button id="confirm-btn" class="btn btn-primary px-4" disabled>確定して保存</button>
    </div>

    <!-- 日別カード -->
    <?php
    $todayStr  = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $mealIcons = [1 => '🌅', 2 => '☀️', 3 => '🌙'];
    $mealNames = [1 => '朝食', 2 => '昼食', 3 => '夕食'];
    ?>
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
                <span class="day-badge <?= $badgeClass ?>">
                    <?= $isToday ? '今日' : $dowLabel ?>
                </span>
                <span class="day-date"><?= h($dt->format('n月j日')) . '（' . $dowLabel . '）' ?></span>
            </div>
            <div class="meal-row">
            <?php foreach ($meals as $mealType => $mealLabel):
                $checked = !empty($grid[$userId][$d][$mealType]);
                $version = (int)($versions[$userId][$d][$mealType] ?? 1);
            ?>
                <label class="meal-toggle <?= $checked ? 'active' : '' ?>"
                       data-uid="<?= $userId ?>"
                       data-date="<?= h($d) ?>"
                       data-meal="<?= $mealType ?>"
                       data-version="<?= $version ?>"
                       data-original="<?= $checked ? '1' : '0' ?>">
                    <input type="checkbox" <?= $checked ? 'checked' : '' ?>>
                    <span class="meal-icon"><?= $mealIcons[$mealType] ?></span>
                    <span class="meal-label"><?= $mealNames[$mealType] ?></span>
                    <span class="meal-status"><?= $checked ? '食べた' : '未入力' ?></span>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="action-bar mt-3">
        <span class="pending-msg" id="pending-msg-bottom"></span>
        <button id="confirm-btn-bottom" class="btn btn-primary px-4" disabled>確定して保存</button>
    </div>

    <?php endif; ?>
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
        if (status) status.textContent = isChanged ? '変更中' : (checked ? '食べた' : '未入力');
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
