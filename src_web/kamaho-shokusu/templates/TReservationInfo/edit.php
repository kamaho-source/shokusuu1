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
                <h3><?= __('予約情報の編集') ?></h3>
            </div>
            <div class="card-body">
                <?= $this->Form->create() ?>
                <fieldset>
                    <legend><?= __('Reservation Details') ?></legend>
                    <?php if (!empty($tReservationInfos)): ?>
                        <?php foreach ($tReservationInfos as $index => $tReservationInfo): ?>
                            <div class="form-group row">
                                <?= $this->Form->label("d_reservation_date", '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                                <div class="col-sm-9">
                                    <?= $this->Form->control("d_reservation_date", [
                                        'type' => 'date',
                                        'label' => false,
                                        'class' => 'form-control',
                                        'disabled' => true,
                                        'value' => $tReservationInfo->d_reservation_date->format('Y-m-d')
                                    ]) ?>
                                </div>
                            </div>
                            <div class="form-group row">
                                <?= $this->Form->label("i_id_room", '部屋名', ['class' => 'col-sm-3 col-form-label']) ?>
                                <div class="col-sm-9">
                                    <?= $this->Form->control("tReservationInfos.$index.i_id_room", [
                                        'type' => 'select',
                                        'label' => false,
                                        'options' => $rooms,
                                        'empty' => '-- 部屋を選択 --',
                                        'class' => 'form-control',
                                        'value' => $tReservationInfo->i_id_room
                                    ]) ?>
                                </div>
                            </div>
                            <div class="form-group row">
                                <?= $this->Form->label("tReservationInfos.$index.c_reservation_type", '予約タイプ', ['class' => 'col-sm-3 col-form-label']) ?>
                                <div class="col-sm-9">
                                    <?= $this->Form->control("tReservationInfos.$index.c_reservation_type", [
                                        'label' => false,
                                        'type' => 'select',
                                        'options' => [
                                            1 => '朝',
                                            2 => '昼',
                                            3 => '夜',
                                        ],
                                        'empty' => '-- 予約タイプを選択 --',
                                        'class' => 'form-control',
                                        'value' => $tReservationInfo->c_reservation_type
                                    ]) ?>
                                </div>
                            </div>
                            <div class="form-group row">
                                <?= $this->Form->label("i_taberu_ninzuu", '食事を取る人数', ['class' => 'col-sm-3 col-form-label']) ?>
                                <div class="col-sm-9">
                                    <?= $this->Form->control("i_taberu_ninzuu", [
                                        'label' => false,
                                        'class' => 'form-control',
                                        'value' => $tReservationInfo->i_taberu_ninzuu
                                    ]) ?>
                                </div>
                            </div>
                            <div class="form-group row">
                                <?= $this->Form->label("i_tabenai_ninzuu", '食事を取らない人数', ['class' => 'col-sm-3 col-form-label']) ?>
                                <div class="col-sm-9">
                                    <?= $this->Form->control("i_tabenai_ninzuu", [
                                        'label' => false,
                                        'class' => 'form-control',
                                        'value' => $tReservationInfo->i_tabenai_ninzuu
                                    ]) ?>
                                </div>
                            </div>
                            <hr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?= __('編集可能な予約情報がありません。') ?></p>
                    <?php endif; ?>
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
