<?php $rooms ??= []; $isAdmin ??= false; $canViewAllRooms ??= $isAdmin; $calRoomId ??= null; ?>
<!-- === 予約コピー（週／月）: 大人向けのみ表示 === -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <div class="me-auto">
            <div class="fw-bold">予約コピー</div>
            <div class="text-muted small">先週→指定週、または月単位で予約をコピーできます。</div>
        </div>
        <!--
        <button class="btn btn-outline-primary btn-sm" id="res-copy-btn-lastweek">先週の予約をこの週へコピー</button>
        -->
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#res-copy-modal">予約をコピー（週 / 月）</button>
    </div>
</div>

<!-- 部屋別食数フィルタ（JS により FullCalendar ツールバーへ移動） -->
<?php if (!empty($rooms)): ?>
<div id="calRoomSelectorWrap" style="display:none; align-items:center; gap:6px;">
    <form method="get" id="calRoomFilterForm" style="display:inline-flex; align-items:center; gap:4px; margin:0;">
        <?php foreach ($this->request->getQueryParams() as $qk => $qv):
            if ($qk === 'cal_room_id') continue; ?>
        <input type="hidden" name="<?= h($qk) ?>" value="<?= h($qv) ?>">
        <?php endforeach; ?>
        <select name="cal_room_id" class="form-select form-select-sm cal-room-select" onchange="this.form.submit()">
            <?php if ($canViewAllRooms): ?>
            <option value="" <?= ($calRoomId === null) ? 'selected' : '' ?>>全部屋</option>
            <?php endif; ?>
            <?php foreach ($rooms as $rid => $rname): ?>
            <option value="<?= h($rid) ?>" <?= ((int)$calRoomId === (int)$rid) ? 'selected' : '' ?>><?= h($rname) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php endif; ?>

<!-- カレンダー -->
<div id="calendar" aria-label="食数予約カレンダー（業務）"></div>

<!-- 凡例 -->
<div class="biz-note mt-3">
    <span class="me-3"><span class="legend-dot legend-green"></span>自分の予約あり</span>
    <span class="me-3"><span class="legend-dot legend-orange"></span>未予約（空）</span>
    <span class="me-3"><span class="legend-dot legend-red"></span>祝日</span>
    <span><span class="legend-dot legend-gray"></span>その他</span>
</div>

<!-- コピー用モーダル -->
<div class="modal fade" id="res-copy-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-check"></i> 予約をコピー</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <form id="res-copy-form">
                    <!-- ステップ1: コピー範囲 -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="bi bi-1-circle-fill text-primary"></i> コピー範囲を選択
                        </label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode" id="res-copy-mode-week" value="week" checked>
                            <label class="btn btn-outline-primary" for="res-copy-mode-week">
                                <i class="bi bi-calendar-week"></i> 週単位
                            </label>
                            <input type="radio" class="btn-check" name="mode" id="res-copy-mode-month" value="month">
                            <label class="btn btn-outline-primary" for="res-copy-mode-month">
                                <i class="bi bi-calendar-range"></i> 月単位
                            </label>
                        </div>
                        <div class="form-text mt-2" id="mode-hint">
                            週単位の場合は月曜日、月単位の場合は1日を開始日に指定してください
                        </div>
                    </div>

                    <!-- ステップ2: コピー元 -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="bi bi-2-circle-fill text-primary"></i> コピー元の開始日
                            <small class="text-muted fw-normal">（自動入力）</small>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                            <input type="date" class="form-control" id="source_start" name="source_start" required>
                            <button class="btn btn-outline-secondary" type="button" id="refresh-source" title="日付を再計算">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                        <div class="form-text" id="source-validation"></div>
                    </div>

                    <!-- ステップ3: コピー先 -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">
                            <i class="bi bi-3-circle-fill text-primary"></i> コピー先の開始日
                            <small class="text-muted fw-normal">（複数選択可）</small>
                        </label>
                        <div class="mb-2">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar-check"></i></span>
                                <input type="date" class="form-control" id="target_start_input" placeholder="日付を選択">
                                <button class="btn btn-outline-primary" type="button" id="add-target-btn">
                                    <i class="bi bi-plus-circle"></i> 追加
                                </button>
                            </div>
                            <div class="form-text">日付を選択して「追加」ボタンをクリックしてください</div>
                        </div>

                        <!-- 選択された日付のリスト -->
                        <div id="target-dates-list" class="border rounded p-2" style="min-height: 60px; max-height: 150px; overflow-y: auto; background-color: #f8f9fa;">
                            <div class="text-muted text-center small py-2" id="target-dates-empty">
                                <i class="bi bi-info-circle"></i> コピー先の日付が選択されていません
                            </div>
                        </div>

                        <!-- hidden inputs for form submission -->
                        <div id="target-dates-hidden"></div>
                    </div>

                    <!-- ステップ4: 部屋と対象 -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="bi bi-4-circle-fill text-primary"></i> 対象の部屋
                        </label>
                        <?= $this->Form->control('room_id', [
                                'type'    => 'select',
                                'label'   => false,
                                'options' => $rooms ?? [],
                                'empty'   => '所属全部屋',
                                'class'   => 'form-select',
                                'id'      => 'res-copy-room',
                        ]) ?>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="copy-only-children" name="only_children" value="1">
                        <label class="form-check-label" for="copy-only-children">
                            <i class="bi bi-people"></i> 子供（利用者）のみコピー
                        </label>
                        <div class="form-text">職員の予約は除外されます</div>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong>注意：</strong>既に予約がある日時はスキップされます（上書きされません）。未予約の箇所のみにコピーされます。
                    </div>

                    <!-- プレビュー表示 -->
                    <div class="alert alert-light border mt-3" id="copy-preview" style="display: none;">
                        <div class="fw-bold mb-2"><i class="bi bi-eye"></i> コピー内容プレビュー</div>
                        <div id="preview-content" class="small"></div>
                    </div>

                    <input type="hidden" name="csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button id="res-copy-submit" class="btn btn-primary" disabled>
                    <i class="bi bi-check-circle"></i> コピーを実行
                </button>
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> キャンセル
                </button>
            </div>
        </div>
    </div>
</div>