<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Application\UseCase\FacilitySetting\GetFacilitySettingOutput $setting
 */
$this->assign('title', '施設別設定');
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-md-10">

        <h3 class="mb-4">施設別設定</h3>

        <?= $this->Flash->render() ?>

        <?= $this->Form->create(null, ['url' => ['action' => 'edit'], 'class' => 'needs-validation', 'novalidate' => true]) ?>

        <!-- 予約ルール -->
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-calendar-check me-1"></i>予約ルール
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="reservation_changeable_days" class="form-label">
                        予約変更可能日数
                        <span class="text-muted small">（今日を基準に何日先まで変更できるか）</span>
                    </label>
                    <div class="input-group" style="max-width:200px">
                        <input
                            type="number"
                            id="reservation_changeable_days"
                            name="reservation_changeable_days"
                            class="form-control"
                            min="0"
                            max="365"
                            value="<?= h($setting->reservationChangeableDays) ?>"
                            required
                        >
                        <span class="input-group-text">日</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="reservation_deadline_time" class="form-label">
                        予約締切時刻
                        <span class="text-muted small">（空白の場合は制限なし）</span>
                    </label>
                    <input
                        type="time"
                        id="reservation_deadline_time"
                        name="reservation_deadline_time"
                        class="form-control"
                        style="max-width:160px"
                        value="<?= h($setting->reservationDeadlineTime ?? '') ?>"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        年度更新日
                        <span class="text-muted small">（空白の場合は設定なし）</span>
                    </label>
                    <?php
                        $fyMonth = null;
                        $fyDay   = null;
                        if ($setting->fiscalYearUpdateDate !== null) {
                            $parts   = explode('-', $setting->fiscalYearUpdateDate);
                            $fyMonth = (int)($parts[0] ?? 0) ?: null;
                            $fyDay   = (int)($parts[1] ?? 0) ?: null;
                        }
                    ?>
                    <div class="d-flex align-items-center gap-2">
                        <select id="fiscal_year_update_month" name="fiscal_year_update_month" class="form-select" style="max-width:100px">
                            <option value="">--</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $fyMonth === $m ? 'selected' : '' ?>><?= $m ?>月</option>
                            <?php endfor; ?>
                        </select>
                        <select id="fiscal_year_update_day" name="fiscal_year_update_day" class="form-select" style="max-width:90px">
                            <option value="">--</option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $fyDay === $d ? 'selected' : '' ?>><?= $d ?>日</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="text-muted small mt-1">月・日の両方を選択するか、両方とも「--」にしてください。</div>
                </div>
            </div>
        </div>

        <!-- 一括予約 -->
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-table me-1"></i>一括予約
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input
                        type="hidden"
                        name="enable_weekly_bulk"
                        value=""
                    >
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="enable_weekly_bulk"
                        name="enable_weekly_bulk"
                        value="1"
                        <?= $setting->enableWeeklyBulk ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="enable_weekly_bulk">週間一括予約を有効にする</label>
                </div>
                <div class="form-check form-switch">
                    <input
                        type="hidden"
                        name="enable_monthly_bulk"
                        value=""
                    >
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="enable_monthly_bulk"
                        name="enable_monthly_bulk"
                        value="1"
                        <?= $setting->enableMonthlyBulk ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="enable_monthly_bulk">月間一括予約を有効にする</label>
                </div>
            </div>
        </div>

        <!-- 食事設定 -->
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-cup-hot me-1"></i>食事設定
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-2">
                    <input
                        type="hidden"
                        name="lunch_bento_exclusive"
                        value=""
                    >
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="lunch_bento_exclusive"
                        name="lunch_bento_exclusive"
                        value="1"
                        <?= $setting->lunchBentoExclusive ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="lunch_bento_exclusive">昼食と弁当を排他にする</label>
                </div>
                <div class="text-muted small">ONにすると昼食と弁当のどちらか一方しか予約できません。</div>

                <div class="mt-3">
                    <label for="export_template_code" class="form-label">
                        エクスポートテンプレートコード
                        <span class="text-muted small">（空白の場合はデフォルト）</span>
                    </label>
                    <input
                        type="text"
                        id="export_template_code"
                        name="export_template_code"
                        class="form-control"
                        style="max-width:200px"
                        maxlength="32"
                        value="<?= h($setting->exportTemplateCode ?? '') ?>"
                        placeholder="例: DEFAULT"
                    >
                </div>
            </div>
        </div>

        <!-- 承認フロー -->
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-check2-circle me-1"></i>承認フロー
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-2">
                    <input
                        type="hidden"
                        name="approval_enabled"
                        value=""
                    >
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="approval_enabled"
                        name="approval_enabled"
                        value="1"
                        <?= $setting->approvalEnabled ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="approval_enabled">承認フローを有効にする</label>
                </div>
                <div class="text-muted small">ONにすると予約に管理者の承認が必要になります。</div>
            </div>
        </div>

        <!-- 利用者権限 -->
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-person-lock me-1"></i>利用者権限
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-2">
                    <input
                        type="hidden"
                        name="resident_self_edit_enabled"
                        value=""
                    >
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="resident_self_edit_enabled"
                        name="resident_self_edit_enabled"
                        value="1"
                        <?= $setting->residentSelfEditEnabled ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="resident_self_edit_enabled">入居者が自分で予約を編集できる</label>
                </div>
                <div class="text-muted small">OFFにすると入居者はスタッフを介してのみ予約変更できます。</div>
            </div>
        </div>

        <!-- 保存ボタン -->
        <div class="d-flex justify-content-end gap-2 mb-5">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-floppy me-1"></i>設定を保存する
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
