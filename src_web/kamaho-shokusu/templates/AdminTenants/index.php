<?php
/**
 * テナント管理画面（システム管理者専用）
 *
 * @var \App\View\AppView $this
 * @var array $tenants
 * @var int|null $activeTenantId
 */
$this->assign('title', 'テナント管理');
$csrfToken = (string)($this->request->getAttribute('csrfToken') ?? '');

$statusLabel = [
    'trial'      => ['label' => 'トライアル',  'class' => 'bg-warning text-dark'],
    'active'     => ['label' => '利用中',      'class' => 'bg-success text-white'],
    'suspended'  => ['label' => '利用停止',    'class' => 'bg-danger text-white'],
    'terminated' => ['label' => '契約終了',    'class' => 'bg-secondary text-white'],
];
?>

<div class="rounded-3 shadow-sm mb-4 px-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-2"
     style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); color:#fff;">
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center rounded-circle shadow"
             style="width:48px; height:48px; flex-shrink:0; background:#3b82f6;">
            <i class="bi bi-building fs-4 text-white"></i>
        </div>
        <div>
            <h1 class="h5 fw-bold mb-0 text-white">テナント管理</h1>
            <small class="text-white-50">テナントを選択して操作画面に入ります</small>
        </div>
    </div>
    <?php if ($activeTenantId !== null): ?>
        <form method="post" action="<?= $this->Url->build(['action' => 'exitTenant']) ?>">
            <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
            <button type="submit" class="btn btn-outline-light btn-sm">
                <i class="bi bi-x-circle me-1"></i>全テナントモードに戻る
            </button>
        </form>
    <?php endif; ?>
</div>

<?= $this->Flash->render() ?>

<div class="row g-3">
    <?php foreach ($tenants as $tenant): ?>
        <?php
        $isActive = ($activeTenantId === $tenant->id);
        $st = $statusLabel[$tenant->status] ?? ['label' => $tenant->status, 'class' => 'bg-secondary text-white'];
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100 shadow-sm border-0 <?= $isActive ? 'border border-primary border-2' : '' ?>"
                 style="<?= $isActive ? 'outline: 2px solid #3b82f6;' : '' ?>">
                <div class="card-body d-flex flex-column gap-2">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-bold fs-6 mb-1"><?= h($tenant->name) ?></div>
                            <code class="text-muted small"><?= h($tenant->tenant_code) ?></code>
                        </div>
                        <span class="badge <?= h($st['class']) ?> align-self-start"><?= h($st['label']) ?></span>
                    </div>
                    <div class="text-muted small">
                        <i class="bi bi-hash me-1"></i>ID: <?= (int)$tenant->id ?>
                        <?php if (!empty($tenant->contract_started_at)): ?>
                            <span class="ms-2"><i class="bi bi-calendar me-1"></i><?= h($tenant->contract_started_at) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
                    <?php if ($isActive): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary"><i class="bi bi-check-circle me-1"></i>操作中</span>
                            <form method="post" action="<?= $this->Url->build(['action' => 'exitTenant']) ?>" class="d-inline">
                                <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-box-arrow-left me-1"></i>退出
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= $this->Url->build(['action' => 'enter', $tenant->id]) ?>">
                            <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                            <button type="submit" class="btn btn-sm btn-primary w-100"
                                    <?= $tenant->status === 'terminated' ? 'disabled' : '' ?>>
                                <i class="bi bi-box-arrow-in-right me-1"></i>このテナントで操作する
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
