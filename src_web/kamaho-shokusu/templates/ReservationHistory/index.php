<?php
/**
 * 予約履歴の確認画面（予約状況・部屋集計・変更の経緯）
 *
 * @var \App\View\AppView $this
 * @var string $from
 * @var string $to
 * @var string $tab
 * @var array<int, string> $targets
 * @var int $targetId
 * @var array|null $target
 * @var int $actorId
 * @var string|null $error
 * @var string|null $forbidden
 * @var array<string, array<int, true>> $meals
 * @var array<int, array{at:string, date:string, meal:int, kind:string, actor:string}> $logs
 * @var string|null $logStart
 * @var array<string, array<int, int>> $summary
 * @var array<int, string> $rooms
 * @var int $roomId
 * @var array<int, string> $mealLabels
 * @var bool $canViewSummary
 */
$this->assign('title', '予約履歴');

$tabs = ['meals' => '予約状況', 'summary' => '部屋集計', 'log' => '変更の経緯'];
if (!$canViewSummary) {
    unset($tabs['summary']); // 子どもには部屋の情報を出さない
}
$kindLabels = ['reserve' => '予約', 'cancel' => '取消'];
$kindClass = ['reserve' => 'text-bg-success', 'cancel' => 'text-bg-danger'];
$tabUrl = fn(string $t): string => $this->Url->build(['action' => 'index', '?' => [
    'from' => $from, 'to' => $to, 'user' => $targetId, 'room' => $roomId, 'tab' => $t,
]]);
?>
<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">予約履歴</h1>
        <?php if ($canViewSummary): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= $this->Url->build(['controller' => 'Pages', 'action' => 'dashboard']) ?>">ダッシュボードへ戻る</a>
        <?php else: ?>
            <?php /* 子どもはダッシュボードが無いので予約画面へ戻す */ ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= $this->Url->build('/TReservationInfo') ?>">予約へ戻る</a>
        <?php endif; ?>
    </div>

    <form method="get" class="card card-body mb-3">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small" for="rh-from">開始日</label>
                <input class="form-control" type="date" id="rh-from" name="from" value="<?= h($from) ?>" required>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small" for="rh-to">終了日</label>
                <input class="form-control" type="date" id="rh-to" name="to" value="<?= h($to) ?>" required>
            </div>
            <?php if ($tab === 'summary'): ?>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="rh-room">部屋</label>
                    <select class="form-select" id="rh-room" name="room">
                        <?php foreach ($rooms as $id => $name): ?>
                            <option value="<?= (int)$id ?>"<?= (int)$id === $roomId ? ' selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="rh-user">対象者</label>
                    <select class="form-select" id="rh-user" name="user">
                        <?php foreach ($targets as $id => $name): ?>
                            <option value="<?= (int)$id ?>"<?= (int)$id === $targetId ? ' selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-12 col-md-2">
                <button class="btn btn-primary w-100" type="submit">表示</button>
            </div>
        </div>
        <div class="form-text mt-2">終了日は昨日以前、期間は最大93日です。当日以降の予約は予約画面で確認します。</div>
    </form>

    <ul class="nav nav-tabs mb-3">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= $tabUrl($key) ?>"><?= h($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($error !== null): ?>
        <div class="alert alert-warning" role="alert"><strong>入力を確認してください。</strong><br><?= h($error) ?></div>
    <?php elseif ($forbidden !== null): ?>
        <div class="alert alert-danger" role="alert"><strong>権限がありません。</strong><br><?= h($forbidden) ?></div>
    <?php elseif ($tab === 'summary'): ?>
        <?php if (!$summary): ?>
            <div class="alert alert-secondary">この期間の予約はありません。</div>
        <?php else: ?>
            <p class="small text-muted"><?= h($rooms[$roomId] ?? '') ?>の予約済みの人数です（直前の変更を反映した最終的な予約）。個人名は出しません。</p>
            <div class="table-responsive">
                <table class="table table-bordered table-sm text-center align-middle">
                    <thead class="table-light"><tr><th>日付</th><?php foreach ($mealLabels as $l): ?><th><?= h($l) ?></th><?php endforeach; ?><th>合計</th></tr></thead>
                    <tbody>
                    <?php $tot = array_fill_keys(array_keys($mealLabels), 0); ?>
                    <?php foreach ($summary as $date => $byType): ?>
                        <tr>
                            <td><?= h($date) ?></td>
                            <?php $sum = 0; foreach ($mealLabels as $type => $l): $n = (int)($byType[$type] ?? 0); $sum += $n; $tot[$type] += $n; ?>
                                <td><?= $n ?></td>
                            <?php endforeach; ?>
                            <td><?= $sum ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><th>期間合計</th><?php foreach ($tot as $n): ?><th><?= $n ?></th><?php endforeach; ?><th><?= array_sum($tot) ?></th></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($tab === 'log'): ?>
        <p class="small text-muted">対象：<?= h($target['name'] ?? '') ?><?= $targetId !== $actorId ? '（代理で閲覧）' : '' ?></p>
        <?php if ($logStart === null || $from < $logStart): ?>
            <div class="alert alert-info small"><?= $logStart === null ? '変更の経緯はまだ記録されていません。' : h($logStart) . ' より前の変更の経緯は記録されていません。' ?></div>
        <?php endif; ?>
        <?php if (!$logs): ?>
            <div class="alert alert-secondary">この期間の変更の経緯はありません。</div>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($logs as $l): ?>
                    <li class="list-group-item">
                        <div class="small text-muted"><?= h($l['at']) ?>（対象日 <?= h($l['date']) ?>）</div>
                        <span class="badge <?= h($kindClass[$l['kind']] ?? 'text-bg-secondary') ?>"><?= h($kindLabels[$l['kind']] ?? '') ?></span>
                        <?= h($mealLabels[$l['meal']] ?? '') ?>
                        <span class="small text-muted ms-2">操作：<?= h($l['actor']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="small text-muted mt-2">記録されている操作だけを表示します。代理による一括登録など、対象者が記録されていない操作は含まれません。</p>
    <?php else: ?>
        <p class="small text-muted">対象：<?= h($target['name'] ?? '') ?><?= $targetId !== $actorId ? '（代理で閲覧）' : '' ?></p>
        <?php if (!$meals): ?>
            <div class="alert alert-secondary">この期間の予約はありません。</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm text-center align-middle">
                    <thead class="table-light"><tr><th>日付</th><?php foreach ($mealLabels as $l): ?><th><?= h($l) ?></th><?php endforeach; ?><th>予約済み食数</th></tr></thead>
                    <tbody>
                    <?php $tot = array_fill_keys(array_keys($mealLabels), 0); ?>
                    <?php foreach ($meals as $date => $byType): ?>
                        <tr>
                            <td><?= h($date) ?></td>
                            <?php foreach (array_keys($mealLabels) as $type): $booked = isset($byType[$type]); $tot[$type] += $booked ? 1 : 0; ?>
                                <td><?= $booked ? '● 予約済み' : '－' ?></td>
                            <?php endforeach; ?>
                            <td><?= count($byType) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><th>期間合計</th><?php foreach ($tot as $n): ?><th><?= $n ?></th><?php endforeach; ?><th><?= array_sum($tot) ?></th></tr></tfoot>
                </table>
            </div>
            <p class="small text-muted">「● 予約済み」は直前の変更を反映した最終的な予約です。予約済みの食事が無い日は表示しません。</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
