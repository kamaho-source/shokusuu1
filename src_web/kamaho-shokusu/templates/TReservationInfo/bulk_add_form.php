<?php
$this->Html->script('bulk_add_form', ['block' => true]);
echo $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken'));
?>

<div class="container">
    <h1>一括予約</h1>
    <h3>日付: <?= h($selectedDate) ?></h3>

    <!-- 一括予約チェックボックス -->
    <form action="<?= $this->Url->build(['action' => 'bulkAddSubmit']) ?>" method="post" id="reservation-form">
        <fieldset>
            <legend>一括予約の日付を選択</legend>

            <div class="form-group">
                <label><?= $dates[0]->format('Y-m-d') ?>(月)</label>
                <input type="checkbox" name="dates[<?= $dates[0]->format('Y-m-d') ?>]" value="1" id="monday">
            </div>
            <div class="form-group">
                <label><?= $dates[1]->format('Y-m-d') ?>(火)</label>
                <input type="checkbox" name="dates[<?= $dates[1]->format('Y-m-d') ?>]" value="1" id="tuesday">
            </div>
            <div class="form-group">
                <label><?= $dates[2]->format('Y-m-d') ?>(水)</label>
                <input type="checkbox" name="dates[<?= $dates[2]->format('Y-m-d') ?>]" value="1" id="wednesday">
            </div>
            <div class="form-group">
                <label><?= $dates[3]->format('Y-m-d') ?>(木)</label>
                <input type="checkbox" name="dates[<?= $dates[3]->format('Y-m-d') ?>]" value="1" id="thursday">
            </div>
            <div class="form-group">
                <label><?= $dates[4]->format('Y-m-d') ?>(金)</label>
                <input type="checkbox" name="dates[<?= $dates[4]->format('Y-m-d') ?>]" value="1" id="friday">
            </div>
        </fieldset>

        <!-- 食数入力のテーブル -->
        <fieldset>
            <legend>食数予約</legend>

            <div class="form-group">
                <?= $this->Form->label('i_id_room', '部屋を選択') ?>
                <?= $this->Form->control('i_id_room', [
                    'type' => 'select',
                    'label' => false,
                    'options' => $rooms,
                    'empty' => '-- 部屋を選択 --',
                    'class' => 'form-control',
                    'required' => true,
                    'onchange' => "fetchUsersByRoom(this.value)"
                ]) ?>
            </div>

            <div id="user-table-container">
                <div class="d-flex justify-content-between mb-2">
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('morning', true)">全員朝チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('morning', false)">全員朝解除</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('noon', true)">全員昼チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('noon', false)">全員昼解除</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('night', true)">全員夜チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('night', false)">全員夜解除</button>
                </div>

                <div id="user-table-container">
                    <!-- 朝昼夜の食数入力フォーム -->
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
                        <!-- ユーザー情報はAjaxで動的に表示 -->
                        </tbody>
                    </table>
                </div>

            </div>

        </fieldset>

        <button class="btn btn-primary" type="submit">一括予約を登録</button>
    </form>
</div>

<script>
    function fetchUsersByRoom(roomId) {
        // 部屋IDが選ばれた場合のみAjaxリクエストを送信
        if (roomId) {
            fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.users) {
                        renderUsers(data.users);
                    } else {
                        document.getElementById('user-checkboxes').innerHTML = '<tr><td colspan="4">利用者が見つかりません。</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('ユーザー情報の取得に失敗しました:', error);
                    document.getElementById('user-checkboxes').innerHTML = '<tr><td colspan="4">データを取得できませんでした。</td></tr>';
                });
        }
    }

    function renderUsers(users) {
        const userTableBody = document.getElementById('user-checkboxes');
        userTableBody.innerHTML = ''; // テーブルをリセット
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][morning]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][noon]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][night]" value="1"></td>
            `;
            userTableBody.appendChild(row);
        });
    }

    function toggleAllUsers(mealTime, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name$="[${mealTime}]"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    }
</script>
