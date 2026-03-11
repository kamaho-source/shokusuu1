<?php
/**
 * 子ども向け：1か月分まとめて登録モーダル
 *
 * 受け取る変数:
 *   array  $authorizedRooms  [id => name]
 *   string $currentRoomId
 */
$authorizedRooms = isset($authorizedRooms) ? $authorizedRooms : [];
$currentRoomId   = isset($currentRoomId) ? $currentRoomId : '';

// 登録可能期間：今日+15日目〜今日+45日（1か月分）
$todayDt  = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
$minDt    = $todayDt->modify('+15 days');   // 通常予約の最短
$maxDt    = $todayDt->modify('+45 days');   // 約1か月先

$bulkSubmitUrl = $this->Url->build(['controller' => 'TReservationInfo', 'action' => 'bulkAddSubmit']);
$csrfToken     = (string)($this->request->getAttribute('csrfToken') ?? '');
?>

<!-- 1か月まとめ登録 ボタン -->
<div class="d-flex justify-content-end mb-2">
    <button class="btn btn-outline-success btn-sm"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#kidMonthlyModal"
            id="kidMonthlyOpenBtn">
        <i class="bi bi-calendar-month"></i> 1か月分まとめて登録
    </button>
</div>

<!-- モーダル本体 -->
<div class="modal fade" id="kidMonthlyModal" tabindex="-1"
     aria-labelledby="kidMonthlyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <!-- ヘッダー -->
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="kidMonthlyModalLabel">
                    <i class="bi bi-calendar-month"></i> 1か月分まとめて登録
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>

            <!-- ボディ -->
            <div class="modal-body">

                <!-- 部屋選択 -->
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="bi bi-door-open"></i> 利用する部屋</label>
                    <select id="kidMonthlyRoomSelect" class="form-select" title="利用する部屋を選択">
                        <option value="">部屋を選択してください</option>
                        <?php foreach ($authorizedRooms as $rid => $rname): ?>
                            <option value="<?= h($rid) ?>"
                                <?= (string)$currentRoomId === (string)$rid ? 'selected' : '' ?>>
                                <?= h($rname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 食事種別 選択（全期間に適用） -->
                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="bi bi-egg-fried"></i> 食事の種類を選ぶ（チェックした種類を全日付に登録）</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php
                        $mealDefs = [
                            1 => ['label'=>'☀️ 朝食',  'color'=>'success'],
                            2 => ['label'=>'🌞 昼食',  'color'=>'warning'],
                            3 => ['label'=>'🌙 夕食',  'color'=>'primary'],
                            4 => ['label'=>'🍱 弁当',  'color'=>'danger'],
                        ];
                        foreach ($mealDefs as $mt => $md): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input kid-monthly-meal-chk"
                                       type="checkbox"
                                       id="kidMonthlyMeal<?= $mt ?>"
                                       value="<?= $mt ?>"
                                       data-color="<?= h($md['color']) ?>">
                                <label class="form-check-label fw-bold text-<?= h($md['color']) ?>"
                                       for="kidMonthlyMeal<?= $mt ?>">
                                    <?= h($md['label']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text text-danger" id="kidMonthlyMealError" style="display:none;">
                        食事の種類を1つ以上選んでください。
                    </div>
                    <div class="form-text text-danger" id="kidMonthlyLunchBentoError" style="display:none;">
                        昼食と弁当は同時に選べません。
                    </div>
                </div>

                <!-- 日付カレンダー選択 -->
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label fw-bold mb-0">
                            <i class="bi bi-calendar-check"></i>
                            登録する日付を選ぶ
                            <small class="text-muted fw-normal">（<?= $minDt->format('n月j日') ?>〜<?= $maxDt->format('n月j日') ?>）</small>
                        </label>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="kidMonthlyCheckAll">すべて選択</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="kidMonthlyUncheckAll">すべて解除</button>
                        </div>
                    </div>

                    <!-- 曜日ヘッダー -->
                    <div class="kid-monthly-cal-grid kid-monthly-cal-header">
                        <?php foreach(['日','月','火','水','木','金','土'] as $i => $dl): ?>
                            <div class="kid-monthly-dow <?= $i===0?'dow-sun':($i===6?'dow-sat':'') ?>">
                                <?= $dl ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- カレンダーグリッド -->
                    <div class="kid-monthly-cal-grid" id="kidMonthlyCalGrid">
                        <?php
                        // グリッド開始：$minDt の週の日曜日
                        $gridStart = $minDt->modify('sunday this week');
                        if ($gridStart > $minDt) {
                            $gridStart = $gridStart->modify('-7 days');
                        }

                        $cursor = $gridStart;
                        $endGrid = $maxDt->modify('saturday this week');
                        if ($endGrid < $maxDt) {
                            $endGrid = $endGrid->modify('+7 days');
                        }

                        while ($cursor <= $endGrid):
                            $dateKey  = $cursor->format('Y-m-d');
                            $dow      = (int)$cursor->format('w');
                            $inRange  = ($cursor >= $minDt && $cursor <= $maxDt);
                            $isSun    = ($dow === 0);
                            $isSat    = ($dow === 6);
                        ?>
                            <?php if ($inRange): ?>
                                <label class="kid-monthly-cell <?= $isSun?'cell-sun':'' ?><?= $isSat?' cell-sat':'' ?>"
                                       for="kidMonthlyDate_<?= h($dateKey) ?>">
                                    <input type="checkbox"
                                           id="kidMonthlyDate_<?= h($dateKey) ?>"
                                           class="kid-monthly-date-chk visually-hidden"
                                           value="<?= h($dateKey) ?>">
                                    <span class="kid-monthly-day-num"><?= (int)$cursor->format('j') ?></span>
                                    <span class="kid-monthly-month-badge"><?= (int)$cursor->format('n') ?>/</span>
                                    <span class="kid-monthly-meal-icons" data-date="<?= h($dateKey) ?>"></span>
                                </label>
                            <?php else: ?>
                                <div class="kid-monthly-cell cell-disabled"></div>
                            <?php endif; ?>
                        <?php
                            $cursor = $cursor->modify('+1 day');
                        endwhile;
                        ?>
                    </div>
                    <div class="form-text text-danger mt-1" id="kidMonthlyDateError" style="display:none;">
                        日付を1日以上選んでください。
                    </div>
                </div>

                <!-- 選択中サマリー -->
                <div class="alert alert-info py-2 small mt-2" id="kidMonthlySummary">
                    日付と食事の種類を選んでください。
                </div>

            </div>

            <!-- フッター -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-success" id="kidMonthlySubmitBtn" disabled>
                    <span class="spinner-border spinner-border-sm d-none me-1" id="kidMonthlySpinner"></span>
                    <i class="bi bi-check-circle"></i> まとめて登録
                </button>
            </div>

        </div>
    </div>
</div>

<!-- hidden: 送信先URL と CSRFトークン (JSから参照) -->
<input type="hidden" id="kidMonthlyBulkSubmitUrl" value="<?= h($bulkSubmitUrl) ?>">
<input type="hidden" id="kidMonthlyCsrfToken" value="<?= h($csrfToken) ?>">

<style>
.kid-monthly-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 3px;
}
.kid-monthly-cal-header .kid-monthly-dow {
    text-align: center;
    font-weight: bold;
    font-size: .78rem;
    padding: 4px 0;
    color: #555;
}
.kid-monthly-cal-header .dow-sun { color: #e44; }
.kid-monthly-cal-header .dow-sat { color: #44b; }

.kid-monthly-cell {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4px 2px;
    border-radius: 6px;
    cursor: pointer;
    border: 2px solid #dee2e6;
    min-height: 52px;
    font-size: .82rem;
    transition: background .15s, border-color .15s;
    user-select: none;
    position: relative;
}
.kid-monthly-cell:hover { background: #f0f8f0; border-color: #28a745; }
.kid-monthly-cell.cell-sun .kid-monthly-day-num { color: #e44; }
.kid-monthly-cell.cell-sat .kid-monthly-day-num { color: #44b; }
.kid-monthly-cell.cell-disabled {
    background: #f8f8f8;
    cursor: default;
    border-color: #f0f0f0;
}
.kid-monthly-cell.cell-selected {
    background: #d4edda !important;
    border-color: #28a745 !important;
}
.kid-monthly-cell .kid-monthly-month-badge {
    font-size: .65rem;
    color: #888;
    position: absolute;
    top: 3px;
    left: 5px;
}
.kid-monthly-cell .kid-monthly-day-num {
    font-weight: bold;
    font-size: .9rem;
}
.kid-monthly-cell .kid-monthly-meal-icons {
    font-size: .65rem;
    min-height: 14px;
    color: #555;
    margin-top: 1px;
    letter-spacing: -.05em;
}
</style>