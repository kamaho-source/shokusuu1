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
    <style>
        body { background: #f8f9fa; }
        .page-header { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 16px; }
        .grid-table { font-size: 0.88rem; }
        .grid-table th { background: #f1f5f9; font-weight: 600; white-space: nowrap; }
        .grid-table td { vertical-align: middle; }
        .meal-header { font-size: 0.78rem; color: #64748b; }
        .date-header { font-size: 0.82rem; }
        .weekend-col { background: #fafafa; }
        .sat-col th, .sat-col td { background: #eff6ff; }
        .sun-col th, .sun-col td { background: #fff0f0; }
        .check-cell { min-width: 32px; }
        .actual-cb { width: 18px; height: 18px; cursor: pointer; }
        .actual-cb:disabled { cursor: not-allowed; opacity: 0.4; }
        .user-name { white-space: nowrap; min-width: 100px; }
        .staff-id { font-size: 0.75rem; color: #94a3b8; }
        .week-nav { gap: 8px; }
        .saving-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.15); z-index: 9000; align-items: center; justify-content: center; }
        .saving-overlay.active { display: flex; }
        .notice-area { position: fixed; top: 12px; right: 12px; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
        .notice-toast { background: #1d4ed8; color: #fff; padding: 10px 14px; border-radius: 8px; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .notice-toast.success { background: #15803d; }
        .notice-toast.error   { background: #dc2626; }
        .notice-toast.warning { background: #b45309; }
    </style>
    <script>
        window.__BASE_PATH = <?= json_encode($this->request->getAttribute('base') ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACTUAL_MEAL_SAVE_URL = <?= json_encode($this->Url->build('/TReservationInfo/actual-meal-save'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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

        <?php /* ---- グリッドテーブル ---- */ ?>
        <div class="card">
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
                                    <div><?= h($u['name']) ?></div>
                                    <?php if (!empty($u['staff_id'])): ?>
                                        <div class="staff-id">ID: <?= h($u['staff_id']) ?></div>
                                    <?php endif; ?>
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

        <div class="mt-2 small text-muted">
            ※ チェックを変更すると即時保存されます。競合が発生した場合は画面を再読み込みしてください。
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const saveUrl    = window.__ACTUAL_MEAL_SAVE_URL;
    const csrfToken  = window.__CSRF_TOKEN;
    const overlay    = document.getElementById('saving-overlay');
    const noticeArea = document.getElementById('notice-area');

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

    document.querySelectorAll('.actual-cb').forEach((cb) => {
        cb.addEventListener('change', async (e) => {
            const el      = e.target;
            const uid     = parseInt(el.dataset.uid,     10);
            const date    = el.dataset.date;
            const meal    = parseInt(el.dataset.meal,    10);
            const version = parseInt(el.dataset.version, 10);
            const roomId  = parseInt(el.dataset.room,    10);
            const checked = el.checked ? 1 : 0;

            el.disabled = true;
            setOverlay(true);

            try {
                const res  = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        _csrfToken: csrfToken,
                        user_id:    uid,
                        date:       date,
                        meal_type:  meal,
                        checked:    checked,
                        version:    version,
                        room_id:    roomId,
                    }),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    // バージョンを更新する
                    el.dataset.version = String(version + 1);
                    showNotice('保存しました。', 'success');
                } else {
                    el.checked = !el.checked; // ロールバック
                    showNotice(data.message || '保存に失敗しました。', 'error');
                }
            } catch (err) {
                el.checked = !el.checked; // ロールバック
                showNotice('通信エラーが発生しました。', 'error');
                console.error(err);
            } finally {
                el.disabled = false;
                setOverlay(false);
            }
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
