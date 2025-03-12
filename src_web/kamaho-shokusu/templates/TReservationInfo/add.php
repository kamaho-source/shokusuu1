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

$this->assign('title','食数予約の追加');
$this->Html->script('reservation', ['block' => true]);
$this->Html->css(['bootstrap.min']);
echo $this->Html->meta('csrfToken',$this->request->getAttribute('csrfToken'));
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
                <?= $this->Form->create($tReservationInfo, ['id' => 'reservation-form']) ?>
                <fieldset>
                    <legend><?= __("食数予約") ?></legend>
                    <div class="form-group row">
                        <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                        <div class="col-sm-9">
                            <?= $this->Form->control('d_reservation_date', [
                                'type' => 'date',
                                'label' => false,
                                'class' => 'form-control',
                                'disabled' => true,
                                'value' => $date
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
                                    <!-- 朝・昼・夜 それぞれの列にチェックボックスを設置 -->
                                    <th>
                                        朝
                                        <!-- 第2引数 this.checked を渡して、ヘッダのチェック状態に連動させる -->
                                        <input type="checkbox" onclick="toggleAllRooms(1, this.checked)">
                                    </th>
                                    <th>
                                        昼
                                        <input type="checkbox" onclick="toggleAllRooms(2, this.checked)">
                                    </th>
                                    <th>
                                        夜
                                        <input type="checkbox" onclick="toggleAllRooms(3, this.checked)">
                                    </th>
                                    <th>
                                        弁当
                                        <input type="checkbox" onclick="toggleAllRooms(4, this.checked)">
                                    </th>

                                </tr>
                                </thead>
                                <tbody id="room-checkboxes">
                                <?php foreach ($rooms as $roomId => $roomName): ?>
                                    <tr>
                                        <td><?= $roomName ?></td>
                                        <td><?= $this->Form->checkbox("meals[1][$roomId]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("meals[2][$roomId]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("meals[3][$roomId]", ['value' => 1]) ?></td>
                                        <td><?= $this->Form->checkbox("meals[4][$roomId]", ['value' => 1]) ?></td>
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
                                    <th>
                                        <!-- 集団用のトグルも同様に、on/off を受け取り全チェック/全解除 -->
                                        <input type="checkbox" onclick="toggleAllUsers('morning', this.checked)">
                                        朝
                                    </th>
                                    <th>
                                        <input type="checkbox" onclick="toggleAllUsers('noon', this.checked)">
                                        昼
                                    </th>
                                    <th>
                                        <input type="checkbox" onclick="toggleAllUsers('night', this.checked)">
                                        夜
                                    </th>
                                    <th>
                                        <input type="checkbox" onclick="toggleAllUsers('bento', this.checked)">
                                        弁当
                                    </th>

                                </tr>
                                </thead>
                                <tbody id="user-checkboxes">
                                <!-- JavaScriptで動的にユーザー情報を表示 -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <script>
                        /**
                         * 個人予約テーブルの「朝・昼・夜」欄を、ヘッダのチェックに応じて一括で操作する関数
                         * @param {number} mealType - 1: 朝, 2: 昼, 3: 夜
                         * @param {boolean} isChecked - チェック状態 (true: チェック, false: 解除)
                         */
                        function toggleAllRooms(mealType, isChecked) {
                            const checkboxes = document.querySelectorAll(`input[name^="meals[${mealType}]"]`);
                            checkboxes.forEach(checkbox => {
                                checkbox.checked = isChecked;
                            });
                        }

                        /**
                         * 集団予約テーブル全体を一括で操作する関数
                         * @param {string} mealTime - "morning", "noon", "night" のいずれか
                         * @param {boolean} isChecked - チェック状態 (true: チェック, false: 解除)
                         */
                        function toggleAllUsers(mealTime, isChecked) {
                            const mealTimeMapping = {
                                morning: 1, // 朝
                                noon: 2,    // 昼
                                night: 3,  // 夜
                                bento: 4    // 弁当


                            };

                            const mealType = mealTimeMapping[mealTime];
                            if (!mealType) {
                                console.error('無効なmealTime:', mealTime);
                                return;
                            }

                            // 指定された時間帯のチェックボックスを取得
                            const checkboxes = document.querySelectorAll(`input[name^="users"][name$="[${mealType}]"]`);
                            checkboxes.forEach(checkbox => {
                                checkbox.checked = isChecked;
                            });
                        }
                    </script>
                </fieldset>
                <?= $this->Form->button(__('登録'), ['class' => 'btn btn-primary']) ?>
                <!-- オーバーレイ -->
                <div id="loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                    <div style="position: relative; top: 50%; transform: translateY(-50%);">
                        <!-- スピナー -->
                        <div class="spinner-border text-info" role="status">

                        </div>
                        <p style="color: black; margin-top: 10px;">処理中です。少々お待ちください...</p>
                    </div>
                </div>


                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- 部屋データをJavaScriptオブジェクトとして出力 -->
<script>
    var roomsData = <?= json_encode($rooms); ?>;
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('reservation-form');
        const overlay = document.getElementById('loading-overlay');
        const submitButton = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', function () {
            // オーバーレイを表示して画面全体をブロック
            overlay.style.display = 'block';
            // ボタンを無効化
            submitButton.disabled = true;
        });
    });

</script>
