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
?>
<div id="ce-root"
     data-base="<?= h($basePath) ?>"
     data-date="<?= h($date) ?>"
     data-mealtype="<?= h($mealType) ?>">

    <style>
        /* ===== 直前編集モーダル（#ce-rootスコープ） ===== */
        #ce-root { font-size: .88rem; }

        /* 警告ヘッダ */
        #ce-root .ce-warning-banner {
            background: #fff3cd;
            border-bottom: 1px solid #ffc107;
            padding: .5rem .85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            flex-wrap: wrap;
            color: #664d03;
        }
        #ce-root .ce-warning-banner .ce-date-label {
            font-weight: 700;
            font-size: .92rem;
        }
        #ce-root .ce-warning-banner .ce-warning-note {
            font-size: .78rem;
            color: #856404;
        }

        /* ツールバー */
        #ce-root .ce-toolbar {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: .4rem .75rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
        }
        #ce-root .ce-toolbar .form-control,
        #ce-root .ce-toolbar .form-select {
            font-size: .83rem;
            height: 1.9rem;
            padding-top: .2rem;
            padding-bottom: .2rem;
        }
        #ce-root #ce-name-search { max-width: 170px; }
        #ce-root .ce-toolbar .form-select { max-width: 150px; }

        /* 食数サマリーバー */
        #ce-root .ce-summary-bar {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: .35rem .75rem;
            display: flex;
            align-items: center;
            gap: .4rem 1rem;
            flex-wrap: wrap;
            font-size: .8rem;
            color: #495057;
        }
        #ce-root .ce-summary-bar .ce-sum-label { font-weight: 600; }
        #ce-root .ce-summary-bar .ce-count { font-weight: 700; color: #0d6efd; }

        /* テーブル */
        #ce-root .ce-table-wrap { overflow-x: auto; overflow-y: auto; }
        #ce-root #ce-table { margin-bottom: 0; min-width: 360px; }

        #ce-root #ce-table thead th {
            background: #f1f3f5;
            font-size: .78rem;
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            padding: .4rem .4rem;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 1;
            color: #495057;
        }
        #ce-root #ce-table thead th:first-child {
            text-align: left;
            padding-left: .75rem;
            min-width: 140px;
        }

        #ce-root #ce-table td {
            text-align: center;
            vertical-align: middle;
            padding: .3rem .4rem;
        }
        #ce-root #ce-table td:first-child {
            text-align: left;
            padding-left: .75rem;
        }
        #ce-root #ce-table tbody tr:hover { background: #f8f9fa; }
        #ce-root #ce-table tbody tr.ce-row-hidden { display: none; }
        #ce-root #ce-table tbody tr.ce-row-changed { background: #e8f4e8 !important; }

        /* チェックボックス */
        #ce-root .meal-checkbox,
        #ce-root #ce-table thead input[type="checkbox"] {
            width: 1.1rem;
            height: 1.1rem;
            cursor: pointer;
        }
        #ce-root .meal-checkbox:disabled { cursor: not-allowed; opacity: .4; }

        /* フッター */
        #ce-root .ce-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .5rem .75rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .5rem;
        }
        #ce-root .ce-footer .btn { font-size: .83rem; }
        #ce-root #ce-save-spinner { display: none; }
        #ce-root #ce-change-count { font-size: .78rem; color: #6c757d; }
    </style>

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
