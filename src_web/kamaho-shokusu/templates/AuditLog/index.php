<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $logs
 * @var array $categories
 */
$this->assign('title', '監査ログ');
$q = $this->request->getQueryParams();
$hasFilter = !empty($q['category']) || !empty($q['action']) || !empty($q['actor'])
          || !empty($q['target_id']) || isset($q['result']) && $q['result'] !== ''
          || !empty($q['date_from']) || !empty($q['date_to']);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- ページヘッダー -->
<div class="rounded-3 shadow-sm mb-4 px-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-2"
     style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); color:#fff;">
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center rounded-circle bg-danger shadow"
             style="width:48px; height:48px; flex-shrink:0;">
            <i class="bi bi-shield-lock-fill fs-4"></i>
        </div>
        <div>
            <h1 class="h5 fw-bold mb-0 text-white">監査ログ</h1>
            <small class="text-white-50">システム管理者専用 — 全操作履歴を記録・検索</small>
        </div>
    </div>
    <a href="<?= $this->Url->build(['action' => 'export'] + ['?' => $q]) ?>"
       class="btn btn-outline-light btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-file-earmark-arrow-down"></i>
        <span>CSV エクスポート</span>
    </a>
</div>

<!-- 検索フォーム -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
        <i class="bi bi-funnel-fill text-secondary"></i>
        <span class="fw-semibold text-secondary small">絞り込み条件</span>
        <?php if ($hasFilter): ?>
            <span class="badge bg-primary ms-1">適用中</span>
        <?php endif; ?>
    </div>
    <div class="card-body pb-2">
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index']]) ?>
        <div class="row g-3">
            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">カテゴリ</label>
                <?= $this->Form->select('category',
                    array_merge(['' => 'すべて'], array_combine($categories, $categories)),
                    ['class' => 'form-select form-select-sm', 'value' => $q['category'] ?? '']
                ) ?>
            </div>
            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">操作種別</label>
                <?= $this->Form->text('action', [
                    'class'       => 'form-control form-control-sm',
                    'placeholder' => 'user_login など',
                    'value'       => $q['action'] ?? '',
                ]) ?>
            </div>
            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">操作者名</label>
                <?= $this->Form->text('actor', [
                    'class'       => 'form-control form-control-sm',
                    'placeholder' => 'ユーザー名',
                    'value'       => $q['actor'] ?? '',
                ]) ?>
            </div>
            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">対象ID</label>
                <?= $this->Form->text('target_id', [
                    'class'       => 'form-control form-control-sm',
                    'placeholder' => 'レコードID',
                    'value'       => $q['target_id'] ?? '',
                ]) ?>
            </div>
            <div class="col-sm-4 col-md-2 col-lg-1">
                <label class="form-label small fw-semibold text-muted mb-1">結果</label>
                <?= $this->Form->select('result',
                    ['' => 'すべて', '1' => '✓ 成功', '0' => '✗ 失敗'],
                    ['class' => 'form-select form-select-sm', 'value' => $q['result'] ?? '']
                ) ?>
            </div>
            <div class="col-sm-4 col-md-2 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">開始日</label>
                <?= $this->Form->date('date_from', [
                    'class' => 'form-control form-control-sm',
                    'value' => $q['date_from'] ?? '',
                ]) ?>
            </div>
            <div class="col-sm-4 col-md-2 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1">終了日</label>
                <?= $this->Form->date('date_to', [
                    'class' => 'form-control form-control-sm',
                    'value' => $q['date_to'] ?? '',
                ]) ?>
            </div>
            <div class="col-sm-12 col-lg-1 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="bi bi-search me-1"></i>検索
                </button>
                <a href="<?= $this->Url->build(['action' => 'index']) ?>"
                   class="btn btn-outline-secondary btn-sm flex-fill"
                   title="クリア">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<!-- 件数 & 上部ページネーション -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <p class="text-muted small mb-0">
        <i class="bi bi-list-ul me-1"></i>
        <?= $this->Paginator->counter('全 {{count}} 件中 {{start}}〜{{end}} 件を表示') ?>
    </p>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?= $this->Paginator->prev('«', [
                'tag'            => 'li',
                'class'          => 'page-item',
                'linkAttributes' => ['class' => 'page-link'],
            ], null, [
                'tag'            => 'li',
                'class'          => 'page-item disabled',
                'linkAttributes' => ['class' => 'page-link', 'tabindex' => '-1'],
            ]) ?>
            <?= $this->Paginator->numbers([
                'tag'          => 'li',
                'class'        => 'page-item',
                'currentTag'   => 'li',
                'currentClass' => 'page-item active',
                'linkAttributes' => ['class' => 'page-link'],
                'escape'       => false,
            ]) ?>
            <?= $this->Paginator->next('»', [
                'tag'            => 'li',
                'class'          => 'page-item',
                'linkAttributes' => ['class' => 'page-link'],
            ], null, [
                'tag'            => 'li',
                'class'          => 'page-item disabled',
                'linkAttributes' => ['class' => 'page-link', 'tabindex' => '-1'],
            ]) ?>
        </ul>
    </nav>
