<?php
/**
 * お知らせ新規作成
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', 'お知らせ作成');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content" style="max-width: 640px;">
    <h3 class="mb-4">
        <i class="bi bi-megaphone"></i> お知らせ作成
    </h3>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'method' => 'post']) ?>

    <div class="mb-3">
        <label for="c_title" class="form-label fw-bold">タイトル <span class="text-danger">*</span></label>
        <?= $this->Form->text('c_title', [
            'id'          => 'c_title',
            'class'       => 'form-control',
            'required'    => true,
            'maxlength'   => 200,
            'placeholder' => '例：6月30日が予約締切日です',
        ]) ?>
    </div>

    <div class="mb-3">
        <label for="c_body" class="form-label fw-bold">本文</label>
        <small class="text-muted d-block mb-1">省略可。複数行の入力が可能です。</small>
        <?= $this->Form->textarea('c_body', [
            'id'    => 'c_body',
            'class' => 'form-control',
            'rows'  => 4,
        ]) ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <label for="d_start" class="form-label fw-bold">掲示開始日</label>
            <small class="text-muted d-block mb-1">空欄の場合は即時掲示</small>
            <?= $this->Form->date('d_start', [
                'id'    => 'd_start',
                'class' => 'form-control',
            ]) ?>
        </div>
        <div class="col-sm-6">
            <label for="d_end" class="form-label fw-bold">掲示終了日</label>
            <small class="text-muted d-block mb-1">空欄の場合は無期限</small>
            <?= $this->Form->date('d_end', [
                'id'    => 'd_end',
                'class' => 'form-control',
            ]) ?>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">重要度</label>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_normal" value="0" checked>
                <label class="form-check-label" for="imp_normal">通常</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_high" value="1">
                <label class="form-check-label" for="imp_high">重要</label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <?= $this->Form->submit('登録する', ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link('キャンセル', ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>
