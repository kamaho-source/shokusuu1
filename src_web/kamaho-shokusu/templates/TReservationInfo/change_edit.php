<?php
/**
 * 直前編集ビュー（再設計版）
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
        /* ===== 直前編集モーダル スタイル（#ce-rootスコープ） ===== */

        #ce-root {
            font-size: .9rem;
        }

        /* 警告ヘッダバナー */
        #ce-root .ce-warning-banner {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: #fff;
            padding: .65rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
        }
        #ce-root .ce-warning-banner .ce-date-label {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .02em;
        }
        #ce-root .ce-warning-banner .ce-warning-note {
            font-size: .78rem;
            opacity: .9;
            white-space: nowrap;
        }

        /* 部屋セレクタ + 検索バー */
        #ce-root .ce-toolbar {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: .5rem .75rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
        }
        #ce-root .ce-toolbar .form-control,
        #ce-root .ce-toolbar .form-select {
            font-size: .85rem;
            height: 2rem;
            padding-top: .25rem;
            padding-bottom: .25rem;
        }
        #ce-root #ce-name-search { max-width: 180px; }
        #ce-root .ce-toolbar .form-select { max-width: 160px; }

        /* 食数サマリーバー */
        #ce-root .ce-summary-bar {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: .4rem .75rem;
            display: flex;
            align-items: center;
            gap: .5rem 1rem;
            flex-wrap: wrap;
            font-size: .82rem;
        }
        #ce-root .ce-meal-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .15rem .55rem;
            border-radius: 999px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        #ce-root .ce-meal-pill.morning { background: #fff3cd; border-color: #ffc107; color: #664d03; }
        #ce-root .ce-meal-pill.lunch   { background: #d1e7dd; border-color: #198754; color: #0a3622; }
        #ce-root .ce-meal-pill.dinner  { background: #cff4fc; border-color: #0dcaf0; color: #055160; }
        #ce-root .ce-meal-pill.bento   { background: #f8d7da; border-color: #dc3545; color: #58151c; }
        #ce-root .ce-meal-pill .ce-count { font-size: .9em; }

        /* テーブル */
        #ce-root .ce-table-wrap {
            overflow-x: auto;
            overflow-y: auto;
        }
        #ce-root #ce-table {
            margin-bottom: 0;
            min-width: 380px;
        }
        #ce-root #ce-table thead th {
            background: #f8f9fa;
            font-size: .78rem;
            font-weight: 700;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            padding: .4rem .3rem;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        #ce-root #ce-table thead th:first-child {
            text-align: left;
            padding-left: .75rem;
            min-width: 140px;
        }
        /* 列カラー */
        #ce-root #ce-table thead th.col-morning { color: #856404; border-top: 3px solid #ffc107; }
        #ce-root #ce-table thead th.col-lunch   { color: #146c43; border-top: 3px solid #198754; }
        #ce-root #ce-table thead th.col-dinner  { color: #055160; border-top: 3px solid #0dcaf0; }
        #ce-root #ce-table thead th.col-bento   { color: #842029; border-top: 3px solid #dc3545; }

        #ce-root #ce-table td {
            text-align: center;
            vertical-align: middle;
            padding: .35rem .3rem;
        }
        #ce-root #ce-table td:first-child {
            text-align: left;
            padding-left: .75rem;
            font-weight: 500;
        }
        #ce-root #ce-table tbody tr:hover { background: #f0f4ff; }
        #ce-root #ce-table tbody tr.ce-row-hidden { display: none; }

        /* チェックボックス */
        #ce-root .meal-checkbox,
        #ce-root #ce-table thead input[type="checkbox"] {
            width: 1.15rem;
            height: 1.15rem;
            cursor: pointer;
            accent-color: #0d6efd;
        }
        #ce-root .meal-checkbox:disabled { cursor: not-allowed; opacity: .45; }
        #ce-root .meal-checkbox[data-locked="1"] { accent-color: #6c757d; }

        /* 変更あり行ハイライト */
        #ce-root #ce-table tbody tr.ce-row-changed { background: #fff8e1 !important; }

        /* ロック済みバッジ */
        #ce-root .ce-locked-label {
            font-size: .7rem;
            color: #6c757d;
            display: block;
            line-height: 1;
        }

        /* フッターアクション */
        #ce-root .ce-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .6rem .75rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .5rem;
        }
        #ce-root .ce-footer .btn { font-size: .85rem; }

        /* 保存中スピナー */
        #ce-root #ce-save-spinner { display: none; }
    </style>

    <!-- 警告バナー -->
    <div class="ce-warning-banner">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <span class="ce-date-label">直前編集：<?= h($date) ?></span>
        </div>
        <span class="ce-warning-note">
            <i class="bi bi-info-circle me-1"></i>この日はすでに発注済みです。変更内容をよく確認してください。
        </span>
    </div>

    <!-- ツールバー（部屋選択・検索） -->
    <div class="ce-toolbar">
        <i class="bi bi-search text-muted" aria-hidden="true"></i>
        <input type="search" id="ce-name-search" class="form-control" placeholder="氏名で絞り込み" autocomplete="off">

        <?php if (!empty($rooms) && count($rooms) > 1): ?>
            <i class="bi bi-door-open text-muted ms-1" aria-hidden="true"></i>
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
            <span class="text-muted small ms-1">
                <i class="bi bi-door-open me-1"></i><?= h($selectedRoomName ?: '（部屋未設定）') ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- 食数サマリー -->
    <div class="ce-summary-bar">
        <span class="text-muted fw-semibold me-1">食数：</span>
        <span class="ce-meal-pill morning">
            <i class="bi bi-brightness-high-fill"></i>朝&nbsp;<span class="ce-count" data-meal-summary="1">-</span>名
        </span>
        <span class="ce-meal-pill lunch">
            <i class="bi bi-sun-fill"></i>昼&nbsp;<span class="ce-count" data-meal-summary="2">-</span>名
        </span>
        <span class="ce-meal-pill dinner">
            <i class="bi bi-moon-fill"></i>夕&nbsp;<span class="ce-count" data-meal-summary="3">-</span>名
        </span>
        <span class="ce-meal-pill bento">
            <i class="bi bi-box-seam-fill"></i>弁当&nbsp;<span class="ce-count" data-meal-summary="4">-</span>名
        </span>
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
                    <th class="text-start">
                        氏名
                    </th>
                    <th class="col-morning">
                        <i class="bi bi-brightness-high-fill d-block mb-1"></i>朝<br>
                        <input type="checkbox" id="select-all-1" aria-label="朝 全選択/解除" title="全選択/解除">
                    </th>
                    <th class="col-lunch">
                        <i class="bi bi-sun-fill d-block mb-1"></i>昼<br>
                        <input type="checkbox" id="select-all-2" aria-label="昼 全選択/解除" title="全選択/解除">
                    </th>
                    <th class="col-dinner">
                        <i class="bi bi-moon-fill d-block mb-1"></i>夕<br>
                        <input type="checkbox" id="select-all-3" aria-label="夕 全選択/解除" title="全選択/解除">
                    </th>
                    <th class="col-bento">
                        <i class="bi bi-box-seam-fill d-block mb-1"></i>弁当<br>
                        <input type="checkbox" id="select-all-4" aria-label="弁当 全選択/解除" title="全選択/解除">
                    </th>
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
        <span id="ce-change-count" class="text-muted small me-auto"></span>
        <span id="ce-save-spinner" class="text-muted small">
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>保存中...
        </span>
        <?= $this->Form->button('<i class="bi bi-floppy-fill me-1"></i>変更を保存', [
            'type'   => 'submit',
            'class'  => 'btn btn-primary btn-sm',
            'id'     => 'ce-save-btn',
            'escape' => false,
        ]) ?>
    </div>
    <?= $this->Form->end() ?>

</div>