</div>

<!-- ログ一覧テーブル -->
<div class="table-responsive shadow-sm rounded border">
    <table class="table table-bordered table-hover table-sm mb-0" style="font-size: 0.82rem;">
        <thead style="background-color:#1a1a2e; color:#fff; position:sticky; top:0; z-index:1;">
            <tr>
                <th style="width:55px;" class="text-center">ID</th>
                <th style="width:95px;">カテゴリ</th>
                <th style="width:195px;">操作種別</th>
                <th style="width:130px;">対象テーブル</th>
                <th style="width:115px;">対象ID</th>
                <th style="width:110px;">操作者</th>
                <th style="width:125px;">IPアドレス</th>
                <th style="width:55px;" class="text-center">結果</th>
                <th>詳細</th>
                <th style="width:145px;">操作日時</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr class="<?= $log->i_result ? '' : 'table-danger' ?>">
                <td class="text-center text-muted"><?= h($log->i_id_audit) ?></td>
                <td>
                    <?php
                    [$badge, $label] = match($log->c_category) {
                        'user'        => ['bg-primary',              'user'],
                        'reservation' => ['bg-success',              'reservation'],
                        'actual_meal' => ['bg-warning text-dark',    'actual_meal'],
                        'approval'    => ['bg-info text-dark',       'approval'],
                        'master'      => ['bg-secondary',            'master'],
                        'system'      => ['bg-danger',               'system'],
                        default       => ['bg-dark',                 $log->c_category],
                    };
                    ?>
                    <span class="badge <?= $badge ?> text-truncate d-inline-block" style="max-width:88px;" title="<?= h($log->c_category) ?>">
                        <?= h($label) ?>
                    </span>
                </td>
                <td class="font-monospace"><?= h($log->c_action) ?></td>
                <td class="text-muted font-monospace small"><?= h($log->c_target_table ?? '—') ?></td>
                <td class="font-monospace small"><?= h($log->c_target_id ?? '—') ?></td>
                <td>
                    <?php if ($log->i_actor_user_id): ?>
                        <span class="text-muted small">#<?= h($log->i_actor_user_id) ?>&nbsp;</span>
                    <?php endif; ?>
                    <?= h($log->c_actor_user_name) ?>
                </td>
                <td class="text-muted small font-monospace"><?= h($log->c_ip_address ?? '—') ?></td>
                <td class="text-center">
                    <?php if ($log->i_result): ?>
                        <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($log->c_detail): ?>
                        <?php $detail = json_decode($log->c_detail, true); ?>
                        <?php if ($detail): ?>
                            <details>
                                <summary class="text-primary small" style="cursor:pointer;">詳細を見る</summary>
                                <pre class="mt-1 mb-0 p-1 bg-light rounded border" style="font-size:0.72rem; white-space:pre-wrap; max-height:140px; overflow:auto;"><?= h(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </details>
                        <?php else: ?>
                            <span class="text-muted small"><?= h($log->c_detail) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="font-monospace small text-nowrap"><?= h($log->dt_create) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (iterator_count($logs) === 0): ?>
            <tr>
                <td colspan="10" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                    条件に一致するログがありません
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 下部ページネーション -->
<nav class="mt-3">
    <ul class="pagination justify-content-center mb-0">
        <?= $this->Paginator->prev('«', [
            'tag'            => 'li',
            'class'          => 'page-item',
            'linkAttributes' => ['class' => 'page-link'],
        ], null, [
            'tag'            => 'li',
            'class'          => 'page-item disabled',
            'linkAttributes' => ['class' => 'page-link', 'tabindex' => '-1'],
        ]) ?>
        <?= $this->Paginator->numbers([
            'tag'          => 'li',
            'class'        => 'page-item',
            'currentTag'   => 'li',
            'currentClass' => 'page-item active',
            'linkAttributes' => ['class' => 'page-link'],
            'escape'       => false,
        ]) ?>
        <?= $this->Paginator->next('»', [
            'tag'            => 'li',
            'class'          => 'page-item',
            'linkAttributes' => ['class' => 'page-link'],
        ], null, [
            'tag'            => 'li',
            'class'          => 'page-item disabled',
            'linkAttributes' => ['class' => 'page-link', 'tabindex' => '-1'],
        ]) ?>
    </ul>
</nav>
