<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('操作') ?></h4>
            <?= $this->Html->link(__('ユーザー情報一覧'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4><?= __('ユーザー情報の追加') ?></h4>
            </div>
            <div class="card-body">
                <?= $this->Form->create($mUserInfo, ['class' => 'needs-validation', 'novalidate' => true]) ?>
                <fieldset>
                    <!-- ログインID -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_account', [
                            'label' => ['text' => 'ログインID', 'class' => 'form-label'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- パスワード -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_login_passwd', [
                            'label' => ['text' => 'パスワード', 'class' => 'form-label'],
                            'type' => 'password',
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- ユーザー名 -->
                    <div class="mb-3">
                        <?= $this->Form->control('c_user_name', [
                            'label' => ['text' => 'ユーザー名', 'class' => 'form-label'],
                            'class' => 'form-control'
                        ]) ?>
                    </div>

                    <!-- 年齢 -->
                    <div class="mb-3">
                        <?= $this->Form->control('age', [
                            'type' => 'select',
                            'options' => range(1, 80),
                            'label' => ['text' => '年齢', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'empty' => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 役職 -->
                    <div class="mb-3">
                        <?= $this->Form->control('role', [
                            'type' => 'select',
                            'options' => [0 => '職員', 1 => '児童', 3 => 'その他'],
                            'label' => ['text' => '役職', 'class' => 'form-label'],
                            'class' => 'form-control',
                            'empty' => '選択してください'
                        ]) ?>
                    </div>

                    <!-- 部屋情報のチェックボックス -->
                    <div class="mb-3">
                        <label><?= __('所属する部屋') ?></label>
                        <?php if (!empty($rooms)): ?>
                            <?php foreach ($rooms as $roomId => $roomName): ?>
                                <div class="form-check">
                                    <?= $this->Form->checkbox('MUserGroup.' . $roomId . '.i_id_room', [
                                        'value' => $roomId,
                                        'class' => 'form-check-input',
                                        'id' => 'MUserGroup-' . $roomId . '-i_id_room'
                                    ]) ?>
                                    <label class="form-check-label" for="MUserGroup-<?= $roomId ?>-i_id_room"><?= h($roomName) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?= __('表示できる部屋がありません') ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- ユーザー選択 -->
                    <div id="user-selection" class="mb-3" style="display: none;">
                        <label><?= __('部屋に属する利用者をご選択ください') ?></label>
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
                                <!-- 利用者のチェックボックスがここに追加されます -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </fieldset>
                <?= $this->Form->button(__('送信'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', (event) => {
        const fetchUsersByRoom = async (roomId) => {
            const response = await fetch(`http://localhost:8091/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}`);
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Network response was not ok:', errorText);
                throw new Error('Network response was not ok');
            }
            try {
                return await response.json();
            } catch (e) {
                const errorText = await response.text();
                console.error('Failed to parse JSON:', errorText);
                throw new Error('Failed to parse JSON: ' + e.message);
            }
        };

        document.querySelectorAll('.form-check-input').forEach((checkbox) => {
            checkbox.addEventListener('change', function () {
                const roomId = this.value;
                const userSelection = document.getElementById('user-selection');
                const userCheckboxes = document.getElementById('user-checkboxes');

                userCheckboxes.innerHTML = ''; // 既存のチェックボックスをクリア

                if (this.checked) {
                    userSelection.style.display = 'block';
                    fetchUsersByRoom(roomId)
                        .then(data => {
                            if (data.error) {
                                throw new Error(data.error);
                            }
                            if (Array.isArray(data) && data.length === 0) {
                                console.error('No users found for the selected room');
                            }
                            data.forEach(user => {
                                const row = document.createElement('tr');

                                // ユーザー名を表示
                                const nameCell = document.createElement('td');
                                const nameLabel = document.createElement('label');
                                nameLabel.className = 'form-check-label';
                                nameLabel.htmlFor = 'user-' + user.id;
                                nameLabel.textContent = user.name;
                                nameCell.appendChild(nameLabel);
                                row.appendChild(nameCell);

                                // 朝のチェックボックス
                                const morningCell = document.createElement('td');
                                const morningCheckbox = document.createElement('input');
                                morningCheckbox.className = 'form-check-input';
                                morningCheckbox.type = 'checkbox';
                                morningCheckbox.name = `morning_${user.id}`;
                                morningCell.appendChild(morningCheckbox);
                                row.appendChild(morningCell);

                                // 昼のチェックボックス
                                const afternoonCell = document.createElement('td');
                                const afternoonCheckbox = document.createElement('input');
                                afternoonCheckbox.className = 'form-check-input';
                                afternoonCheckbox.type = 'checkbox';
                                afternoonCheckbox.name = `afternoon_${user.id}`;
                                afternoonCell.appendChild(afternoonCheckbox);
                                row.appendChild(afternoonCell);

                                // 夜のチェックボックス
                                const eveningCell = document.createElement('td');
                                const eveningCheckbox = document.createElement('input');
                                eveningCheckbox.className = 'form-check-input';
                                eveningCheckbox.type = 'checkbox';
                                eveningCheckbox.name = `evening_${user.id}`;
                                eveningCell.appendChild(eveningCheckbox);
                                row.appendChild(eveningCell);

                                // 行をテーブルに追加
                                userCheckboxes.appendChild(row);
                            });
                        })
                        .catch(error => console.error('Fetch error:', error));
                } else {
                    userSelection.style.display = 'none';
                }
            });
        });
    })

</script>
