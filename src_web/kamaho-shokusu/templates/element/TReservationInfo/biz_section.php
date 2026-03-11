<!-- ================= 大人向け（業務システム調・エクスポートUI改善） ================= -->
<?php
/** @noinspection PhpUndefinedVariableInspection */
/** @var mixed|null $user */
/** @noinspection PhpUndefinedVariableInspection */
/** @var array<int|string, mixed> $rooms */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $isAdmin */
/** @noinspection PhpUndefinedVariableInspection */
/** @var bool $canViewAllRooms */
/** @noinspection PhpUndefinedVariableInspection */
/** @var int|string|null $calRoomId */
$user = isset($user) ? $user : null;
$rooms = isset($rooms) ? $rooms : [];
$isAdmin = isset($isAdmin) ? (bool)$isAdmin : false;
$canViewAllRooms = isset($canViewAllRooms) ? (bool)$canViewAllRooms : $isAdmin;
$calRoomId = isset($calRoomId) ? $calRoomId : null;
$copyModalVars = [
    'rooms' => $rooms,
    'isAdmin' => $isAdmin,
    'canViewAllRooms' => $canViewAllRooms,
    'calRoomId' => $calRoomId,
];
?>
<?php if ($user && $user->get('i_admin') === 1): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto">
                    <div class="fw-bold">エクスポート</div>
                    <div class="text-muted small">期間を選んで「予定表」または「実施表」を出力できます。</div>
                </div>

                <div class="btn-group" role="group" aria-label="期間プリセット">
                    <button class="btn btn-outline-secondary btn-sm" data-range-preset="this-month"><i class="bi bi-calendar2-week"></i> 今月</button>
                    <button class="btn btn-outline-secondary btn-sm" data-range-preset="next-month"><i class="bi bi-calendar2-plus"></i> 来月</button>
                    <button class="btn btn-outline-secondary btn-sm" data-range-preset="this-week"><i class="bi bi-calendar-week"></i> 今週</button>
                    <button class="btn btn-outline-secondary btn-sm" data-range-preset="last-month"><i class="bi bi-calendar2-minus"></i> 先月</button>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="fromDate" class="form-label mb-1">期間開始日</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                        <input type="date" id="fromDate" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label for="toDate" class="form-label mb-1">期間終了日</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                        <input type="date" id="toDate" class="form-control" value="<?= date('Y-m-t') ?>">
                    </div>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">出力種別</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="exportType" id="typePlan" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="typePlan"><i class="bi bi-file-earmark-excel"></i> 予定表</label>

                        <input type="radio" class="btn-check" name="exportType" id="typeActual" autocomplete="off">
                        <label class="btn btn-outline-primary" for="typeActual"><i class="bi bi-file-earmark-spreadsheet"></i> 実施表</label>
                    </div>
                    <div class="form-text">予定表＝食数予定表 / 実施表＝実施食数表</div>
                </div>

                <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-success" id="exportNow">
                        <span class="btn-label"><i class="bi bi-download"></i> エクスポート</span>
                        <span class="spinner-border spinner-border-sm ms-2 d-none" id="exportSpinner" role="status" aria-hidden="true"></span>
                    </button>
                    <div class="form-text text-muted mt-1">Excel（.xlsx）で保存されます。</div>
                </div>
            </div>

            <hr class="my-3">

            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="小さな text-muted"><i class="bi bi-info-circle"></i> 選択中の期間：</div>
                <span class="badge rounded-pill text-bg-light" id="rangeChip"><?= date('Y-m-01') ?> 〜 <?= date('Y-m-t') ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<?= $this->element('TReservationInfo/copy_modal', $copyModalVars) ?>