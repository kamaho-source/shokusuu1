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

$mealLabels = [1 => '朝食', 2 => '昼食', 3 => '夕食', 4 => '弁当'];
$mealBadgeClasses = [
    1 => 'bg-warning text-dark',
    2 => 'bg-success',
    3 => 'bg-primary',
    4 => 'bg-info text-dark',
];
$mealLabel      = $mealLabels[(int)$mealType]      ?? "食種{$mealType}";
$mealBadgeClass = $mealBadgeClasses[(int)$mealType] ?? 'bg-secondary';

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

    <!-- 直前編集警告バナー（日付・食種を明示） -->
    <div class="alert alert-warning mb-3" role="alert">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
            <span class="fw-bold">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>直前編集（発注済み期間）
            </span>
            <span class="d-flex gap-2 align-items-center">
                <span class="badge bg-dark fs-6"><?= h($date) ?>（<?= h($weekday) ?>）</span>
                <span class="badge <?= h($mealBadgeClass) ?> fs-6"><?= h($mealLabel) ?></span>
            </span>
        </div>
        <small class="text-body-secondary">
            変更内容をよく確認してください。職員の既存予約は削除できません（追加のみ可能）。
        </small>
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

                    <!-- hidden fields -->
                    <?= $this->Form->hidden('d_reservation_date', ['value' => $date, 'id' => 'ce-date-hidden']) ?>
                    <?= $this->Form->hidden('meal_type', ['value' => $mealType, 'id' => 'ce-mealtype-hidden']) ?>
                    <?= $this->Form->hidden('reservation_type', ['value' => '2', 'id' => 'ce-reservation-type-hidden']) ?>
                    <?php if ($selectedRoomId): ?>
                        <?= $this->Form->hidden('i_id_room', ['value' => $selectedRoomId, 'id' => 'ce-room-hidden']) ?>
                    <?php endif; ?>

                    <!-- 予約タイプ：トグルボタン -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">予約タイプ</label>
                        <div class="btn-group w-100" role="group" aria-label="予約タイプ切替">
                            <button type="button" class="btn btn-outline-secondary ce-type-btn active"
                                    id="ce-btn-group" data-ce-type="2">
                                <i class="bi bi-people-fill me-1"></i>集団（利用者別）
                            </button>
                            <button type="button" class="btn btn-outline-secondary ce-type-btn"
                                    id="ce-btn-individual" data-ce-type="1">
                                <i class="bi bi-person-fill me-1"></i>個人（部屋別）
                            </button>
                        </div>
                    </div>

                    <!-- 集団：部屋選択 + 氏名検索 -->
                    <div id="ce-group-toolbar" class="mb-3 d-none">
                        <?php if (!empty($rooms) && count($rooms) > 1): ?>
                            <label for="ce-room-select" class="form-label fw-semibold">部屋を選択</label>
                            <?= $this->Form->control('i_id_room', [
                                'type'      => 'select',
                                'label'     => false,
                                'options'   => $rooms,
                                'empty'     => false,
                                'value'     => $selectedRoomId,
                                'class'     => 'form-select mb-2',
                                'required'  => true,
                                'id'        => 'ce-room-select',
                                'data-date' => $date,
                            ]) ?>
                        <?php elseif ($selectedRoomId): ?>
                            <p class="text-muted small mb-2"><?= h($selectedRoomName ?: '（部屋未設定）') ?></p>
                        <?php endif; ?>
                        <input type="search" id="ce-name-search" class="form-control"
                               placeholder="氏名で絞り込み" autocomplete="off">
                    </div>

                    <!-- 集団：食数サマリー -->
                    <div id="ce-group-summary" class="mb-3 p-2 bg-light rounded d-none">
                        <span class="fw-semibold me-2">チェック中の食数：</span>
                        <span>朝&nbsp;<span class="ce-count" data-meal-summary="1">-</span>名</span>
                        <span class="ms-3">昼&nbsp;<span class="ce-count" data-meal-summary="2">-</span>名</span>
                        <span class="ms-3">夕&nbsp;<span class="ce-count" data-meal-summary="3">-</span>名</span>
                        <span class="ms-3">弁当&nbsp;<span class="ce-count" data-meal-summary="4">-</span>名</span>
                    </div>

                    <!-- 集団：利用者テーブル -->
                    <div id="ce-group-section" class="mb-3 d-none">
                        <label class="form-label fw-semibold">部屋に属する利用者と食事選択</label>
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

                    <!-- 個人：部屋ごとのチェック -->
                    <div class="mb-3 d-none" id="ce-individual-section">
                        <label class="form-label fw-semibold">部屋名と食事選択</label>
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

                            // ボタンのアクティブ状態を更新
                            document.querySelectorAll('.ce-type-btn').forEach(function(btn) {
                                if (btn.getAttribute('data-ce-type') === val) {
                                    btn.classList.add('active');
                                    btn.classList.remove('btn-outline-secondary');
                                    btn.classList.add(isIndiv ? 'btn-secondary' : 'btn-secondary');
                                } else {
                                    btn.classList.remove('active');
                                    btn.classList.add('btn-outline-secondary');
                                    btn.classList.remove('btn-secondary');
                                }
                            });

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

                        document.querySelectorAll('.ce-type-btn').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                setType(this.getAttribute('data-ce-type'));
                            });
                        });

                        setType('2');
                    })();
                    </script>

                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </div><!-- /.col-md-9 -->
    </div><!-- /.row -->

</div><!-- /#ce-root -->
