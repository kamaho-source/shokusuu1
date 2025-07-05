<?php
// 変数の初期化（コントローラから渡されていない場合の保険）
$user = $this->request->getAttribute('identity'); // ユーザー情報を取得
$selectedDate = $selectedDate ?? date('Y-m-d');
$dates = $dates ?? [];
$rooms = $rooms ?? [];
$users = $users ?? [];
$dayOfWeekList = ['月', '火', '水', '木', '金', '土', '日'];

// 今日の日付と1ヶ月後の日付を取得
$currentDate = new \DateTime();
$oneMonthLater = (clone $currentDate)->modify('+30 days');

// 選択された日付
$selectedDateObj = new \DateTime($selectedDate);

// 予約不可の条件（今日から1ヶ月後まで）
$isDisabled = ($selectedDateObj < $oneMonthLater);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>一括予約</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
    <!-- 必要に応じて他のhead要素 -->
</head>
<body>
<div class="container">
    <h1>一括予約</h1>
    <h3>日付: <?= h($selectedDate) ?></h3>

    <form action="<?= $this->Url->build(['action' => 'bulkAddSubmit']) ?>" method="post" id="reservation-form">
        <fieldset>
            <legend>一括予約の日付を選択</legend>
            <?php foreach ($dates as $dateObj): ?>
                <?php
                $dateStr = is_object($dateObj) ? $dateObj->format('Y-m-d') : $dateObj;
                $dayOfWeek = $dayOfWeekList[(new \DateTime($dateStr))->format('N') - 1];
                $isDateDisabled = (new \DateTime($dateStr) < $oneMonthLater);
                ?>
                <div class="form-group">
                    <label><?= h($dateStr) ?> (<?= $dayOfWeek ?>)</label>
                    <input type="checkbox" name="dates[<?= h($dateStr) ?>]" value="1" <?= $isDateDisabled ? 'disabled' : '' ?>>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>予約タイプ選択</legend>
            <div class="form-group">
                <select id="reservation_type" name="reservation_type" class="form-control" required>
                    <option value="" selected disabled>-- 予約タイプを選択 --</option>
                    <option value="personal">個人</option>
                    <?php if ($user->get('i_admin') === 1 || $user->get('i_user_level') == 0): ?>
                        <option value="group">集団</option>
                    <?php endif; ?>
                </select>
            </div>
        </fieldset>

        <!-- 個人予約：部屋ごとの食事選択テーブル -->
        <div class="form-group" id="room-selection-table" style="display: none;">
            <?= $this->Form->label('rooms', '部屋名と食事選択') ?>
            <div id="room-table-container">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>部屋名</th>
                        <th><input type="checkbox" onclick="toggleAllRooms(1, this.checked)">朝</th>
                        <th><input type="checkbox" onclick="toggleAllRooms(2, this.checked)">昼</th>
                        <th><input type="checkbox" onclick="toggleAllRooms(3, this.checked)">夜</th>
                        <th><input type="checkbox" onclick="toggleAllRooms(4, this.checked)">弁当</th>
                    </tr>
                    </thead>
                    <tbody id="room-checkboxes">
                    <?php foreach ($rooms as $roomId => $roomName): ?>
                        <tr>
                            <td><?= h($roomName) ?></td>
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

        <!-- 集団予約フォーム -->
        <fieldset id="group-reservation-fieldset" style="display: none;">
            <legend>集団予約</legend>
            <div class="form-group">
                <?= $this->Form->label('i_id_room', '部屋を選択') ?>
                <?= $this->Form->control('i_id_room', [
                    'type' => 'select',
                    'label' => false,
                    'options' => $rooms,
                    'empty' => '-- 部屋を選択 --',
                    'class' => 'form-control',
                    // 'required' => true, // ← ここは外す
                    'onchange' => "fetchUsersByRoom(this.value)",
                    'disabled' => $isDisabled,
                    'id' => 'i-id-room'
                ]) ?>
            </div>
            <div id="user-table-container">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>利用者名</th>
                        <th><input type="checkbox" onclick="toggleAllUsers('morning', this.checked)">朝</th>
                        <th><input type="checkbox" onclick="toggleAllUsers('noon', this.checked)">昼</th>
                        <th><input type="checkbox" onclick="toggleAllUsers('night', this.checked)">夜</th>
                        <th><input type="checkbox" onclick="toggleAllUsers('bento', this.checked)">弁当</th>
                    </tr>
                    </thead>
                    <tbody id="user-checkboxes">
                    <!-- ユーザー情報はAjaxで動的に表示 -->
                    </tbody>
                </table>
            </div>
        </fieldset>

        <?php if (!$isDisabled): ?>
            <button class="btn btn-primary" type="submit">一括予約を登録</button>
            <div id="loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                <div style="position: relative; top: 50%; transform: translateY(-50%);">
                    <div class="spinner-border text-info" role="status"></div>
                    <p style="color: white; margin-top: 10px;">処理中です。少々お待ちください...</p>
                </div>
            </div>
        <?php else: ?>
            <button class="btn btn-secondary" disabled>一括予約不可（当日から1ヶ月後までは登録不可）</button>
        <?php endif; ?>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const reservationType = document.getElementById('reservation_type');
        const roomSelectionTable = document.getElementById('room-selection-table');
        const groupFieldset = document.getElementById('group-reservation-fieldset');
        const form = document.getElementById('reservation-form');
        const iIdRoom = document.getElementById('i-id-room');

        // 予約タイプ選択時にフォーム表示切り替え＋required制御
        reservationType.addEventListener('change', function() {
            if (this.value === 'personal') {
                roomSelectionTable.style.display = '';
                groupFieldset.style.display = 'none';
                if (iIdRoom) iIdRoom.removeAttribute('required');
            } else if (this.value === 'group') {
                roomSelectionTable.style.display = 'none';
                groupFieldset.style.display = '';
                if (iIdRoom) iIdRoom.setAttribute('required', 'required');
            } else {
                roomSelectionTable.style.display = 'none';
                groupFieldset.style.display = 'none';
                if (iIdRoom) iIdRoom.removeAttribute('required');
            }
        });
        // 初期状態
        roomSelectionTable.style.display = 'none';
        groupFieldset.style.display = 'none';
        if (iIdRoom) iIdRoom.removeAttribute('required');

        // ローディング制御
        const overlay = document.getElementById('loading-overlay');
        const submitButton = form.querySelector('button[type="submit"]');
        const showLoading = () => {
            if (overlay) overlay.style.display = 'block';
            if (submitButton) submitButton.disabled = true;
        };
        const hideLoading = () => {
            if (overlay) overlay.style.display = 'none';
            if (submitButton) submitButton.disabled = false;
        };

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showLoading();
            const formData = new FormData(form);
            // CSRFトークン取得（nullチェック付き）
            const csrfMeta = document.querySelector('meta[name="csrfToken"]');
            const csrfToken = csrfMeta ? csrfMeta.content : '';
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            })
                .then((response) => response.json())
                .then((serverResponse) => {
                    if (serverResponse.status === 'success') {
                        alert(serverResponse.message || '登録が完了しました');
                        if (serverResponse.redirect_url) {
                            window.location.href = serverResponse.redirect_url;
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(serverResponse.message || 'エラーが発生しました');
                        hideLoading();
                    }
                })
                .catch((error) => {
                    alert('通信エラーが発生しました');
                    hideLoading();
                });
        });
    });

    // 部屋ごと全選択
    function toggleAllRooms(mealType, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name^="meals[${mealType}]"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    }

    // 集団予約：全選択
    function toggleAllUsers(mealTime, isChecked) {
        const checkboxes = document.querySelectorAll(`#user-table-container input[name$="[${mealTime}]"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    }

    // 集団予約：部屋選択時に利用者取得
    function fetchUsersByRoom(roomId) {
        if (!roomId) return;
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
            .then(response => response.json())
            .then(data => {
                if (data && data.users) {
                    renderUsers(data.users);
                } else {
                    document.getElementById('user-checkboxes').innerHTML = '<tr><td colspan="5">利用者が見つかりません。</td></tr>';
                }
            })
            .catch(error => {
                document.getElementById('user-checkboxes').innerHTML = '<tr><td colspan="5">データを取得できませんでした。</td></tr>';
            });
    }

    function renderUsers(users) {
        const userTableBody = document.getElementById('user-checkboxes');
        userTableBody.innerHTML = '';
        users.forEach(user => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${user.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][morning]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][noon]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][night]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${user.id}][bento]" value="1"></td>
            `;
            userTableBody.appendChild(row);
        });
    }
</script>
</body>
</html>