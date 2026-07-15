<?php
/**
 * LP画像管理画面
 *
 * LP（ランディングページ）に掲載する画像のアップロード・一覧・表示切替・削除を行う。
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
                アップロードした画像はLP（ドメイン直下のページ）に表示されます。PNG・JPEG・WebP・GIF形式、5MBまで。
            </p>
        </div>
        <div class="card-body">
            <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'type' => 'file']) ?>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="c_title" class="form-label fw-semibold">タイトル <span class="text-danger">*</span></label>
                    <input type="text" name="c_title" id="c_title" class="form-control" maxlength="100" required
                           placeholder="例: 予約カレンダー画面">
                </div>
                <div class="col-12 col-md-3">
                    <label for="c_section" class="form-label fw-semibold">掲載セクション</label>
                    <select name="c_section" id="c_section" class="form-select">
                        <?php foreach ($sections as $val => $label): ?>
                            <option value="<?= h($val) ?>" <?= $val === 'gallery' ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label for="i_sort" class="form-label fw-semibold">表示順</label>
                    <input type="number" name="i_sort" id="i_sort" class="form-control" value="0">
                </div>
                <div class="col-12 col-md-3">
                    <label for="image_file" class="form-label fw-semibold">画像ファイル <span class="text-danger">*</span></label>
                    <input type="file" name="image_file" id="image_file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif" required>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>追加</button>
                </div>
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
                    <?php $hasImages = false; ?>
                    <?php foreach ($images as $image): $hasImages = true; ?>
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
                    <?php if (!$hasImages): ?>
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
