<?php
/**
 * 直前編集ビュー
 */
$this->assign('title', '直前編集');

$selectedRoomId = null;
$selectedRoomName = '';
$individualReservations = $individualReservations ?? [];

if (isset($room) && is_object($room)) {
    $selectedRoomId = $room->i_id_room;
    $selectedRoomName = $room->c_room_name ?? '';
} elseif (is_array($rooms) && !empty($rooms)) {
    $selectedRoomId = array_key_first($rooms);
    $selectedRoomName = $rooms[$selectedRoomId] ?? '';
}

$basePath = $this->Url->build('/', ['fullBase' => false]);
$mealType = $this->request->getParam('mealType') ?? $this->request->getQuery('mealType') ?? 2;
echo $this->Html->css('pages/t_reservation_change_edit.css');

$indivJson = json_encode(
    array_values(array_map(fn($r) => [
        'type'    => (int)$r->i_reservation_type,
        'room_id' => (int)$r->i_id_room,
    ], $individualReservations)),
    JSON_UNESCAPED_UNICODE
);
?>
<div id="ce-root"
     data-base="<?= h($basePath) ?>"
     data-date="<?= h($date) ?>"
     data-mealtype="<?= h($mealType) ?>">

    <!-- 警告ヘッダ -->
    <div class="ce-warning-banner">
        <span class="ce-date-label">&#9888; 直前編集：<?= h($date) ?></span>
        <span class="ce-warning-note">発注済みです。変更内容をよく確認してください。</span>
    </div>

    <!-- 予約タイプ選択 -->
    <div class="ce-type-selector">
        <span class="ce-type-label">予約タイプ：</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="予約タイプの選択">
            <input type="radio" class="btn-check" name="ce-type-radio" id="ce-type-group-radio" value="2" autocomplete="off">
            <label class="btn btn-outline-primary" for="ce-type-group-radio">集団（利用者別）</label>
            <input type="radio" class="btn-check" name="ce-type-radio" id="ce-type-individual-radio" value="1" checked autocomplete="off">
            <label class="btn btn-outline-primary" for="ce-type-individual-radio">個人（部屋別）</label>
        </div>
    </div>

    <!-- 集団：ツールバー（フォーム外） -->
    <div id="ce-group-toolbar" class="ce-toolbar">
        <input type="search" id="ce-name-search" class="form-control" placeholder="氏名で絞り込み" autocomplete="off">

        <?php if (!empty($rooms) && count($rooms) > 1): ?>
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
        <?php elseif ($selectedRoomId): ?>
            <span class="text-muted small"><?= h($selectedRoomName ?: '（部屋未設定）') ?></span>
        <?php endif; ?>
    </div>

    <!-- 集団：食数サマリー（フォーム外） -->
    <div id="ce-group-summary" class="ce-summary-bar">
        <span class="ce-sum-label">食数：</span>
        <span>朝&nbsp;<span class="ce-count" data-meal-summary="1">-</span>名</span>
        <span>昼&nbsp;<span class="ce-count" data-meal-summary="2">-</span>名</span>
        <span>夕&nbsp;<span class="ce-count" data-meal-summary="3">-</span>名</span>
        <span>弁当&nbsp;<span class="ce-count" data-meal-summary="4">-</span>名</span>
    </div>

    <!-- フォーム -->
    <?= $this->Form->create(null, ['id' => 'change-edit-form', 'url' => ['action' => 'changeEdit']]) ?>
    <?= $this->Form->hidden('d_reservation_date', ['value' => $date, 'id' => 'ce-date-hidden']) ?>
    <?= $this->Form->hidden('meal_type', ['value' => $mealType, 'id' => 'ce-mealtype-hidden']) ?>
    <?= $this->Form->hidden('reservation_type', ['value' => '2', 'id' => 'ce-reservation-type-hidden']) ?>
    <?php if ($selectedRoomId): ?>
        <?= $this->Form->hidden('i_id_room', ['value' => $selectedRoomId, 'id' => 'ce-room-hidden']) ?>
    <?php endif; ?>

    <!-- ■ 個人予約セクション ■ -->
    <div id="ce-individual-section" style="display:none;">
        <div class="ce-table-wrap">
            <table class="table table-sm table-bordered" id="ce-room-table">
                <thead>
                    <tr>
                        <th class="text-start">部屋名</th>
                        <th class="text-center">朝</th>
                        <th class="text-center">昼</th>
                        <th class="text-center">夕</th>
                        <th class="text-center">弁当</th>
                    </tr>
                </thead>
                <tbody id="ce-room-tbody">
                    <?php foreach ($rooms as $rid => $rname): ?>
                    <tr data-room-id="<?= h($rid) ?>">
                        <td><?= h($rname) ?></td>
                        <td class="text-center"><input type="checkbox" class="meal-checkbox" name="meals[1][<?= h($rid) ?>]" value="1"></td>
                        <td class="text-center"><input type="checkbox" class="meal-checkbox" name="meals[2][<?= h($rid) ?>]" value="1"></td>
                        <td class="text-center"><input type="checkbox" class="meal-checkbox" name="meals[3][<?= h($rid) ?>]" value="1"></td>
                        <td class="text-center"><input type="checkbox" class="meal-checkbox" name="meals[4][<?= h($rid) ?>]" value="1"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ■ 集団予約セクション ■ -->
    <div id="ce-group-section">
        <div class="ce-table-wrap">
            <table class="table table-sm table-bordered" id="ce-table">
                <thead>
                    <tr>
                        <th class="text-start">氏名</th>
                        <th>朝<br><input type="checkbox" id="select-all-1" aria-label="朝 全選択/解除" title="全選択/解除"></th>
                        <th>昼<br><input type="checkbox" id="select-all-2" aria-label="昼 全選択/解除" title="全選択/解除"></th>
                        <th>夕<br><input type="checkbox" id="select-all-3" aria-label="夕 全選択/解除" title="全選択/解除"></th>
                        <th>弁当<br><input type="checkbox" id="select-all-4" aria-label="弁当 全選択/解除" title="全選択/解除"></th>
                    </tr>
                </thead>
                <tbody id="ce-tbody">
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>読み込み中...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- フッター -->
    <div class="ce-footer">
        <span id="ce-change-count" class="me-auto"></span>
        <span id="ce-save-spinner" class="text-muted small">
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>保存中...
        </span>
        <button type="submit" class="btn btn-primary btn-sm" id="ce-save-btn">変更を保存</button>
    </div>
    <?= $this->Form->end() ?>

    <script>
    (function(){
        var INDIV_DATA   = <?= $indivJson ?>;
        var typeHidden   = document.getElementById('ce-reservation-type-hidden');
        var groupToolbar = document.getElementById('ce-group-toolbar');
        var groupSummary = document.getElementById('ce-group-summary');
        var indivSection = document.getElementById('ce-individual-section');
        var groupSection = document.getElementById('ce-group-section');
        var _prefilled   = false;

        function setType(val) {
            if (typeHidden) typeHidden.value = val;
            var isIndiv = (val === '1');
            if (indivSection) indivSection.style.display = isIndiv ? '' : 'none';
            if (groupSection) groupSection.style.display = isIndiv ? 'none' : '';
            if (groupToolbar) groupToolbar.style.display = isIndiv ? 'none' : '';
            if (groupSummary) groupSummary.style.display = isIndiv ? 'none' : '';
            if (isIndiv && !_prefilled) {
                _prefilled = true;
                INDIV_DATA.forEach(function(r) {
                    var cb = document.querySelector('input[name="meals[' + r.type + '][' + r.room_id + ']"]');
                    if (cb) cb.checked = true;
                });
            }
        }

        document.querySelectorAll('input[name="ce-type-radio"]').forEach(function(radio) {
            radio.addEventListener('change', function() { setType(this.value); });
        });

        setType('1');
    })();
    </script>

</div>
