<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 */
?>
<div class="row">
    <aside class="col-md-3">
        <div class="p-3 bg-light rounded">
            <h4><?= __('アクション') ?></h4>
            <?= $this->Html->link(__('部屋情報一覧'), ['action' => 'index'], ['class' => 'btn btn-secondary btn-block mt-2']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card mb-3">
            <div class="card-header">
                <h4><?= __('部屋情報の追加') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mRoomInfo, ['class' => 'form']) ?>
                <fieldset>
                    <div class="form-group">
                        <?= $this->Form->control('c_room_name', [
                            'label' => '部屋名',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
