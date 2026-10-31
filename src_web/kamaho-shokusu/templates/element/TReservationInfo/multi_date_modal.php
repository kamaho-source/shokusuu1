<?php
/**
 * 複数日予約のモーダル。中身は treservation_multi_date.js が描画する。
 *
 * @var \App\View\AppView $this
 * @var bool $isChild
 */
?>
<div class="modal fade" id="multiDateModal" tabindex="-1" aria-labelledby="multiDateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down multi-date-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6 fw-bold" id="multiDateModalTitle">🗓️ 複数日予約：予約日を選ぶ</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="multi-date-row" id="multiDateRoomRow" hidden>
                    <span class="multi-date-label">部屋</span>
                    <div class="d-flex flex-wrap gap-2" id="multiDateRooms" role="group" aria-label="部屋"></div>
                </div>
                <div class="multi-date-row">
                    <span class="multi-date-label">食事</span>
                    <div class="d-flex flex-wrap gap-2" id="multiDateMeals" role="group" aria-label="食事"></div>
                </div>
                <div class="small text-muted mb-2" id="multiDateHint"></div>
                <div class="d-flex align-items-center justify-content-center gap-3 my-2">
                    <button type="button" class="btn btn-sm btn-link text-dark" id="multiDatePrev" aria-label="前の月">◀</button>
                    <span class="fw-bold fs-6" id="multiDateMonth"></span>
                    <button type="button" class="btn btn-sm btn-link text-dark" id="multiDateNext" aria-label="次の月">▶</button>
                </div>
                <div id="multiDateCalendar"></div>
                <div class="multi-date-legend small text-secondary my-2">
                    <span><i class="multi-date-dot" style="background:#28a745"></i>自分の予約あり</span>
                    <span><i class="multi-date-dot" style="background:#fd7e14"></i>未予約</span>
                    <span><i class="multi-date-dot" style="background:#dc3545"></i>祝日</span>
                    <span>✓ 選択中</span>
                    <span>🔒 選んだ食事が登録済み</span>
                    <span class="text-body-tertiary">灰色＝選べない日（過去日など）</span>
                </div>
                <div id="multiDateSummary" class="my-2"></div>
                <div id="multiDateChips"></div>
                <div class="alert alert-warning py-2 small mt-2 mb-0" id="multiDateLastMinute" hidden>
                    <?php if (!empty($isChild)): ?>
                        ⚠️ 直前期間（今日〜14日後）の日が含まれます。お店に注文済みのため、登録した後に変えることは基本できません。
                    <?php else: ?>
                        ⚠️ 直前期間（今日〜14日後）の日が含まれます。登録後、職員は取消できません。
                    <?php endif; ?>
                </div>
                <div class="small mt-2 mb-0" id="multiDateResult" role="status" aria-live="polite" hidden></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-success fw-bold" id="multiDateSubmit" disabled>✅ 登録する（0件）</button>
            </div>
        </div>
    </div>
</div>
