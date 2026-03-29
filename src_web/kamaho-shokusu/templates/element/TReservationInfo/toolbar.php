<div class="d-flex flex-wrap align-items-center justify-content-between mt-2 mb-2 gap-2">
    <h1 class="m-0 fs-4 fs-md-1"><?= $useKidUI ? '🍚 食数予約（中高生向け）' : '食数予約（業務）' ?></h1>
    <?php if (!$useKidUI || ($useKidUI && $isStaff)): ?>
        <div class="d-flex align-items-center gap-2">
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
        </div>
    <?php endif; ?>
</div>
