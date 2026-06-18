<?php
/**
 * @var \App\View\AppView $this
 * @var array{rows: list<array{action: string, label: string, category: string, category_label: string, total: int, unique_users: int, last_used: string}>, total_operations: int, top_feature: string} $summary
 * @var array<string, string> $monthOptions
 * @var array<string, string> $categories
 * @var string $yearMonth
 * @var string|null $category
 */
$this->assign('title', '機能使用頻度ダッシュボード');
$q = $this->request->getQueryParams();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- ページヘッダー -->
<div class="rounded-3 shadow-sm mb-4 px-4 py-3 d-flex align-items-center justify-content-between"
     style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); color:#fff;">
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center rounded-circle shadow"
             style="width:48px; height:48px; flex-shrink:0; background:#f97316;">
            <i class="bi bi-bar-chart-fill fs-4 text-white"></i>
        </div>
        <div>
            <h1 class="h5 fw-bold mb-0 text-white">機能使用頻度ダッシュボード</h1>
            <small class="text-white-50">システム管理者専用 — 操作ログから機能の利用状況を集計</small>
        </div>
    </div>
</div>

<!-- サマリーカード -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10"
                     style="width:48px;height:48px;flex-shrink:0;">
                    <i class="bi bi-activity text-primary fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">月間オペレーション数</div>
                    <div class="fw-bold fs-4"><?= number_format($summary['total_operations']) ?> <span class="fs-6 text-muted fw-normal">回</span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10"
                     style="width:48px;height:48px;flex-shrink:0;">
                    <i class="bi bi-trophy-fill text-success fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">最多利用機能</div>
                    <div class="fw-bold fs-6"><?= $summary['top_feature'] !== '' ? h($summary['top_feature']) : '—' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center bg-info bg-opacity-10"
                     style="width:48px;height:48px;flex-shrink:0;">
                    <i class="bi bi-list-check text-info fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small">操作種別数</div>
                    <div class="fw-bold fs-4"><?= count($summary['rows']) ?> <span class="fs-6 text-muted fw-normal">種</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- フィルターフォーム -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
        <i class="bi bi-funnel-fill text-secondary"></i>
        <span class="fw-semibold text-secondary small">絞り込み条件</span>
        <?php if (!empty($q['month']) || !empty($q['category'])): ?>
            <span class="badge bg-primary ms-1">適用中</span>
        <?php endif; ?>
    </div>
    <div class="card-body pb-2">
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index']]) ?>
        <div class="row g-3 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">対象月</label>
                <?= $this->Form->select('month', $monthOptions, [
                    'class' => 'form-select form-select-sm',
                    'value' => $yearMonth,
                ]) ?>
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">カテゴリ</label>
                <?= $this->Form->select('category',
                    array_merge(['' => 'すべて'], $categories),
                    ['class' => 'form-select form-select-sm', 'value' => $category ?? '']
                ) ?>
            </div>
            <div class="col-sm-4 col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i>集計
                </button>
            </div>
            <?php if (!empty($q['month']) || !empty($q['category'])): ?>
                <div class="col-auto">
                    <a href="<?= $this->Url->build(['action' => 'index']) ?>"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>クリア
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<!-- 集計テーブル -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold text-secondary small">
            <i class="bi bi-table me-1"></i>
            機能別使用状況
            <span class="badge bg-secondary ms-1"><?= h($yearMonth) ?></span>
        </span>
        <span class="text-muted small"><?= count($summary['rows']) ?> 種別</span>
    </div>

    <?php if (empty($summary['rows'])): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
            <p class="mb-0">対象期間にデータがありません。</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:2.5rem;">#</th>
                        <th>機能名</th>
                        <th>カテゴリ</th>
                        <th class="text-end pe-3" style="width:8rem;">使用回数</th>
                        <th class="text-end pe-3" style="width:10rem;">ユニークユーザー</th>
                        <th class="text-end pe-3" style="width:8rem;">最終利用日</th>
                        <th style="width:12rem;">使用率</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxTotal = !empty($summary['rows']) ? (int)$summary['rows'][0]['total'] : 1;
                    foreach ($summary['rows'] as $i => $row):
                        $pct = $maxTotal > 0 ? (int)round($row['total'] / $maxTotal * 100) : 0;
                        $barColor = match($row['category']) {
                            'reservation'  => 'bg-primary',
                            'user'         => 'bg-info',
                            'actual_meal'  => 'bg-success',
                            'approval'     => 'bg-warning',
                            'master'       => 'bg-secondary',
                            default        => 'bg-dark',
                        };
                    ?>
                        <tr>
                            <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                            <td>
                                <span class="fw-semibold"><?= h($row['label']) ?></span>
                                <br>
                                <code class="text-muted" style="font-size:0.7rem;"><?= h($row['action']) ?></code>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-75"><?= h($row['category_label']) ?></span>
                            </td>
                            <td class="text-end pe-3 fw-bold">
                                <?= number_format($row['total']) ?>
                            </td>
                            <td class="text-end pe-3">
                                <i class="bi bi-people text-muted me-1"></i><?= number_format($row['unique_users']) ?>
                            </td>
                            <td class="text-end pe-3 text-muted small">
                                <?= h($row['last_used']) ?>
                            </td>
                            <td class="pe-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:8px;">
                                        <div class="progress-bar <?= $barColor ?>"
                                             role="progressbar"
                                             style="width:<?= $pct ?>%"
                                             aria-valuenow="<?= $pct ?>"
                                             aria-valuemin="0"
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <span class="text-muted small" style="width:2.5rem;"><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
