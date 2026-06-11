<?php
/**
 * お知らせ編集
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MNotice $notice
 * @var bool $isSysAdmin
 */

$this->assign('title', 'お知らせ編集');
$this->Html->script('realtime-validation.js', ['block' => true]);

$startVal = $notice->d_start ? $notice->d_start->format('Y-m-d') : '';
$endVal   = $notice->d_end   ? $notice->d_end->format('Y-m-d')   : '';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content" style="max-width: 640px;">
    <h3 class="mb-4">
        <i class="bi bi-megaphone"></i> お知らせ編集
    </h3>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'edit', $notice->i_id], 'method' => 'post', 'id' => 'notice-form']) ?>

    <div class="mb-3">
        <label for="c_title" class="form-label fw-bold">タイトル <span class="text-danger">*</span></label>
        <?= $this->Form->text('c_title', [
            'id'            => 'c_title',
            'class'         => 'form-control',
            'maxlength'     => 200,
            'value'         => $notice->c_title,
            'data-validate' => 'required maxlength:200',
        ]) ?>
        <div class="invalid-feedback"></div>
    </div>

    <div class="mb-3">
        <label for="c_body" class="form-label fw-bold">本文</label>
        <?= $this->Form->textarea('c_body', [
            'id'    => 'c_body',
            'class' => 'form-control',
            'rows'  => 4,
            'value' => $notice->c_body ?? '',
        ]) ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <label for="d_start" class="form-label fw-bold">掲示開始日</label>
            <small class="text-muted d-block mb-1">空欄の場合は即時掲示</small>
            <input type="date" id="d_start" name="d_start" class="form-control"
                value="<?= h($startVal) ?>"
                data-validate="date-range"
                data-range-end="d_end">
            <div class="invalid-feedback"></div>
        </div>
        <div class="col-sm-6">
            <label for="d_end" class="form-label fw-bold">掲示終了日</label>
            <small class="text-muted d-block mb-1">空欄の場合は無期限</small>
            <input type="date" id="d_end" name="d_end" class="form-control" value="<?= h($endVal) ?>">
            <div class="invalid-feedback"></div>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">重要度</label>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_normal" value="0"
                    <?= (int)$notice->i_importance === 0 ? 'checked' : '' ?>>
                <label class="form-check-label" for="imp_normal">通常</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_high" value="1"
                    <?= (int)$notice->i_importance === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="imp_high">重要</label>
            </div>
        </div>
    </div>

    <?php if ($isSysAdmin): ?>
    <div class="mb-4">
        <label class="form-label fw-bold">種別</label>
        <small class="text-muted d-block mb-1">リリースノートはダッシュボードで 🚀 アイコン付きで表示されます。</small>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_type" id="type_normal" value="0"
                    <?= (int)$notice->i_type === 0 ? 'checked' : '' ?>>
                <label class="form-check-label" for="type_normal">通常お知らせ</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_type" id="type_release" value="1"
                    <?= (int)$notice->i_type === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="type_release">🚀 リリースノート</label>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <?= $this->Form->submit('更新する', ['class' => 'btn btn-primary', 'id' => 'submit-btn']) ?>
        <?= $this->Html->link('キャンセル', ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initRealtimeValidation('notice-form', 'submit-btn');
});
</script>
