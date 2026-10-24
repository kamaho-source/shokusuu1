<?php
/**
 * @var \App\View\AppView $this
 * @var bool $isChild
 * @var mixed $mkUrl
 * @var mixed $useKidUI
 * @var bool $useCybozu
 */
$isBiz = !$useKidUI && !$useCybozu;
$title = $useKidUI ? '🍚 食数予約（中高生向け）' : ($useCybozu ? '食数予約（サイボウズモード）' : '食数予約（業務）');
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 mb-2">
    <h1 class="m-0 fs-3"><?= h($title) ?></h1>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-none d-md-inline">表示モード:</span>
        <div class="btn-group" role="group" aria-label="UIモード切替">
            <a class="btn btn-sm <?= $useKidUI ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= h($mkUrl(['uimode'=>'kid'])) ?>">
                子どもUI
            </a>
            <?php if (!$isChild): ?>
                <a class="btn btn-sm <?= $isBiz ? 'btn-primary' : 'btn-outline-primary' ?>"
                   href="<?= h($mkUrl(['uimode'=>'biz'])) ?>">
                    業務UI
                </a>
            <?php endif; ?>
            <?php // サイボウズモード中は、もう一度押すとモーダルを開く（treservation_cybozu.js） ?>
            <a class="btn btn-sm position-relative <?= $useCybozu ? 'btn-primary' : 'btn-outline-primary' ?>"
               id="cybozuModeButton"
               href="<?= h($mkUrl(['uimode'=>'cybozu'])) ?>">
                🗓️ サイボウズモード
                <span class="badge rounded-pill text-bg-warning cybozu-new-badge">NEW</span>
            </a>
        </div>
    </div>
</div>
