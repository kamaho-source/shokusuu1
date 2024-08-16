<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TReservationInfo $tReservationInfo
 * @var array $rooms
 */
?>
<div class="row">
    <aside class="col-md-4">
        <div class="list-group">
            <h4 class="list-group-item-heading"><?= __('Actions') ?></h4>
            <?= $this->Form->postLink(
                __('削除'),
                ['action' => 'delete', $tReservationInfo->d_reservation_date],
                ['confirm' => __('本当に {0} の予約を削除してもよろしいですか？', $tReservationInfo->d_reservation_date), 'class' => 'list-group-item list-group-item-action']
            ) ?>
            <?= $this->Html->link(__('予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3><?= __('予約情報の編集') ?></h3>
            </div>
            <div class="card-body">
                <?= $this->Form->create($tReservationInfo, ['class' => 'form-horizontal']) ?>
                <fieldset>
                    <div class="form-group row">
                        <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('d_reservation_date', [
                                'type' => 'date',
                                'label' => false,
                                'class' => 'form-control',
                                'disabled' => true
                            ]) ?>
                        </div>
                    </div>

                    <div class="form-group row">
                        <?= $this->Form->label('i_id_room', '部屋名', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('i_id_room', [
                                'type' => 'select',
                                'label' => false,
                                'options' => $rooms,
                                'empty' => '-- 部屋を選択 --',
                                'class' => 'form-control'
                            ]) ?>
                        </div>
                    </div>

                    <div class="form-group row">
                        <?= $this->Form->label('c_reservation_type', '予約タイプ', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('c_reservation_type', [
                                'label' => false,
                                'type' => 'select',
                                'options' => [
                                    1 => '朝',
                                    2 => '昼',
                                    3 => '夜'
                                ],
                                'empty' => '-- 予約タイプを選択 --',
                                'class' => 'form-control'
                            ]) ?>
                        </div>
                    </div>

                    <div class="form-group row">
                        <?= $this->Form->label('i_taberu_ninzuu', '食事を取る人数', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('i_taberu_ninzuu', [
                                'label' => false,
                                'class' => 'form-control'
                            ]) ?>
                        </div>
                    </div>

                    <div class="form-group row">
                        <?= $this->Form->label('i_tabenai_ninzuu', '食事を取らない人数', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('i_tabenai_ninzuu', [
                                'label' => false,
                                'class' => 'form-control'
                            ]) ?>
                        </div>
                    </div>
                </fieldset>
                <div class="form-group row">
                    <div class="col-sm-9 offset-sm-3">
                        <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                    </div>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
