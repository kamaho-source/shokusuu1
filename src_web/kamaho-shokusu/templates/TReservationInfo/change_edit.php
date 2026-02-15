<?php
/**
 * 直前編集ビュー（ExcelライクUI）
 */

$selectedRoomId = null;
$selectedRoomName = '';

if (isset($room) && is_object($room)) {
    $selectedRoomId = $room->i_id_room;
    $selectedRoomName = $room->c_room_name ?? '';
} elseif (is_array($rooms) && !empty($rooms)) {
    $selectedRoomId = array_key_first($rooms);
    $selectedRoomName = $rooms[$selectedRoomId] ?? '';
}

$basePath = $this->Url->build('/', ['fullBase' => false]);
$mealType = $this->request->getParam('mealType') ?? $this->request->getQuery('mealType') ?? 2;
?>
<div id="ce-root"
     data-base="<?= h($basePath) ?>"
     data-date="<?= h($date) ?>"
     data-mealtype="<?= h($mealType) ?>">
    <style>
        body { background:#eef2f6; }
        .excel-header { background:#e9edf3; border-radius:10px; padding:10px 12px; }
        .sub-bar { background:#1f2937; color:#fff; padding:8px 14px; border-radius:8px; }
        .meal-count { color:#22c55e; font-weight:700; margin-left:4px; }
        .excel-card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; }
        .excel-table th { font-size:.75rem; color:#8a96a3; text-transform:uppercase; }
        .meal-toggle { display:none; }
        .meal-btn {
            width:36px; height:28px; border-radius:6px;
            background:#e5e7eb; display:inline-flex; align-items:center; justify-content:center;
            color:#1f2937; font-weight:700; cursor:pointer;
        }
        .meal-toggle:checked + .meal-btn { background:#2563eb; color:#fff; }
        .meal-toggle:disabled + .meal-btn { background:#cbd5e1; color:#64748b; cursor:not-allowed; }
    </style>

    <div class="container py-3">
        <div class="excel-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <input type="search" class="form-control" placeholder="氏名検索" style="max-width:220px;">
                <button class="btn btn-outline-primary btn-sm" type="button">月曜日の設定を全曜日にコピー</button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($rooms)): ?>
                    <?php if (count($rooms) > 1): ?>
                        <?= $this->Form->control('i_id_room', [
                            'type'      => 'select',
                            'label'     => false,
                            'options'   => $rooms,
                            'empty'     => false,
                            'value'     => $selectedRoomId,
                            'class'     => 'form-select',
                            'required'  => true,
                            'id'        => 'ce-room-select',
                            'data-date' => $date,
                        ]) ?>
                    <?php else: ?>
                        <div class="form-control-plaintext"><?= h($selectedRoomName ?: '（部屋未設定）') ?></div>
                        <?= $this->Form->hidden('i_id_room', ['value'=>$selectedRoomId, 'id'=>'ce-room-hidden']) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted small">部屋が設定されていません。</div>
                <?php endif; ?>
                <button class="btn btn-success btn-sm" type="button" onclick="document.getElementById('change-edit-form').requestSubmit()">確定・保存</button>
            </div>
        </div>

        <div class="sub-bar mt-2 d-flex align-items-center gap-3 flex-wrap">
            <span>表示中：<strong><?= h($date) ?></strong></span>
            <span>朝食：<span class="meal-count">-</span></span>
            <span>昼食：<span class="meal-count">-</span></span>
            <span>夕食：<span class="meal-count">-</span></span>
            <span>弁当：<span class="meal-count">-</span></span>
        </div>

        <div class="excel-card mt-3 p-3">
            <?= $this->Form->create(null, ['id'=>'change-edit-form', 'url'=>['action'=>'changeEdit']]) ?>
            <?= $this->Form->hidden('d_reservation_date', ['value'=>$date, 'id'=>'ce-date-hidden']) ?>
            <?= $this->Form->hidden('meal_type', ['value'=>$mealType, 'id'=>'ce-mealtype-hidden']) ?>
            <?php if ($selectedRoomId): ?>
                <?= $this->Form->hidden('i_id_room', ['value'=>$selectedRoomId, 'id'=>'ce-room-hidden']) ?>
            <?php endif; ?>

            <div id="ce-table-wrap" class="table-responsive">
                <table class="table excel-table align-middle" id="ce-table">
                    <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>職員氏名 / 所属</th>
                        <th class="text-center">MORNING<br><input type="checkbox" id="select-all-1" aria-label="朝 全選択/解除"></th>
                        <th class="text-center">LUNCH<br><input type="checkbox" id="select-all-2" aria-label="昼 全選択/解除"></th>
                        <th class="text-center">DINNER<br><input type="checkbox" id="select-all-3" aria-label="夜 全選択/解除"></th>
                        <th class="text-center">BENTO<br><input type="checkbox" id="select-all-4" aria-label="弁当 全選択/解除"></th>
                    </tr>
                    </thead>
                    <tbody id="ce-tbody">
                    <tr><td colspan="6" class="text-center text-muted">読み込み中...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 d-flex gap-2">
                <?= $this->Form->button(__('保存'), ['class'=>'btn btn-primary']) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php
// 既存の JS を利用するためのスクリプトは元のまま動作します
?>
<script>
    (function(){
        // 既存 change_edit.php のJSロジックをそのまま動かすために、
        // 必要なDOMは保持しています。
    })();
</script>
