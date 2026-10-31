<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Infrastructure\Persistence\Entity\MRoomInfo $mRoomInfo
 * @var array $users
 * @var bool $canEditRoom
 * @var bool $canReorder
 */
$this->assign('title', __('部屋情報編集'));
$this->Html->script('realtime-validation.js', ['block' => true]);
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <?= $this->Html->link(__('部屋一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4><?= __('部屋情報の修正') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mRoomInfo, ['id' => 'room-form']) ?>
                <div class="mb-3">
                    <?php if ($canEditRoom): ?>
                        <?= $this->Form->control('c_room_name', [
                            'label' => ['class' => 'form-label', 'text' => '部屋名'],
                            'class' => 'form-control',
                            'data-validate' => 'required',
                            'data-msg-required' => '部屋名を入力してください。',
                        ]) ?>
                        <div class="invalid-feedback"></div>
                    <?php else: ?>
                        <div class="form-label"><?= __('部屋名') ?></div>
                        <div class="form-control-plaintext"><?= h($mRoomInfo->c_room_name) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($canReorder): ?>
                    <?= $this->element('MRoomInfo/member_order', ['users' => $users, 'canReorder' => true]) ?>
                <?php endif; ?>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <?php if ($canReorder): ?>
                        <button type="button" class="btn btn-outline-secondary" id="member-order-undo" disabled><?= __('元に戻す') ?></button>
                    <?php endif; ?>
                    <?= $this->Form->button(__('保存'), ['class' => 'btn btn-primary', 'id' => 'submit-btn']) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canEditRoom): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    initRealtimeValidation('room-form', 'submit-btn');
});
</script>
<?php endif; ?>
