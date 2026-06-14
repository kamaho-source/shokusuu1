<?php
/** @var \App\Controller\RoomUsageController $this */
$rooms     = $rooms     ?? [];
$lowRooms  = $lowRooms  ?? [];
$dateFrom  = $dateFrom  ?? date('Y-m-01');
$dateTo    = $dateTo    ?? date('Y-m-d');
$mealType  = $mealType  ?? null;
$threshold = $threshold ?? 50.0;

$mealLabels = ['' => '全食種', 1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁当'];
$basePath   = $this->request->getAttribute('base') ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>部屋使用率</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; }
        .page-shell { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        .page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
        .page-title { font-size: 1.4rem; font-weight: 700; margin: 0; }
        .page-subtitle { font-size: .85rem; color: #6c757d; margin-top: 2px; }
        .mui-paper { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 20px; margin-bottom: 20px; }
        .filter-title { font-size: .8rem; font-weight: 600; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }
        .section-title { font-size: 1rem; font-weight: 700; margin-bottom: 12px; }
        .usage-bar-wrap { width: 120px; }
        .usage-bar { height: 8px; border-radius: 4px; background: #e9ecef; overflow: hidden; }
        .usage-bar-fill { height: 100%; border-radius: 4px; transition: width .3s; }
        .low-badge { font-size: .7rem; padding: 2px 6px; }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="page-head">
        <div>
            <h1 class="page-title">部屋使用率</h1>
            <div class="page-subtitle">期間内の部屋ごとの食事利用率を集計します。システム管理者専用。</div>
        </div>
        <a href="<?= h($basePath) ?>/" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    <!-- フィルター -->
    <div class="mui-paper">
        <div class="filter-title">絞り込み</div>
        <form method="get" action="" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1 small">開始日</label>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1 small">終了日</label>
                <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label mb-1 small">食種</label>
                <select name="meal_type" class="form-select form-select-sm">
                    <?php foreach ($mealLabels as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= (string)$val === (string)($mealType ?? '') ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label mb-1 small">低使用率の閾値（%以下）</label>
                <input type="number" name="threshold" value="<?= h($threshold) ?>" min="0" max="100" step="1" class="form-control form-control-sm">
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">絞り込む</button>
            </div>
        </form>
    </div>

    <!-- 低使用率部屋ピックアップ -->
    <?php if (!empty($lowRooms)): ?>
    <div class="mui-paper border border-warning">
        <div class="section-title text-warning">⚠ 低使用率の部屋（<?= h($threshold) ?>%以下）</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>部屋名</th>
                    <th class="text-end">総食数</th>
                    <th class="text-end">食べる</th>
                    <th>使用率</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lowRooms as $r): ?>
                    <tr>
                        <td><?= h($r['room_name']) ?></td>
                        <td class="text-end"><?= h($r['capacity']) ?></td>
                        <td class="text-end"><?= h($r['eat_count']) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="usage-bar-wrap">
                                    <div class="usage-bar">
                                        <div class="usage-bar-fill bg-warning" style="width:<?= h(min(100, $r['usage_rate'])) ?>%"></div>
                                    </div>
                                </div>
                                <span class="fw-semibold text-warning"><?= h($r['usage_rate']) ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 全部屋使用率一覧 -->
    <div class="mui-paper">
        <div class="section-title">全部屋の使用率一覧</div>
        <?php if (empty($rooms)): ?>
            <div class="text-muted text-center py-3">対象データがありません</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>部屋名</th>
                    <th class="text-end">総食数</th>
                    <th class="text-end">食べる</th>
                    <th class="text-end">食べない</th>
                    <th>使用率</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rooms as $r): ?>
                    <?php $isLow = $r['usage_rate'] <= $threshold && $r['capacity'] > 0; ?>
                    <tr class="<?= $isLow ? 'table-warning' : '' ?>">
                        <td>
                            <?= h($r['room_name']) ?>
                            <?php if ($isLow): ?>
                                <span class="badge bg-warning text-dark low-badge ms-1">低</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h($r['capacity']) ?></td>
                        <td class="text-end"><?= h($r['eat_count']) ?></td>
                        <td class="text-end"><?= h($r['capacity'] - $r['eat_count']) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="usage-bar-wrap">
                                    <div class="usage-bar">
                                        <?php
                                            $pct = (float)$r['usage_rate'];
                                            $color = $pct >= 70 ? '#198754' : ($pct >= 50 ? '#ffc107' : '#dc3545');
                                        ?>
                                        <div class="usage-bar-fill" style="width:<?= h(min(100, $pct)) ?>%;background:<?= h($color) ?>"></div>
                                    </div>
                                </div>
                                <span class="fw-semibold" style="color:<?= h($color) ?>"><?= h($pct) ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
