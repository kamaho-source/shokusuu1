<?php
/**
 * サイボウズモード（複数日まとめて予約）のモーダル。中身は treservation_cybozu.js が描画する。
 *
 * @var \App\View\AppView $this
 */
?>
<div class="modal fade" id="cybozuModal" tabindex="-1" aria-labelledby="cybozuModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down cybozu-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6 fw-bold" id="cybozuModalTitle">🗓️ サイボウズモード：予約日を選ぶ</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="cybozu-row" id="cybozuRoomRow" hidden>
                    <span class="cybozu-label">部屋</span>
                    <div class="d-flex flex-wrap gap-2" id="cybozuRooms" role="group" aria-label="部屋"></div>
                </div>
                <div class="cybozu-row">
                    <span class="cybozu-label">食事</span>
                    <div class="d-flex flex-wrap gap-2" id="cybozuMeals" role="group" aria-label="食事"></div>
                </div>
                <div class="small text-muted mb-2" id="cybozuHint"></div>
                <div class="d-flex align-items-center justify-content-center gap-3 my-2">
                    <button type="button" class="btn btn-sm btn-link text-dark" id="cybozuPrev" aria-label="前の月">◀</button>
                    <span class="fw-bold fs-6" id="cybozuMonth"></span>
                    <button type="button" class="btn btn-sm btn-link text-dark" id="cybozuNext" aria-label="次の月">▶</button>
                </div>
                <div id="cybozuCalendar"></div>
                <div class="cybozu-legend small text-secondary my-2">
                    <span><i class="cybozu-dot" style="background:#28a745"></i>自分の予約あり</span>
                    <span><i class="cybozu-dot" style="background:#fd7e14"></i>未予約</span>
                    <span><i class="cybozu-dot" style="background:#dc3545"></i>祝日</span>
                    <span>✓ 選択中</span>
                    <span>🔒 選んだ食事が登録済み</span>
                    <span class="text-body-tertiary">灰色＝過去日</span>
                </div>
                <div id="cybozuSummary" class="my-2"></div>
                <div id="cybozuChips"></div>
                <div class="alert alert-warning py-2 small mt-2 mb-0" id="cybozuLastMinute" hidden>
                    ⚠️ 直前期間（今日〜14日後）の日が含まれます。登録後、職員は取消できません。
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-success fw-bold" id="cybozuSubmit" disabled>✅ 登録する（0件）</button>
            </div>
        </div>
    </div>
</div>
