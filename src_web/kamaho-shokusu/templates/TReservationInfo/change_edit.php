<?php
/**
 * 直前編集ビュー
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
echo $this->Html->css('pages/t_reservation_change_edit.css');
?>
<div id="ce-root"
     data-base="<?= h($basePath) ?>"
     data-date="<?= h($date) ?>"
     data-mealtype="<?= h($mealType) ?>">

    <!-- 警告ヘッダ -->
    <div class="ce-warning-banner">
        <span class="ce-date-label">
            &#9888; 直前編集：<?= h($date) ?>
        </span>
        <span class="ce-warning-note">発注済みです。変更内容をよく確認してください。</span>
    </div>

    <!-- ツールバー -->
    <div class="ce-toolbar">
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

    <!-- 食数サマリー -->
    <div class="ce-summary-bar">
        <span class="ce-sum-label">食数：</span>
        <span>朝&nbsp;<span class="ce-count" data-meal-summary="1">-</span>名</span>
        <span>昼&nbsp;<span class="ce-count" data-meal-summary="2">-</span>名</span>
        <span>夕&nbsp;<span class="ce-count" data-meal-summary="3">-</span>名</span>
        <span>弁当&nbsp;<span class="ce-count" data-meal-summary="4">-</span>名</span>
    </div>

    <!-- フォーム＋テーブル -->
    <?= $this->Form->create(null, ['id' => 'change-edit-form', 'url' => ['action' => 'changeEdit']]) ?>
    <?= $this->Form->hidden('d_reservation_date', ['value' => $date, 'id' => 'ce-date-hidden']) ?>
    <?= $this->Form->hidden('meal_type', ['value' => $mealType, 'id' => 'ce-mealtype-hidden']) ?>
    <?php if ($selectedRoomId): ?>
        <?= $this->Form->hidden('i_id_room', ['value' => $selectedRoomId, 'id' => 'ce-room-hidden']) ?>
    <?php endif; ?>

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

    <!-- フッター -->
    <div class="ce-footer">
        <span id="ce-change-count" class="me-auto"></span>
        <span id="ce-save-spinner" class="text-muted small">
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>保存中...
        </span>
        <button type="submit" class="btn btn-primary btn-sm" id="ce-save-btn">変更を保存</button>
    </div>
    <?= $this->Form->end() ?>

</div>
