<?php
// 直前編集の一括画面（ExcelライクUI）
$user = $this->request->getAttribute('identity');
$selectedDate = $selectedDate ?? date('Y-m-d');
$rooms = $rooms ?? [];
$selectedRoomId = $selectedRoomId ?? ($this->request->getQuery('room_id') ?? '');
$selectedDateObj = $selectedDateObj ?? new \DateTimeImmutable($selectedDate);
$baseWeek = $baseWeek ?? $selectedDateObj->modify('monday this week');
$weekStarts = $weekStarts ?? [$baseWeek];
$activeWeekDate = $activeWeekDate ?? $baseWeek->format('Y-m-d');
$maxWeek = $maxWeek ?? $baseWeek->modify('+21 days');
$days = $days ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>直前編集（一括）</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        window.__BASE_PATH = <?= json_encode($this->request->getAttribute('base') ?? $this->request->getAttribute('webroot') ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__SELECTED_DATE = <?= json_encode($selectedDateObj->format('Y-m-d'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__CHANGE_EDIT = true;
        window.__ROOM_ID = <?= json_encode($selectedRoomId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__BASE_WEEK = <?= json_encode($baseWeek->format('Y-m-d'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__LOGIN_USER_ID = <?= json_encode($user?->get('i_id_user') ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?= $this->Html->css('pages/bulk_change_edit_form.pc.css') ?>
<?= $this->Html->css('pages/bulk_change_edit_form.mobile.css') ?>
</head>
<body>
<div class="container py-3">
    <div class="excel-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <input type="search" class="form-control" placeholder="氏名検索" style="max-width:220px;">
            <button class="btn btn-outline-primary btn-sm" id="copy-day-btn">現在選択中の曜日を全曜日にコピー</button>
        </div>
        <div class="header-actions">
            <div class="room-select-wrap">
                <select class="form-select room-select" id="room-select">
                    <option value="">部屋を選択</option>
                    <?php foreach ($rooms as $rid => $rname): ?>
                        <option value="<?= h($rid) ?>" <?= ((string)$rid === (string)$selectedRoomId) ? 'selected' : '' ?>>
                            <?= h($rname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="room-select-help" class="invalid-feedback">部屋を選択してください。</div>
            </div>
            <button class="btn btn-success btn-sm" id="save-btn">確定・保存</button>
            <span class="dirty-badge" id="dirty-badge">未保存</span>
            <a class="help-link" href="#" data-bs-toggle="modal" data-bs-target="#uxHelpModal">使い方</a>
        </div>
    </div>

    <div class="mt-2 tab-day d-flex gap-2 flex-wrap">
        <?php foreach ($days as $idx => $d): ?>
            <button class="btn btn-sm <?= $idx === 0 ? 'active' : '' ?> <?= $d['is_disabled'] ? 'disabled-day' : '' ?>"
                    data-date="<?= h($d['date']) ?>"
                    data-disabled="<?= $d['is_disabled'] ? '1' : '0' ?>">
                <?= h($d['label']) ?>（<?= h($d['date']) ?>）
            </button>
        <?php endforeach; ?>
    </div>

    <div class="sub-bar mt-2 d-flex align-items-center gap-3 flex-wrap">
        <span>表示中：<strong id="active-date-label"><?= h($days[0]['label']) ?>曜日</strong></span>
        <span>朝食：<span class="meal-count" id="count-morning">0</span></span>
        <span>昼食：<span class="meal-count" id="count-noon">0</span></span>
        <span>夕食：<span class="meal-count" id="count-night">0</span></span>
        <span>弁当：<span class="meal-count" id="count-bento">0</span></span>
    </div>

    <div class="excel-card mt-3 p-3">
        <form action="<?= $this->Url->build(['action' => 'changeEdit']) ?>" method="post" id="reservation-form">
            <input type="hidden" name="_csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
            <input type="hidden" name="i_id_room" id="i_id_room">
            <div id="selection-inputs"></div>

            <div class="table-responsive">
                <table class="table excel-table align-middle">
                    <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>職員氏名 / 所属</th>
                        <th class="text-center">
                            <label class="d-inline-flex align-items-center gap-2">
                                <input type="checkbox" class="bulk-toggle" data-type="1">
                                朝
                            </label>
                        </th>
                        <th class="text-center">
                            <label class="d-inline-flex align-items-center gap-2">
                                <input type="checkbox" class="bulk-toggle" data-type="2">
                                昼
                            </label>
                        </th>
                        <th class="text-center">
                            <label class="d-inline-flex align-items-center gap-2">
                                <input type="checkbox" class="bulk-toggle" data-type="3">
                                夜
                            </label>
                        </th>
                        <th class="text-center">
                            <label class="d-inline-flex align-items-center gap-2">
                                <input type="checkbox" class="bulk-toggle" data-type="4">
                                弁当
                            </label>
                        </th>
                    </tr>
                    </thead>
                    <tbody id="user-rows">
                    <tr><td colspan="6" class="text-center text-muted">部屋を選択してください。</td></tr>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    <div class="pager mt-2 d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="pager-prev" type="button">前へ</button>
        <span class="small text-muted" id="pager-info">ページ 1 / 1（0件）</span>
        <button class="btn btn-outline-secondary btn-sm" id="pager-next" type="button">次へ</button>
    </div>

    <div class="week-tabs mt-3 d-flex gap-2">
        <?php foreach ($weekStarts as $idx => $ws): ?>
            <?php
            $label = '第' . ($idx + 1) . '週';
            $isActive = ($ws->format('Y-m-d') === $activeWeekDate);
            $isOverLimit = ($ws > $maxWeek);
            $href = $this->Url->build([
                'action' => 'bulkChangeEditForm',
                '?' => [
                    'date' => $ws->format('Y-m-d'),
                    'base_week' => $baseWeek->format('Y-m-d'),
                    'room_id' => $selectedRoomId,
                ],
            ]);
            ?>
            <a class="btn btn-sm <?= $isActive ? 'active' : '' ?> <?= $isOverLimit ? 'disabled' : '' ?>"
               href="<?= $isOverLimit ? '#' : $href ?>"
               <?= $isOverLimit ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="uxHelpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">使い方</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <ul class="mb-0">
                    <li>最初に部屋を選択してください。</li>
                    <li>曜日タブで日付を切り替えます。</li>
                    <li>予約不可はボタンが無効化され、理由が表示されます。</li>
                    <li>保存前に他の週へ移動すると警告が出ます。</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?= $this->Html->script('bulk_change_edit_form.js') ?>
<script>
    (function(){
        const tabs = document.querySelectorAll('.week-tabs .btn');
        if (!tabs.length) return;
        const hasActive = Array.from(tabs).some(t => t.classList.contains('active'));
        if (!hasActive) tabs[0].classList.add('active');
    })();
</script>
</body>
</html>
