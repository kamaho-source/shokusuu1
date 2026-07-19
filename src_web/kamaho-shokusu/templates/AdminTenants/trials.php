<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Tenant> $tenants
 * @var array $q
 * @var int $totalTrial
 * @var int $nearExpiry
 * @var int $expired
 * @var int $active
 * @var array<int,int> $userStats
 * @var array<int,int> $reservationStat
 * @var array<int,string|null> $lastLoginStat
 * @var \Cake\I18n\DateTime $now
 * @var int|null $activeTenantId
 */
$this->assign('title', 'トライアルユーザー管理');
$csrfToken = (string)($this->request->getAttribute('csrfToken') ?? '');
?>
<style>
.trial-stat-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e8ecf0;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s;
}
.trial-stat-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.1); }
.trial-stat-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.trial-stat-num { font-size: 2rem; font-weight: 700; line-height: 1; }
.trial-stat-label { font-size: 0.8rem; color: #6c757d; margin-top: 2px; }

.status-badge {
    display: inline-flex; align-items: center;
    padding: 0.25em 0.75em;
    border-radius: 999px;
    font-size: 0.78rem; font-weight: 600;
}
.status-trial    { background: #e3f8fd; color: #0baccc; }
.status-near     { background: #fff8e1; color: #f57f17; }
.status-expired  { background: #ffeaea; color: #c0392b; }
.status-active   { background: #e8f8f0; color: #1a7a4a; }
.status-suspended{ background: #f5f5f5; color: #757575; }

.tenant-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.avatar-color-0 { background: #0dcaf0; }
.avatar-color-1 { background: #6c5ce7; }
.avatar-color-2 { background: #00b894; }
.avatar-color-3 { background: #fd79a8; }
.avatar-color-4 { background: #e17055; }
.avatar-color-5 { background: #74b9ff; }

.usage-bar {
    height: 6px; border-radius: 3px; background: #e9ecef;
    overflow: hidden; margin-top: 4px;
}
.usage-bar-fill { height: 100%; border-radius: 3px; transition: width 0.4s; }

.days-hot  { color: #e17055; font-weight: 700; }
.days-warn { color: #f57f17; font-weight: 700; }
.days-ok   { color: #0baccc; font-weight: 700; }
.days-done { color: #1a7a4a; font-weight: 600; }
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
        <li class="breadcrumb-item"><a href="<?= $this->Url->build(['action' => 'index']) ?>" class="text-decoration-none text-muted">テナント選択</a></li>
        <li class="breadcrumb-item active text-muted">トライアルユーザー管理</li>
    </ol>
</nav>

<!-- ── ページヘッダー ── -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 fw-bold mb-0">トライアルユーザー管理</h1>
        <p class="text-muted small mb-0 mt-1">トライアル中の施設、利用状況、終了日を確認・管理します。</p>
    </div>
    <a href="<?= $this->Url->build(['action' => 'add']) ?>"
       class="btn btn-info text-white d-flex align-items-center gap-1 shadow-sm">
        <i class="bi bi-plus-lg"></i>
        <span>トライアルを追加</span>
    </a>
</div>

<?= $this->Flash->render() ?>

<!-- ── 集計カード ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="trial-stat-card">
            <div class="trial-stat-icon" style="background:#e3f8fd;">
                <i class="bi bi-building text-info"></i>
            </div>
            <div>
                <div class="trial-stat-num text-info"><?= $totalTrial ?></div>
                <div class="trial-stat-label">トライアル中</div>
                <div class="trial-stat-label">施設</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="trial-stat-card">
            <div class="trial-stat-icon" style="background:#fff8e1;">
                <i class="bi bi-exclamation-circle text-warning"></i>
            </div>
            <div>
                <div class="trial-stat-num text-warning"><?= $nearExpiry ?></div>
                <div class="trial-stat-label">終了間近</div>
                <div class="trial-stat-label">施設</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="trial-stat-card">
            <div class="trial-stat-icon" style="background:#ffeaea;">
                <i class="bi bi-x-circle" style="color:#c0392b;"></i>
            </div>
            <div>
                <div class="trial-stat-num" style="color:#c0392b;"><?= $expired ?></div>
                <div class="trial-stat-label">期限切れ</div>
                <div class="trial-stat-label">施設</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="trial-stat-card">
            <div class="trial-stat-icon" style="background:#e8f8f0;">
                <i class="bi bi-check-circle" style="color:#1a7a4a;"></i>
            </div>
            <div>
                <div class="trial-stat-num" style="color:#1a7a4a;"><?= $active ?></div>
                <div class="trial-stat-label">本契約へ移行</div>
                <div class="trial-stat-label">施設</div>
            </div>
        </div>
    </div>
</div>

<!-- ── 検索・絞り込み ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="fw-semibold text-muted small mb-2">検索・絞り込み</div>
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'trials']]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <?= $this->Form->text('q', [
                    'class'       => 'form-control form-control-sm',
                    'placeholder' => '施設名・担当者名で検索',
                    'value'       => $q['q'] ?? '',
                ]) ?>
            </div>
            <div class="col-6 col-md-2">
                <?= $this->Form->select('status', [
                    ''            => 'すべての状態',
                    'trial'       => 'トライアル中',
                    'near_expiry' => '終了間近',
                    'expired'     => '期限切れ',
                    'active'      => '本契約',
                    'suspended'   => '停止中',
                ], [
                    'class' => 'form-select form-select-sm',
                    'value' => $q['status'] ?? '',
                ]) ?>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center gap-1">
                    <span class="text-muted small text-nowrap">終了日：</span>
                    <?= $this->Form->date('expire_from', [
                        'class' => 'form-control form-control-sm',
                        'value' => $q['expire_from'] ?? '',
                    ]) ?>
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-info btn-sm text-white">検索</button>
                <a href="<?= $this->Url->build(['action' => 'trials']) ?>" class="btn btn-outline-secondary btn-sm ms-1">リセット</a>
            </div>
            <div class="col ms-auto d-flex justify-content-end align-items-center">
                <span class="text-muted small"><?= $this->Paginator->counter('{{count}}件表示') ?></span>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<!-- ── テナント一覧テーブル ── -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3 fw-semibold text-muted" style="width:220px;">施設名</th>
                        <th class="fw-semibold text-muted">担当者</th>
                        <th class="fw-semibold text-muted" style="width:160px;">利用状況</th>
                        <th class="fw-semibold text-muted">トライアル期間</th>
                        <th class="fw-semibold text-muted text-center" style="width:90px;">残り日数</th>
                        <th class="fw-semibold text-muted text-center" style="width:80px;">ユーザー数</th>
                        <th class="fw-semibold text-muted text-center" style="width:100px;">状態</th>
                        <th class="fw-semibold text-muted text-center" style="width:60px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php
$avatarColors = ['avatar-color-0','avatar-color-1','avatar-color-2','avatar-color-3','avatar-color-4','avatar-color-5'];
$idx          = 0;
$tenantList   = iterator_to_array($tenants, false);
foreach ($tenantList as $tenant):
    $tid          = (int)$tenant->id;
    $userCount    = $userStats[$tid] ?? 0;
    $rsvCount     = $reservationStat[$tid] ?? 0;
    $lastLogin    = $lastLoginStat[$tid] ?? null;
    $trialExpires = $tenant->trial_expires_at;
    $status       = $tenant->status;
    $avatarClass  = $avatarColors[$idx % count($avatarColors)];
    $firstChar    = mb_substr((string)$tenant->name, 0, 1);
    $isEntered    = ($activeTenantId === $tid);
    $idx++;

    // 残り日数
    $daysLabel = '-';
    $daysClass = '';
    if ($trialExpires !== null) {
        $diff   = (int)$now->diff($trialExpires)->days;
        $isPast = $trialExpires < $now;
        if ($isPast) {
            $daysLabel = '期限切れ';
            $daysClass = 'days-hot';
        } elseif ($diff <= 7) {
            $daysLabel = $diff . '日';
            $daysClass = 'days-warn';
        } else {
            $daysLabel = $diff . '日';
            $daysClass = 'days-ok';
        }
    } elseif ($status === 'active') {
        $daysLabel = '移行済';
        $daysClass = 'days-done';
    }

    // ステータスバッジ
    $badgeClass = match(true) {
        $status === 'active'     => 'status-active',
        $status === 'suspended'  => 'status-suspended',
        $status === 'terminated' => 'status-suspended',
        $trialExpires !== null && $trialExpires < $now => 'status-expired',
        $trialExpires !== null && (int)$now->diff($trialExpires)->days <= 7 => 'status-near',
        default => 'status-trial',
    };
    $badgeLabel = match(true) {
        $status === 'active'     => '本契約',
        $status === 'suspended'  => '停止中',
        $status === 'terminated' => '解約済',
        $trialExpires !== null && $trialExpires < $now => '期限切れ',
        $trialExpires !== null && (int)$now->diff($trialExpires)->days <= 7 => '終了間近',
        default => 'トライアル中',
    };

    // 利用状況プログレスバー
    $maxRsv   = 500;
    $pct      = min(100, (int)round($rsvCount / $maxRsv * 100));
    $barColor = $pct >= 80 ? '#2ecc71' : ($pct >= 40 ? '#0dcaf0' : '#adb5bd');

    // トライアル期間
    $trialStart = $tenant->created_at?->format('Y/m/d') ?? '-';
    $trialEnd   = $trialExpires?->format('Y/m/d') ?? ($status === 'active' ? '本契約' : '-');

    // 最終ログイン
    $lastLoginStr = $lastLogin ? substr((string)$lastLogin, 0, 10) : '-';
?>
                    <tr <?= $isEntered ? 'class="table-info"' : '' ?>>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="tenant-avatar <?= $avatarClass ?>"><?= h($firstChar) ?></div>
                                <div>
                                    <div class="fw-semibold"><?= h($tenant->name) ?></div>
                                    <div class="text-muted" style="font-size:0.75rem;">最終ログイン: <?= h($lastLoginStr) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= h($tenant->billing_contact_name ?? '-') ?></td>
                        <td>
                            <div class="small">予約 <?= number_format($rsvCount) ?>件</div>
                            <div class="usage-bar" style="width:120px;">
                                <div class="usage-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>;"></div>
                            </div>
                        </td>
                        <td class="text-nowrap text-muted small"><?= h($trialStart) ?>〜<?= h($trialEnd) ?></td>
                        <td class="text-center <?= $daysClass ?>"><?= h($daysLabel) ?></td>
                        <td class="text-center"><?= $userCount ?><span class="text-muted small">名</span></td>
                        <td class="text-center">
                            <span class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                        </td>
                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary border-0 px-2 py-1"
                                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                        title="操作">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm">
                                    <?php if ($isEntered): ?>
                                    <li>
                                        <form method="post" action="<?= $this->Url->build(['action' => 'exitTenant']) ?>">
                                            <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                                            <button type="submit" class="dropdown-item text-secondary">
                                                <i class="bi bi-box-arrow-left me-1"></i>退出
                                            </button>
                                        </form>
                                    </li>
                                    <?php else: ?>
                                    <li>
                                        <form method="post" action="<?= $this->Url->build(['action' => 'enter', $tenant->id]) ?>">
                                            <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
                                            <button type="submit" class="dropdown-item text-primary"
                                                    <?= $status === 'terminated' ? 'disabled' : '' ?>>
                                                <i class="bi bi-box-arrow-in-right me-1"></i>このテナントで操作
                                            </button>
                                        </form>
                                    </li>
                                    <?php endif; ?>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <?php if ($status !== 'active'): ?>
                                    <li>
                                        <?= $this->Form->postLink(
                                            '✓ 本契約へ移行',
                                            ['action' => 'updateStatus', $tenant->id, '?' => ['status' => 'active']],
                                            ['class' => 'dropdown-item text-success', 'confirm' => "「{$tenant->name}」を本契約に移行しますか？"]
                                        ) ?>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($status !== 'suspended'): ?>
                                    <li>
                                        <?= $this->Form->postLink(
                                            '⏸ 利用停止',
                                            ['action' => 'updateStatus', $tenant->id, '?' => ['status' => 'suspended']],
                                            ['class' => 'dropdown-item text-warning', 'confirm' => "「{$tenant->name}」を利用停止にしますか？"]
                                        ) ?>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($status !== 'trial'): ?>
                                    <li>
                                        <?= $this->Form->postLink(
                                            '🔄 トライアルに戻す',
                                            ['action' => 'updateStatus', $tenant->id, '?' => ['status' => 'trial']],
                                            ['class' => 'dropdown-item text-info']
                                        ) ?>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
<?php endforeach; ?>
                <?php if ($tenantList === []): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-3 d-block mb-2 opacity-25"></i>
                            条件に一致するテナントはありません
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ページネーション -->
    <?php if ($this->Paginator->hasPage(2)): ?>
    <div class="card-footer bg-white border-top d-flex align-items-center justify-content-between py-2 px-3">
        <small class="text-muted"><?= $this->Paginator->counter('全{{count}}件中 {{start}}〜{{end}}件を表示') ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($this->Paginator->hasPrev()): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $this->Paginator->generateUrl(['page' => $this->Paginator->current() - 1]) ?>">&lsaquo;</a>
                </li>
                <?php else: ?>
                <li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>
                <?php endif; ?>
                <?= $this->Paginator->numbers(['class' => 'page-item', 'currentClass' => 'active']) ?>
                <?php if ($this->Paginator->hasNext()): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $this->Paginator->generateUrl(['page' => $this->Paginator->current() + 1]) ?>">&rsaquo;</a>
                </li>
                <?php else: ?>
                <li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
