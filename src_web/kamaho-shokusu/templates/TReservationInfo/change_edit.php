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
$isModal  = (string)($this->request->getQuery('modal') ?? '') === '1';

$weekMap = ['日','月','火','水','木','金','土'];
$weekday = isset($date) ? ($weekMap[(int)date('w', strtotime($date))] ?? '') : '';

$this->Html->css('pages/t_reservation_add.css', ['block' => 'css']);
$this->Html->css('pages/t_reservation_change_edit.css', ['block' => 'css']);
echo $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken'));

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

    <!-- 直前編集警告バナー -->
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3 py-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
        <div>
            <strong>直前編集（当日〜14日以内）</strong>：発注済みです。変更内容をよく確認してください。<br>
            職員の既存予約の削除はできません。新規追加のみ可能です。
        </div>
    </div>

    <div class="row">
        <aside class="col-md-3" <?= $isModal ? 'style="display:none;"' : '' ?>>
            <div class="list-group">
                <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
                <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            </div>
        </aside>

        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h3><?= __('直前編集') ?></h3>
                </div>
                <div class="card-body">
                    <?= $this->Form->create(null, ['id' => 'change-edit-form', 'url' => ['action' => 'changeEdit']]) ?>
                    <fieldset class="form-section">
                        <legend><?= __('食数予約') ?></legend>

                        <!-- 予約日 -->
                        <div class="row mb-3">
                            <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                            <div class="col-sm-9">
                                <div class="d-flex align-items-center">
                                    <?= $this->Form->control('d_reservation_date', [
                                        'type'     => 'date',
                                        'label'    => false,
                                        'class'    => 'form-control',
                                        'disabled' => true,
                                        'value'    => $date,
                                    ]) ?>
                                    <span class="ms-2">(<?= h($weekday) ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- hidden fields -->
                        <?= $this->Form->hidden('d_reservation_date', ['value' => $date, 'id' => 'ce-date-hidden']) ?>
                        <?= $this->Form->hidden('meal_type', ['value' => $mealType, 'id' => 'ce-mealtype-hidden']) ?>
                        <?= $this->Form->hidden('reservation_type', ['value' => '2', 'id' => 'ce-reservation-type-hidden']) ?>
                        <?php if ($selectedRoomId): ?>
                            <?= $this->Form->hidden('i_id_room', ['value' => $selectedRoomId, 'id' => 'ce-room-hidden']) ?>
                        <?php endif; ?>

                        <!-- 予約タイプ -->
                        <div class="mb-3">
                            <label for="ce-type-select" class="form-label">予約タイプ(個人/集団)</label>
                            <select id="ce-type-select" class="form-select">
                                <option value="" disabled>-- 予約タイプを選択 --</option>
                                <option value="1">個人</option>
                                <option value="2">集団（利用者別）</option>
                            </select>
                        </div>

                        <!-- 個人：部屋ごとのチェック -->
                        <div class="mb-3 d-none" id="ce-individual-section">
                            <?= $this->Form->label('rooms', '部屋名と食事選択', ['class' => 'form-label']) ?>
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0" id="ce-room-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">部屋名</th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-room-1">朝</label>
                                                <input type="checkbox" id="select-all-room-1">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-room-2">昼</label>
                                                <input type="checkbox" id="select-all-room-2">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-room-3">夕</label>
                                                <input type="checkbox" id="select-all-room-3">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-room-4">弁当</label>
                                                <input type="checkbox" id="select-all-room-4">
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="ce-room-tbody">
                                        <?php foreach ($rooms as $rid => $rname): ?>
                                        <tr data-room-id="<?= h($rid) ?>">
                                            <td><?= h($rname) ?></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input meal-checkbox" name="meals[1][<?= h($rid) ?>]" value="1"></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input meal-checkbox" name="meals[2][<?= h($rid) ?>]" value="1"></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input meal-checkbox" name="meals[3][<?= h($rid) ?>]" value="1"></td>
                                            <td class="text-center"><input type="checkbox" class="form-check-input meal-checkbox" name="meals[4][<?= h($rid) ?>]" value="1"></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- 集団：氏名検索 + 部屋選択 -->
                        <div id="ce-group-toolbar" class="mb-3 d-none">
                            <input type="search" id="ce-name-search" class="form-control mb-2"
                                   placeholder="氏名で絞り込み" autocomplete="off">
                            <?php if (!empty($rooms) && count($rooms) > 1): ?>
                                <?= $this->Form->label('ce-room-select', '部屋を選択', ['class' => 'form-label']) ?>
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

                        <!-- 集団：食数サマリー -->
                        <div id="ce-group-summary" class="mb-3 p-2 bg-light rounded d-none">
                            <span class="fw-semibold me-2">食数：</span>
                            <span>朝&nbsp;<span class="ce-count" data-meal-summary="1">-</span>名</span>
                            <span class="ms-3">昼&nbsp;<span class="ce-count" data-meal-summary="2">-</span>名</span>
                            <span class="ms-3">夕&nbsp;<span class="ce-count" data-meal-summary="3">-</span>名</span>
                            <span class="ms-3">弁当&nbsp;<span class="ce-count" data-meal-summary="4">-</span>名</span>
                        </div>

                        <!-- 集団：利用者テーブル -->
                        <div id="ce-group-section" class="mb-3 d-none">
                            <?= $this->Form->label('users', '部屋に属する利用者と食事選択', ['class' => 'form-label']) ?>
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0" id="ce-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col" class="text-start">氏名</th>
                                            <th scope="col" class="text-center">朝<br><input type="checkbox" class="form-check-input" id="select-all-1" aria-label="朝 全選択/解除" title="全選択/解除"></th>
                                            <th scope="col" class="text-center">昼<br><input type="checkbox" class="form-check-input" id="select-all-2" aria-label="昼 全選択/解除" title="全選択/解除"></th>
                                            <th scope="col" class="text-center">夕<br><input type="checkbox" class="form-check-input" id="select-all-3" aria-label="夕 全選択/解除" title="全選択/解除"></th>
                                            <th scope="col" class="text-center">弁当<br><input type="checkbox" class="form-check-input" id="select-all-4" aria-label="弁当 全選択/解除" title="全選択/解除"></th>
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

                        <script>
                        (function(){
                            var INDIV_DATA   = <?= $indivJson ?>;
                            var typeHidden   = document.getElementById('ce-reservation-type-hidden');
                            var groupToolbar = document.getElementById('ce-group-toolbar');
                            var groupSummary = document.getElementById('ce-group-summary');
                            var indivSection = document.getElementById('ce-individual-section');
                            var groupSection = document.getElementById('ce-group-section');
                            var _prefilled   = false;

                            function showEl(el){ if (el) el.classList.remove('d-none'); }
                            function hideEl(el){ if (el) el.classList.add('d-none'); }

                            function setType(val) {
                                if (typeHidden) typeHidden.value = val;
                                var isIndiv = (val === '1');
                                if (isIndiv) {
                                    showEl(indivSection);
                                    hideEl(groupToolbar);
                                    hideEl(groupSummary);
                                    hideEl(groupSection);
                                } else {
                                    hideEl(indivSection);
                                    showEl(groupToolbar);
                                    showEl(groupSummary);
                                    showEl(groupSection);
                                }
                                if (isIndiv && !_prefilled) {
                                    _prefilled = true;
                                    INDIV_DATA.forEach(function(r) {
                                        var cb = document.querySelector('input[name="meals[' + r.type + '][' + r.room_id + ']"]');
                                        if (cb) cb.checked = true;
                                    });
                                }
                            }

                            var typeSelect = document.getElementById('ce-type-select');
                            if (typeSelect) {
                                typeSelect.addEventListener('change', function() { setType(this.value); });
                            }

                            if (typeSelect) typeSelect.value = '2';
                            setType('2');
                        })();
                        </script>

                    </fieldset>

                    <!-- 送信ボタン & ローディング -->
                    <div class="d-flex align-items-center justify-content-between mt-3">
                        <span id="ce-change-count" class="text-muted small me-auto"></span>
                        <span id="ce-save-spinner" class="text-muted small me-2">
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>保存中...
                        </span>
                        <button type="submit" class="btn btn-primary" id="ce-save-btn">変更を保存</button>
                    </div>
                    <div id="loading-overlay"
                         role="status"
                         aria-live="polite"
                         aria-label="処理中"
                         style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                        <div style="position: relative; top: 50%; transform: translateY(-50%);">
                            <div class="spinner-border text-info" aria-hidden="true"></div>
                            <p style="color: white; margin-top: 10px;">処理中です。少々お待ちください...</p>
                        </div>
                    </div>
                    <?= $this->Form->end() ?>

                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </div><!-- /.col-md-9 -->
    </div><!-- /.row -->

</div><!-- /#ce-root -->
