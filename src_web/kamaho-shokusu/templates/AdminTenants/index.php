<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Tenant> $tenants
 * @var int|null $activeTenantId
 */
$this->assign('title', 'テナント選択');
$csrfToken = (string)($this->request->getAttribute('csrfToken') ?? '');
?>
<style>
.tenant-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e8ecf0;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s, border-color 0.2s;
    height: 100%;
}
.tenant-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); border-color: #b8d4f0; }
.tenant-card.is-active { border-color: #0dcaf0; box-shadow: 0 0 0 2px rgba(13,202,240,0.25); }

.tenant-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.avatar-color-0 { background: #0dcaf0; }
.avatar-color-1 { background: #6c5ce7; }
.avatar-color-2 { background: #00b894; }
.avatar-color-3 { background: #fd79a8; }
.avatar-color-4 { background: #e17055; }
.avatar-color-5 { background: #74b9ff; }

.status-badge {
    display: inline-flex; align-items: center;
    padding: 0.2em 0.65em;
    border-radius: 999px;
    font-size: 0.75rem; font-weight: 600;
}
.status-trial     { background: #e3f8fd; color: #0baccc; }
.status-active    { background: #e8f8f0; color: #1a7a4a; }
.status-suspended { background: #f5f5f5; color: #757575; }
</style>

<?php if ($activeTenantId !== null): ?>
<div class="alert alert-info d-flex align-items-center justify-content-between py-2 mb-3" role="alert">
    <span><i class="bi bi-building me-2"></i>現在テナントID <strong><?= (int)$activeTenantId ?></strong> の操作モードです。</span>
    <form method="post" action="<?= $this->Url->build(['action' => 'exitTenant']) ?>">
        <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-outline-info btn-sm">
            <i class="bi bi-x-circle me-1"></i>全テナントモードに戻る
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ── パンくず ── -->
<nav aria-label="breadcrumb" class="mb-1">
    <ol class="breadcrumb breadcrumb-sm">
        <li class="breadcrumb-item"><a href="<?= $this->Url->build('/') ?>" class="text-decoration-none text-muted">管理</a></li>
        <li class="breadcrumb-item active text-muted">テナント選択</li>
    </ol>
</nav>

<!-- ── ページヘッダー ── -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 fw-bold mb-0">テナント選択</h1>
        <p class="text-muted small mb-0 mt-1">操作するテナントを選択してください。</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $this->Url->build(['action' => 'trials']) ?>"
           class="btn btn-outline-info d-flex align-items-center gap-1">
            <i class="bi bi-graph-up"></i>
            <span>トライアル管理</span>
        </a>
        <a href="<?= $this->Url->build(['action' => 'add']) ?>"
           class="btn btn-info text-white d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-plus-lg"></i>
            <span>テナントを追加</span>
        </a>
    </div>
</div>

<?= $this->Flash->render() ?>

<!-- ── テナントカード一覧 ── -->
<?php
$avatarColors = ['avatar-color-0','avatar-color-1','avatar-color-2','avatar-color-3','avatar-color-4','avatar-color-5'];
$idx          = 0;
$tenantList   = iterator_to_array($tenants, false);
?>

<?php if ($tenantList === []): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-building fs-2 d-block mb-2 opacity-25"></i>
        登録済みのテナントはありません。
    </div>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($tenantList as $tenant):
    $tid         = (int)$tenant->id;
    $status      = $tenant->status;
    $isEntered   = ($activeTenantId === $tid);
    $avatarClass = $avatarColors[$idx % count($avatarColors)];
    $firstChar   = mb_substr((string)$tenant->name, 0, 1);
    $idx++;

    $badgeClass = match($status) {
        'active'    => 'status-active',
        'suspended', 'terminated' => 'status-suspended',
        default     => 'status-trial',
    };
    $badgeLabel = match($status) {
        'active'      => '本契約',
        'suspended'   => '停止中',
        'terminated'  => '解約済',
        default       => 'トライアル',
    };
?>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="tenant-card <?= $isEntered ? 'is-active' : '' ?>">
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="tenant-avatar <?= $avatarClass ?>"><?= h($firstChar) ?></div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-bold text-truncate"><?= h($tenant->name) ?></div>
                    <div class="text-muted small"><?= h($tenant->tenant_code) ?></div>
                </div>
                <span class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            </div>

            <?php if ($isEntered): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-info text-white"><i class="bi bi-check-circle me-1"></i>操作中</span>
            </div>
            <form method="post" action="<?= $this->Url->build(['action' => 'exitTenant']) ?>">
                <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-box-arrow-left me-1"></i>退出する
                </button>
            </form>
            <?php else: ?>
            <form method="post" action="<?= $this->Url->build(['action' => 'enter', $tenant->id]) ?>">
                <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100"
                        <?= $status === 'terminated' ? 'disabled' : '' ?>>
                    <i class="bi bi-box-arrow-in-right me-1"></i>このテナントで操作
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
