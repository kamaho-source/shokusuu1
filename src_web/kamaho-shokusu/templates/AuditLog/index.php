<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $logs
 * @var array $categories
 */
$this->assign('title', '監査ログ');
$q = $this->request->getQueryParams();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0"><i class="bi bi-shield-lock-fill text-danger me-2"></i>監査ログ</h2>
        <a href="<?= $this->Url->build(['action' => 'export'] + ['?' => $q]) ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>CSVエクスポート
        </a>
    </div>

    <!-- 検索フォーム -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <?= $this->Form->create(null, [
                'type' => 'get',
                'url'  => ['action' => 'index'],
            ]) ?>
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label fw-semibold">カテゴリ</label>
                    <?= $this->Form->select('category', array_merge(['' => '全て'], array_combine($categories, $categories)), [
                        'class' => 'form-select form-select-sm',
                        'value' => $q['category'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">操作種別</label>
                    <?= $this->Form->text('action', [
                        'class'       => 'form-control form-control-sm',
                        'placeholder' => 'user_login など',
                        'value'       => $q['action'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">操作者名</label>
                    <?= $this->Form->text('actor', [
                        'class'       => 'form-control form-control-sm',
                        'placeholder' => 'ユーザー名',
                        'value'       => $q['actor'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">対象ID</label>
                    <?= $this->Form->text('target_id', [
                        'class'       => 'form-control form-control-sm',
                        'placeholder' => 'レコードID',
                        'value'       => $q['target_id'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold">結果</label>
                    <?= $this->Form->select('result', ['' => '全て', '1' => '成功', '0' => '失敗'], [
                        'class' => 'form-select form-select-sm',
                        'value' => $q['result'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold">開始日</label>
                    <?= $this->Form->date('date_from', [
                        'class' => 'form-control form-control-sm',
                        'value' => $q['date_from'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold">終了日</label>
                    <?= $this->Form->date('date_to', [
                        'class' => 'form-control form-control-sm',
                        'value' => $q['date_to'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-1 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="<?= $this->Url->build(['action' => 'index']) ?>" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <!-- ページネーション情報 -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="text-muted small">
            <?= $this->Paginator->counter('全 {{count}} 件中 {{start}}-{{end}} 件を表示') ?>
        </div>
        <div>
            <?= $this->Paginator->prev('<', ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            <?= $this->Paginator->numbers(['class' => 'btn btn-sm btn-outline-secondary mx-1']) ?>
            <?= $this->Paginator->next('>', ['class' => 'btn btn-sm btn-outline-secondary']) ?>
        </div>
    </div>

    <!-- ログ一覧テーブル -->
    <div class="table-responsive shadow-sm rounded">
        <table class="table table-bordered table-hover table-sm mb-0" style="font-size: 0.82rem;">
            <thead class="table-dark sticky-top">
                <tr>
                    <th style="width:60px;">ID</th>
                    <th style="width:100px;">カテゴリ</th>
                    <th style="width:200px;">操作種別</th>
                    <th style="width:130px;">対象テーブル</th>
                    <th style="width:120px;">対象ID</th>
                    <th style="width:100px;">操作者</th>
                    <th style="width:120px;">IPアドレス</th>
                    <th style="width:50px;">結果</th>
                    <th>詳細</th>
                    <th style="width:150px;">操作日時</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= h($log->i_id_audit) ?></td>
                    <td>
                        <?php
                        $badgeClass = match($log->c_category) {
                            'user'        => 'bg-primary',
                            'reservation' => 'bg-success',
                            'actual_meal' => 'bg-warning text-dark',
                            'approval'    => 'bg-info text-dark',
                            'master'      => 'bg-secondary',
                            'system'      => 'bg-danger',
                            default       => 'bg-dark',
                        };
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= h($log->c_category) ?></span>
                    </td>
                    <td class="font-monospace"><?= h($log->c_action) ?></td>
                    <td class="text-muted"><?= h($log->c_target_table ?? '-') ?></td>
                    <td class="font-monospace"><?= h($log->c_target_id ?? '-') ?></td>
                    <td>
                        <?php if ($log->i_actor_user_id): ?>
                            <span class="text-muted small">#<?= h($log->i_actor_user_id) ?></span>
                        <?php endif; ?>
                        <?= h($log->c_actor_user_name) ?>
                    </td>
                    <td class="text-muted small font-monospace"><?= h($log->c_ip_address ?? '-') ?></td>
                    <td class="text-center">
                        <?php if ($log->i_result): ?>
                            <span class="badge bg-success">成功</span>
                        <?php else: ?>
                            <span class="badge bg-danger">失敗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log->c_detail): ?>
                            <?php $detail = json_decode($log->c_detail, true); ?>
                            <?php if ($detail): ?>
                                <details>
                                    <summary class="text-primary" style="cursor:pointer; font-size:0.8rem;">詳細を見る</summary>
                                    <pre class="mt-1 mb-0 p-1 bg-light rounded" style="font-size:0.75rem; white-space:pre-wrap; max-height:150px; overflow:auto;"><?= h(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </details>
                            <?php else: ?>
                                <span class="text-muted small"><?= h($log->c_detail) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-monospace small"><?= h($log->dt_create) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (iterator_count($logs) === 0): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                        条件に一致するログがありません
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-2 d-flex justify-content-center">
        <?= $this->Paginator->prev('<', ['class' => 'btn btn-sm btn-outline-secondary me-1']) ?>
        <?= $this->Paginator->numbers(['class' => 'btn btn-sm btn-outline-secondary mx-1']) ?>
        <?= $this->Paginator->next('>', ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
    </div>
</div>
