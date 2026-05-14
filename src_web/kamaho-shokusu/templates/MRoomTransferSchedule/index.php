<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $schedules
 * @var string|null $statusFilter
 */

$this->assign('title', '部屋異動予約一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

$statusLabels = [
    0 => ['label' => '予約中',    'class' => 'text-bg-warning',  'icon' => 'bi-clock'],
    1 => ['label' => '適用済み',  'class' => 'text-bg-success',  'icon' => 'bi-check-circle-fill'],
    2 => ['label' => 'キャンセル','class' => 'text-bg-secondary', 'icon' => 'bi-x-circle-fill'],
];

$today = new \DateTime('today');
$dayNames = ['日', '月', '火', '水', '木', '金', '土'];
?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
#rts-popup-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    backdrop-filter: blur(2px);
    z-index: 1040;
    animation: rts-overlay-in .15s ease;
}
#rts-popup-overlay.show { display: block; }

#rts-confirm-popup {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -60%) scale(.92);
    background: #fff;
    border-radius: 16px;
    padding: 28px 28px 22px;
    width: min(380px, 92vw);
    box-shadow: 0 20px 60px rgba(0,0,0,.22);
    z-index: 1050;
    text-align: center;
    transition: transform .18s cubic-bezier(.34,1.56,.64,1), opacity .15s ease;
    opacity: 0;
}
#rts-confirm-popup.show {
    display: block;
    transform: translate(-50%, -50%) scale(1);
    opacity: 1;
}
.rts-popup-icon-wrap {
    width: 52px;
    height: 52px;
    margin: 0 auto 14px;
    background: #fff1f2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.rts-popup-icon-svg {
    width: 28px;
    height: 28px;
    stroke: #ef4444;
}
#rts-popup-message {
    font-size: .95rem;
    font-weight: 500;
    color: #374151;
    margin: 0 0 20px;
    line-height: 1.6;
}
.rts-popup-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.rts-popup-actions button {
    flex: 1;
    padding: 9px 0;
    border: none;
    border-radius: 10px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    transition: filter .15s;
}
.rts-popup-actions button:hover { filter: brightness(.93); }
#rts-popup-cancel { background: #f3f4f6; color: #6b7280; }
#rts-popup-ok     { background: #ef4444; color: #fff; }

