<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 */
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
                <?= $this->Form->create($mRoomInfo) ?>
                <fieldset>
                    <div class="mb-3">
                        <?= $this->Form->control('c_room_name', [
                            'label' => ['class' => 'form-label', 'text' => '部屋名'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
