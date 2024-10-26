<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TIndividualReservationInfo $tReservationInfo
 * @var array $users
 * @var array $rooms
 */

use Cake\Form\Form;
use Cake\Form\Schema;
use Cake\Validation\Validator;

$this->Html->script('reservation', ['block' => true]);
$this->Html->css(['bootstrap.min']);
?>
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

                    <!-- 予約タイプの選択 -->
                    <div class="form-group">
                        <?php
                        $reservationTypes = [
                            1 => '個人',
                            2 => '集団'
                        ]; ?>
                        <label for="c_reservation_type">予約タイプ(個人/集団)</label>
                        <select id="c_reservation_type" name="reservation_type" class="form-control">
                            <option value="" selected disabled>-- 予約タイプを選択 --</option>
                            <?php foreach ($reservationTypes as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 個人予約用の部屋と食事選択テーブル -->
                    <div class="form-group" id="room-selection-table" style="display: none;">
                        <?= $this->Form->label('rooms', '部屋名と食事選択') ?>
                        <div id="room-table-container">
                            <table class="table table-bordered">
                                <thead>
                                <tr>
                                    <th>部屋名</th>
                                    <th>朝</th>
                                    <th>昼</th>
                                    <th>夜</th>
                                </tr>
                                </thead>
                                <tbody id="room-checkboxes">
                                <!-- 部屋名とチェックボックスが動的に追加されます -->
                                <?php foreach ($rooms as $roomId => $roomName): ?>
                                    <tr>
                                        <td><?= $roomName ?></td>
                                        <td><?= $this->Form->checkbox("meals.morning[$roomId]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("meals.afternoon[$roomId]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("meals.evening[$roomId]", ['value' => 1]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 集団予約用の部屋セレクトボックス -->
                    <div class="form-group" id="room-select-group" style="display: none;">
                        <?= $this->Form->label('room-select', '部屋を選択') ?>
                        <?= $this->Form->control('i_id_room', [
                            'type' => 'select',
                            'label' => false,
                            'options' => $rooms,
                            'empty' => '-- 部屋を選択 --',
                            'class' => 'form-control',
                            'id' => 'room-select'
                        ]) ?>
                    </div>

                    <!-- 集団予約用の利用者テーブル -->
                    <div class="form-group" id="user-selection-table" style="display: none;">
                        <?= $this->Form->label('users', '部屋に属する利用者と食事選択') ?>
                        <div id="user-table-container">
                            <table class="table table-bordered">
                                <thead>
                                <tr>
                                    <th>利用者名</th>
                                    <th>朝</th>
                                    <th>昼</th>
                                    <th>夜</th>
                                </tr>
                                </thead>
                                <tbody id="user-checkboxes">
                                <!-- 利用者名とチェックボックスが動的に追加されます -->
                                <?php foreach ($users as $userId => $userName): ?>
                                    <tr>
                                        <td><?= $userName ?></td>
                                        <td><?= $this->Form->checkbox("users[$userId][morning]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("users[$userId][afternoon]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("users[$userId][evening]", ['value' => 1]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('登録'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- 部屋データをJavaScriptオブジェクトとして出力 -->
<script>
    var roomsData = <?= json_encode($rooms); ?>;
</script>
