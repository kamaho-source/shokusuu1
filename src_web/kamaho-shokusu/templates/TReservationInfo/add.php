<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>

            <?php
            // クエリパラメータから日付を取得し、月曜日かどうかをチェック
            $date = $this->request->getQuery('date') ?? date('Y-m-d');
            if (date('N', strtotime($date)) == 1): // 月曜日かどうかをチェック ?>
                <?= $this->Html->link(__('週の一括予約'), ['action' => 'bulkAddForm', '?' => ['date' => $date]], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= __('予約の追加') ?></h3>
            </div>
            <div class="card-body">
                <?= $this->Form->create($tReservationInfo) ?>
                <fieldset>
                    <legend><?= __('Reservation Details') ?></legend>
                    <div class="form-group row">
                        <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('d_reservation_date', [
                                'type' => 'date',
                                'label' => false,
                                'class' => 'form-control',
                                'disabled' => true, // 日付は修正できないようにする
                                'value' => $date // クリックされた日付を表示
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
                <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
