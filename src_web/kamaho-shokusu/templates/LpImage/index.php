<?php
/**
 * LP画像管理画面
 *
 * LP（ランディングページ）に掲載する画像のアップロード・一覧・表示切替・削除を行う。
 * 新規アップロードのほか、データベースに登録済みの画像を選択して追加（再利用）できる。
 *
 * 受け取るビュー変数:
 *   - $images   : \Cake\Datasource\ResultSetInterface m_lp_image の全レコード
 *   - $sections : array<string, string> セクション値 => 表示ラベル
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MLpImage> $images
 * @var array<string, string> $sections
 */
$this->assign('title', 'LP画像管理');
?>

<div class="container py-4">
    <h1 class="h3 fw-bold mb-4"><i class="bi bi-images me-2 text-info"></i>LP画像管理</h1>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-3">
            <h2 class="h6 mb-0 fw-semibold"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>画像を追加</h2>
            <p class="text-muted small mb-0 mt-1">
                追加した画像はLP（ドメイン直下のページ）に表示されます。新しい画像のアップロード（PNG・JPEG・WebP・GIF形式、5MBまで）のほか、
                登録済みの画像を選択して別のセクションに追加することもできます。
            </p>
        </div>
        <div class="card-body">
            <?php $hasExisting = $images->count() > 0; ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'type' => 'file']) ?>
            <div class="mb-3">
                <span class="form-label fw-semibold d-block">画像の指定方法</span>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="image_source" id="source_upload" value="upload" checked>
                    <label class="form-check-label" for="source_upload">新規アップロード</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="image_source" id="source_existing" value="existing" <?= $hasExisting ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="source_existing">
                        登録済みから選択
                        <?php if (!$hasExisting): ?><span class="text-muted small">（登録済みの画像がありません）</span><?php endif; ?>
                    </label>
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="c_title" class="form-label fw-semibold">タイトル <span class="text-danger">*</span></label>
                    <?= $this->Form->control('c_title', [
                        'label' => false,
                        'id' => 'c_title',
                        'class' => 'form-control',
                        'maxlength' => 100,
                        'placeholder' => '例: 予約カレンダー画面',
                        'required' => true,
                    ]) ?>
                </div>
                <div class="col-12 col-md-3">
                    <label for="c_section" class="form-label fw-semibold">掲載セクション</label>
                    <?= $this->Form->control('c_section', [
                        'type' => 'select',
                        'options' => $sections,
                        'label' => false,
                        'id' => 'c_section',
                        'class' => 'form-select',
                        'default' => 'gallery',
                    ]) ?>
                </div>
                <div class="col-6 col-md-1">
                    <label for="i_sort" class="form-label fw-semibold">表示順</label>
                    <?= $this->Form->control('i_sort', [
                        'type' => 'number',
                        'label' => false,
                        'id' => 'i_sort',
                        'class' => 'form-control',
                        'value' => 0,
                    ]) ?>
                </div>
                <div class="col-12 col-md-3" id="upload_field_wrap">
                    <label for="image_file" class="form-label fw-semibold">画像ファイル <span class="text-danger">*</span></label>
                    <?= $this->Form->control('image_file', [
                        'type' => 'file',
                        'label' => false,
                        'id' => 'image_file',
                        'class' => 'form-control',
                        'accept' => 'image/png,image/jpeg,image/webp,image/gif',
                        'required' => true,
                    ]) ?>
                </div>
                <div class="col-12 col-md-3 d-none" id="existing_field_wrap">
                    <label for="existing_image_id" class="form-label fw-semibold">登録済み画像 <span class="text-danger">*</span></label>
                    <select name="existing_image_id" id="existing_image_id" class="form-select" disabled>
                        <option value="">選択してください</option>
                        <?php foreach ($images as $image): ?>
                            <option value="<?= h((string)$image->i_id) ?>"
                                    data-path="<?= h($this->Url->build('/' . $image->c_file_path)) ?>">
                                <?= h($image->c_title) ?>（<?= h($sections[$image->c_section] ?? $image->c_section) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>追加</button>
                </div>
            </div>
            <div class="mt-2 d-none" id="existing_preview_wrap">
                <img id="existing_preview" src="" alt="選択中の画像プレビュー"
                     class="img-thumbnail" style="max-width: 160px; max-height: 100px; object-fit: cover;">
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h2 class="h6 mb-0 fw-semibold"><i class="bi bi-card-list me-2 text-primary"></i>登録済みの画像</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th style="width: 140px;">プレビュー</th>
                        <th>タイトル</th>
                        <th style="width: 200px;">掲載セクション</th>
                        <th style="width: 90px;" class="text-center">表示順</th>
                        <th style="width: 110px;" class="text-center">状態</th>
                        <th style="width: 220px;" class="text-center">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($images as $image): ?>
                        <tr>
                            <td>
                                <img src="<?= $this->Url->build('/' . $image->c_file_path) ?>"
                                     alt="<?= h($image->c_title) ?>"
                                     class="img-thumbnail" style="max-width: 120px; max-height: 80px; object-fit: cover;">
                            </td>
                            <td><?= h($image->c_title) ?></td>
                            <td><?= h($sections[$image->c_section] ?? $image->c_section) ?></td>
                            <td class="text-center"><?= h((string)$image->i_sort) ?></td>
                            <td class="text-center">
                                <?php if ($image->i_display === 1): ?>
                                    <span class="badge bg-success">表示中</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">非表示</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= $this->Form->postLink(
                                    $image->i_display === 1 ? '非表示にする' : '表示する',
                                    ['action' => 'toggle', $image->i_id],
                                    ['class' => 'btn btn-sm btn-outline-primary me-1']
                                ) ?>
                                <?= $this->Form->postLink(
                                    '削除',
                                    ['action' => 'delete', $image->i_id],
                                    [
                                        'class' => 'btn btn-sm btn-outline-danger',
                                        'confirm' => sprintf('「%s」を削除します。よろしいですか？', $image->c_title),
                                    ]
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$hasExisting): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">登録済みの画像はありません。</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const uploadRadio    = document.getElementById('source_upload');
    const existingRadio  = document.getElementById('source_existing');
    const uploadWrap     = document.getElementById('upload_field_wrap');
    const existingWrap   = document.getElementById('existing_field_wrap');
    const fileInput      = document.getElementById('image_file');
    const existingSelect = document.getElementById('existing_image_id');
    const previewWrap    = document.getElementById('existing_preview_wrap');
    const preview        = document.getElementById('existing_preview');
    const titleInput     = document.getElementById('c_title');

    // 指定方法に応じて入力欄の表示・必須・送信対象を切り替える
    function applySource() {
        const useExisting = existingRadio.checked;
        uploadWrap.classList.toggle('d-none', useExisting);
        existingWrap.classList.toggle('d-none', !useExisting);

        fileInput.required = !useExisting;
        fileInput.disabled = useExisting;
        existingSelect.required = useExisting;
        existingSelect.disabled = !useExisting;

        if (useExisting) {
            updatePreview();
        } else {
            previewWrap.classList.add('d-none');
        }
    }

    function updatePreview() {
        const opt  = existingSelect.selectedOptions[0];
        const path = opt ? (opt.dataset.path || '') : '';
        previewWrap.classList.toggle('d-none', path === '');
        if (path !== '') {
            preview.src = path;
            // 既存画像選択時は、そのタイトルをデフォルトでセットする（未入力の場合のみ）
            if (titleInput && titleInput.value === '' && opt.text) {
                const titleMatch = opt.text.match(/^(.*)（.*）$/);
                if (titleMatch) {
                    titleInput.value = titleMatch[1];
                }
            }
        }
    }

    uploadRadio.addEventListener('change', applySource);
    existingRadio.addEventListener('change', applySource);
    existingSelect.addEventListener('change', updatePreview);
    applySource();
})();
</script>
