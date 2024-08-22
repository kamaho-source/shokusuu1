<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TReservationInfo $tReservationInfo
 * @var array $rooms
 * @var string $reservationDate
 */
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= __('予約情報を追加') ?></h3>
            </div>
            <div class="card-body">
                <?= $this->Form->create($tReservationInfo) ?>
                <fieldset>
                    <legend><?= __('予約の詳細') ?></legend>

                    <div class="form-group row">
                        <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('d_reservation_date', [
                                'type' => 'text',
                                'value' => isset($reservationDate) ? $reservationDate : '',
                                'label' => false,
                                'class' => 'form-control',
                                'disabled' => true
                            ]) ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->control('i_id_room', [
                            'type' => 'select',
                            'label' => '部屋名',
                            'options' => $rooms,
                            'empty' => '-- 部屋を選択 --',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <div class="form-group">
                        <?php
                        $reservationTypes = [
                            1 => '朝',
                            2 => '昼',
                            3 => '夜'
                        ];
                        ?>
                        <?= $this->Form->control('c_reservation_type', [
                            'label' => '予約タイプ',
                            'type' => 'select',
                            'options' => $reservationTypes,
                            'empty' => '-- 予約タイプを選択 --',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->control('i_taberu_ninzuu', [
                            'label' => '食べる人数',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->control('i_tabenai_ninzuu', [
                            'label' => '食べない人数',
                            'class' => 'form-control'
                        ]) ?>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('予約を追加'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
