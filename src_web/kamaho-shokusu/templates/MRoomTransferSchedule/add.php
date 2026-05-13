<?php
/**
 * @var \App\View\AppView $this
 * @var array $users
 * @var array $rooms
 */

$this->assign('title', '部屋異動予約 登録');
$today = date('Y-m-d');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content" style="max-width: 640px;">
    <h3 class="mb-4">
        <i class="bi bi-arrow-left-right"></i> 部屋異動予約 登録
    </h3>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'method' => 'post']) ?>

    <div class="form-group mb-3">
        <label for="i_id_user" class="font-weight-bold">対象ユーザー <span class="text-danger">*</span></label>
        <?= $this->Form->select('i_id_user', $users, [
            'id'       => 'i_id_user',
            'class'    => 'form-control',
            'empty'    => '-- ユーザーを選択 --',
            'required' => true,
        ]) ?>
    </div>

    <div class="form-group mb-3">
        <label for="i_id_room_from" class="font-weight-bold">異動元部屋</label>
        <small class="text-muted d-block">空欄の場合は「新規配属」として扱います。</small>
        <?= $this->Form->select('i_id_room_from', $rooms, [
            'id'    => 'i_id_room_from',
            'class' => 'form-control',
            'empty' => '-- （新規配属） --',
        ]) ?>
    </div>

    <div class="form-group mb-3">
        <label for="i_id_room_to" class="font-weight-bold">異動先部屋 <span class="text-danger">*</span></label>
        <?= $this->Form->select('i_id_room_to', $rooms, [
            'id'       => 'i_id_room_to',
            'class'    => 'form-control',
            'empty'    => '-- 部屋を選択 --',
            'required' => true,
        ]) ?>
    </div>

    <div class="form-group mb-4">
        <label for="d_effective_date" class="font-weight-bold">有効開始日 <span class="text-danger">*</span></label>
        <small class="text-muted d-block">当日を指定すると即日適用（バッチ次回実行時）になります。</small>
        <?= $this->Form->control('d_effective_date', [
            'type'     => 'date',
            'id'       => 'd_effective_date',
            'class'    => 'form-control',
            'label'    => false,
            'required' => true,
            'min'      => $today,
        ]) ?>
    </div>

    <div class="d-flex gap-2">
        <?= $this->Form->submit('登録する', ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link('キャンセル', ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>
