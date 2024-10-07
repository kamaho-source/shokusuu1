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
                        <?php $reservationTypes = [
                            1 => '個人',
                            2 => '集団'
                        ]; ?>
                        <?= $this->Form->control('c_reservation_type', [
                            'label' => '予約タイプ',
                            'type' => 'select',
                            'options' => $reservationTypes,
                            'empty' => '-- 予約タイプを選択 --',
                            'class' => 'form-control',
                            'id' => 'reservation-type-select' // idを追加
                        ]) ?>
                    </div>

                    <!-- 部屋のチェックボックス（個人用） -->
                    <div class="form-group" id="room-checkboxes-group" style="display: none;">
                        <?= $this->Form->label('rooms', '部屋') ?>
                        <div id="room-checkboxes" class="form-check">
                            <!-- 部屋のチェックボックスがここに動的に追加されます -->
                        </div>
                    </div>

                    <!-- 部屋のセレクトボックス（集団用） -->
                    <div class="form-group" id="room-select-group" style="display: none;">
                        <?= $this->Form->label('room-select', '部屋を選択') ?>
                        <?= $this->Form->control('room_select', [
                            'type' => 'select',
                            'label' => false,
                            'options' => $rooms,
                            'empty' => '-- 部屋を選択 --',
                            'class' => 'form-control',
                            'id' => 'room-select' // idを追加
                        ]) ?>
                    </div>

                    <!-- 部屋に属するユーザーのテーブル -->
                    <div class="form-group" id="user-selection" style="display: none;">
                        <?= $this->Form->label('users', '部屋に属する利用者') ?>
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
                                <!-- ユーザーが動的に追加されます -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScriptでの動的リスト取得 -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('reservation-type-select').addEventListener('change', function() {
            var reservationType = this.value;
            var roomCheckboxesGroup = document.getElementById('room-checkboxes-group');
            var roomSelectGroup = document.getElementById('room-select-group');
            var userSelection = document.getElementById('user-selection');
            var userCheckboxes = document.getElementById('user-checkboxes');
            var roomCheckboxes = document.getElementById('room-checkboxes');

            // チェックボックスとセレクトボックスのクリア
            userCheckboxes.innerHTML = '';
            roomCheckboxes.innerHTML = '';

            // Reservation typeによる表示制御
            if (reservationType == 1) {
                // Individual selected
                roomCheckboxesGroup.style.display = 'block';
                roomSelectGroup.style.display = 'none';
                userSelection.style.display = 'none';

                <?php foreach ($rooms as $roomId => $roomName): ?>
                var checkboxWrapper = document.createElement('div');
                checkboxWrapper.className = 'form-check';

                var checkbox = document.createElement('input');
                checkbox.className = 'form-check-input';
                checkbox.type = 'checkbox';
                checkbox.name = 'room_ids[]';
                checkbox.value = <?= json_encode($roomId) ?>;
                checkbox.id = 'room-<?= $roomId ?>';

                var label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = 'room-<?= $roomId ?>';
                label.appendChild(document.createTextNode(<?= json_encode($roomName) ?>));

                checkboxWrapper.appendChild(checkbox);
                checkboxWrapper.appendChild(label);
                roomCheckboxes.appendChild(checkboxWrapper);
                <?php endforeach; ?>

            } else if (reservationType == 2) {
                // Group selected
                roomCheckboxesGroup.style.display = 'none';
                roomSelectGroup.style.display = 'block';
                userSelection.style.display = 'block';

                var roomSelect = document.getElementById('room-select');

                // イベントリスナーの設定
                roomSelect.removeEventListener('change', handleRoomSelect);
                roomSelect.addEventListener('change', handleRoomSelect);
            } else {
                roomCheckboxesGroup.style.display = 'none';
                roomSelectGroup.style.display = 'none';
                userSelection.style.display = 'none';
            }
        });

        function handleRoomSelect() {
            var roomId = this.value;
            var userCheckboxes = document.getElementById('user-checkboxes');
            userCheckboxes.innerHTML = '';

            if (roomId) {
                // 相対URLを使用してエンドポイントにアクセス
                var url = `/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}`;

                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => { throw new Error(text); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        // レスポンスの検証
                        if (!data.usersByRoom) {
                            console.error('Invalid JSON response: usersByRoom property is missing');
                            alert('Invalid JSON response: usersByRoom property is missing');
                            return;
                        }

                        // ユーザー情報の表示
                        data.usersByRoom.forEach(user => {
                            var row = document.createElement('tr');

                            var nameCell = document.createElement('td');
                            nameCell.appendChild(document.createTextNode(user.name));

                            var morningCell = document.createElement('td');
                            var morningCheckbox = document.createElement('input');
                            morningCheckbox.className = 'form-check-input';
                            morningCheckbox.type = 'checkbox';
                            morningCheckbox.name = `morning_${user.id}`;
                            morningCheckbox.value = 1;
                            morningCell.appendChild(morningCheckbox);

                            var afternoonCell = document.createElement('td');
                            var afternoonCheckbox = document.createElement('input');
                            afternoonCheckbox.className = 'form-check-input';
                            afternoonCheckbox.type = 'checkbox';
                            afternoonCheckbox.name = `afternoon_${user.id}`;
                            afternoonCheckbox.value = 1;
                            afternoonCell.appendChild(afternoonCheckbox);

                            var eveningCell = document.createElement('td');
                            var eveningCheckbox = document.createElement('input');
                            eveningCheckbox.className = 'form-check-input';
                            eveningCheckbox.type = 'checkbox';
                            eveningCheckbox.name = `evening_${user.id}`;
                            eveningCheckbox.value = 1;
                            eveningCell.appendChild(eveningCheckbox);

                            row.appendChild(nameCell);
                            row.appendChild(morningCell);
                            row.appendChild(afternoonCell);
                            row.appendChild(eveningCell);

                            userCheckboxes.appendChild(row);
                        });
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        alert('Fetch error: ' + error.message);
                    });
            }
        }
    });
</script>
