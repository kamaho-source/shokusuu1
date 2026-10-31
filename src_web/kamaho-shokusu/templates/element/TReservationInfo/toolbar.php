<?php
/**
 * @var \App\View\AppView $this
 * @var mixed $isStaff
 * @var mixed $mkUrl
 * @var mixed $useKidUI
 */
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 mb-2">
    <h1 class="m-0 fs-3"><?= $useKidUI ? '🍚 食数予約（中高生向け）' : '食数予約（業務）' ?></h1>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?php // 複数の日をまとめて予約するモーダルを開く。表示モードに関係なく全員に出す（treservation_multi_date.js） ?>
        <button type="button" class="btn btn-success btn-sm fw-bold" id="multiDateButton"
                data-bs-toggle="modal" data-bs-target="#multiDateModal" aria-haspopup="dialog">
            <i class="bi bi-calendar2-check" aria-hidden="true"></i> 複数日予約
        </button>
        <?php if (!$useKidUI || ($useKidUI && $isStaff)): ?>
            <span class="text-muted small d-none d-md-inline">表示モード:</span>
            <div class="btn-group" role="group" aria-label="UIモード切替">
                <a class="btn btn-sm <?= $useKidUI ? 'btn-primary' : 'btn-outline-primary' ?>"
                   href="<?= h($mkUrl(['uimode'=>'kid'])) ?>">
                    子どもUI
                </a>
                <a class="btn btn-sm <?= !$useKidUI ? 'btn-primary' : 'btn-outline-primary' ?>"
                   href="<?= h($mkUrl(['uimode'=>'biz'])) ?>">
                    業務UI
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