@keyframes rts-overlay-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
</style>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">
            <i class="bi bi-arrow-left-right"></i> 部屋異動予約一覧
        </h3>
        <?= $this->Html->link(
            '<i class="bi bi-plus-circle"></i> 新規登録',
            ['action' => 'add'],
            ['class' => 'btn btn-success', 'escape' => false]
        ) ?>
    </div>

    <!-- ステータスフィルター -->
    <div class="mb-3 d-flex gap-2 flex-wrap">
        <?= $this->Html->link('すべて', ['action' => 'index'], [
            'class' => 'btn btn-sm ' . ($statusFilter === null ? 'btn-primary' : 'btn-outline-primary'),
        ]) ?>
        <?= $this->Html->link(
            '<i class="bi bi-clock"></i> 予約中',
            ['action' => 'index', '?' => ['status' => '0']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '0' ? 'btn-warning' : 'btn-outline-warning'), 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-check-circle-fill"></i> 適用済み',
            ['action' => 'index', '?' => ['status' => '1']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '1' ? 'btn-success' : 'btn-outline-success'), 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-x-circle-fill"></i> キャンセル',
            ['action' => 'index', '?' => ['status' => '2']],
            ['class' => 'btn btn-sm ' . ($statusFilter === '2' ? 'btn-secondary' : 'btn-outline-secondary'), 'escape' => false]
        ) ?>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-nowrap">対象ユーザー</th>
                    <th class="text-nowrap">異動元部屋</th>
                    <th class="text-nowrap">異動先部屋</th>
                    <th class="text-nowrap">有効開始日</th>
                    <th class="text-nowrap">ステータス</th>
                    <th class="text-nowrap">登録者</th>
                    <th class="text-nowrap">登録日時</th>
                    <th class="text-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowCount = 0; ?>
            <?php foreach ($schedules as $schedule): ?>
                <?php
                    $rowCount++;
                    $statusInfo = $statusLabels[(int)$schedule->i_status] ?? ['label' => '不明', 'class' => 'text-bg-dark', 'icon' => 'bi-question'];

                    $effectiveDateObj = $schedule->d_effective_date instanceof \DateTime
                        ? $schedule->d_effective_date
                        : new \DateTime((string)$schedule->d_effective_date);
                    $diff = (int)$today->diff($effectiveDateObj)->days * ($effectiveDateObj >= $today ? 1 : -1);
                    $dow  = $dayNames[(int)$effectiveDateObj->format('w')];
                    $dateFormatted = $effectiveDateObj->format('Y/m/d') . "（{$dow}）";

                    if ($diff < 0) {
                        $dateBadge = '<span class="text-muted">' . h($dateFormatted) . '</span>';
                    } elseif ($diff === 0) {
                        $dateBadge = '<span class="badge text-bg-danger">本日 ' . h($dateFormatted) . '</span>';
                    } elseif ($diff <= 7) {
                        $dateBadge = '<span class="badge text-bg-warning text-dark">まもなく ' . h($dateFormatted) . '</span>';
                    } else {
                        $dateBadge = '<span class="fw-semibold">' . h($dateFormatted) . '</span>';
                    }

                    $dtCreate = $schedule->dt_create
                        ? (new \DateTime((string)$schedule->dt_create))->format('Y/m/d H:i')
                        : '-';

                    $cancelMessage = sprintf(
                        '%s の異動予約（→ %s）をキャンセルしますか？',
                        $schedule->m_user_info->c_user_name ?? '',
                        $schedule->room_to->c_room_name ?? ''
                    );
                    $cancelUrl = $this->Url->build(['action' => 'cancel', $schedule->i_id]);
                ?>
                <tr>
                    <td><?= h($schedule->m_user_info->c_user_name ?? '-') ?></td>
                    <td>
                        <?php if ($schedule->room_from): ?>
                            <?= h($schedule->room_from->c_room_name) ?>
                        <?php else: ?>
                            <span class="text-muted fst-italic">新規配属</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= h($schedule->room_to->c_room_name ?? '-') ?></td>
                    <td class="text-nowrap"><?= $dateBadge ?></td>
                    <td>
                        <span class="badge rounded-pill <?= $statusInfo['class'] ?>">
                            <i class="bi <?= $statusInfo['icon'] ?> me-1"></i><?= h($statusInfo['label']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= h($schedule->c_create_user) ?></td>
                    <td class="text-muted small text-nowrap"><?= h($dtCreate) ?></td>
                    <td>
                        <?php if ((int)$schedule->i_status === 0): ?>
                            <!-- キャンセル用フォーム（非表示） -->
                            <?= $this->Form->create(null, [
                                'url'    => ['action' => 'cancel', $schedule->i_id],
                                'method' => 'post',
                                'id'     => 'cancel-form-' . $schedule->i_id,
                                'style'  => 'display:none',
                            ]) ?>
                            <?= $this->Form->end() ?>

                            <button type="button"
                                    class="btn btn-sm btn-outline-danger rts-cancel-btn"
                                    data-form-id="cancel-form-<?= $schedule->i_id ?>"
                                    data-message="<?= h($cancelMessage) ?>">
                                <i class="bi bi-x-circle"></i> キャンセル
                            </button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                        登録されている異動予約はありません。
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 確認モーダル -->
<div id="rts-popup-overlay"></div>
<div id="rts-confirm-popup" role="dialog" aria-modal="true" aria-labelledby="rts-popup-message">
    <div class="rts-popup-icon-wrap">
        <svg class="rts-popup-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <circle cx="12" cy="16" r=".5" fill="currentColor"/>
        </svg>
    </div>
    <p id="rts-popup-message"></p>
    <div class="rts-popup-actions">
        <button id="rts-popup-cancel" type="button">戻る</button>
        <button id="rts-popup-ok"     type="button">キャンセルする</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const overlay      = document.getElementById('rts-popup-overlay');
    const popup        = document.getElementById('rts-confirm-popup');
    const messageEl    = document.getElementById('rts-popup-message');
    const okBtn        = document.getElementById('rts-popup-ok');
    const cancelBtn    = document.getElementById('rts-popup-cancel');

    let pendingFormId = null;

    function showModal(message, formId) {
        messageEl.textContent = message;
        pendingFormId = formId;
        overlay.classList.add('show');
        popup.classList.add('show');
        okBtn.focus();
    }

    function hideModal() {
        overlay.classList.remove('show');
        popup.classList.remove('show');
        pendingFormId = null;
    }

    okBtn.addEventListener('click', () => {
        if (pendingFormId) {
            document.getElementById(pendingFormId)?.submit();
        }
        hideModal();
    });

    cancelBtn.addEventListener('click', hideModal);
    overlay.addEventListener('click', hideModal);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && popup.classList.contains('show')) hideModal();
    });

    document.querySelectorAll('.rts-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            showModal(btn.dataset.message, btn.dataset.formId);
        });
    });
});
</script>
