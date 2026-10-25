<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 */
$this->assign('title', __('部屋情報追加'));
$this->Html->script('realtime-validation.js', ['block' => true]);
?>
<div class="row">
    <?= $this->element('side_actions', [
        'items' => [
            ['tone' => 'back', 'label' => '部屋一覧へ戻る', 'url' => ['action' => 'index']],
        ],
    ]) ?>
    <div class="col-md-9">
        <div class="card mb-3">
            <div class="card-header">
                <h4><?= __('部屋情報の追加') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mRoomInfo, ['class' => 'form', 'id' => 'room-form']) ?>
                <fieldset>
                    <div class="form-group mb-3">
                        <?= $this->Form->control('c_room_name', [
                            'label' => '部屋名',
                            'class' => 'form-control',
                            'data-validate' => 'required',
                            'data-msg-required' => '部屋名を入力してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary', 'id' => 'submit-btn', 'disabled' => true]) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initRealtimeValidation('room-form', 'submit-btn');
});
</script>
