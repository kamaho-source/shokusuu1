<?php
$this->Html->script('bulk_add_form', ['block' => true]);
echo $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken'));
?>

<div class="container">
    <h1>一括予約</h1>
    <h3>日付: <?= h($selectedDate) ?></h3>

    <?php
    // 今日の日付と1ヶ月後の日付を取得
    $currentDate = new \DateTime();
    $oneMonthLater = (clone $currentDate)->modify('+30 days');

    // 選択された日付
    $selectedDateObj = new \DateTime($selectedDate);

    // 予約不可の条件（今日から1ヶ月後まで）
    $isDisabled = ($selectedDateObj < $oneMonthLater);
    ?>

    <!-- 一括予約チェックボックス -->
    <form action="<?= $this->Url->build(['action' => 'bulkAddSubmit']) ?>" method="post" id="reservation-form">
        <fieldset>
            <legend>一括予約の日付を選択</legend>

            <?php foreach ($dates as $index => $dateObj): ?>
                <?php
                $dateStr = $dateObj->format('Y-m-d');
                $dayOfWeek = ['月', '火', '水', '木', '金'][$index];

                // 予約不可の条件（今日から1ヶ月後まで）
                $isDateDisabled = (new \DateTime($dateStr) < $oneMonthLater);
                ?>
                <div class="form-group">
                    <label><?= h($dateStr) ?> (<?= $dayOfWeek ?>)</label>
                    <input type="checkbox" name="dates[<?= h($dateStr) ?>]" value="1" <?= $isDateDisabled ? 'disabled' : '' ?>>
                </div>
            <?php endforeach; ?>

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
                    'onchange' => "fetchUsersByRoom(this.value)",
                    'disabled' => $isDisabled
                ]) ?>
            </div>

            <div id="user-table-container">
                <div class="d-flex justify-content-between mb-2">
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('morning', true)" <?= $isDisabled ? 'disabled' : '' ?>>全員朝チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('morning', false)" <?= $isDisabled ? 'disabled' : '' ?>>全員朝解除</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('noon', true)" <?= $isDisabled ? 'disabled' : '' ?>>全員昼チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('noon', false)" <?= $isDisabled ? 'disabled' : '' ?>>全員昼解除</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('night', true)" <?= $isDisabled ? 'disabled' : '' ?>>全員夜チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('night', false)" <?= $isDisabled ? 'disabled' : '' ?>>全員夜解除</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('bento', true)" <?= $isDisabled ? 'disabled' : '' ?>>全員弁当チェック</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAllUsers('bento', false)" <?= $isDisabled ? 'disabled' : '' ?>>全員弁当解除</button>
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
                            <th>弁当</th>
                        </tr>
                        </thead>
                        <tbody id="user-checkboxes">
                        <!-- ユーザー情報はAjaxで動的に表示 -->
                        </tbody>
                    </table>
                </div>

            </div>

        </fieldset>

        <?php if (!$isDisabled): ?>
            <button class="btn btn-primary" type="submit">一括予約を登録</button>
            <div id="loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                <div style="position: relative; top: 50%; transform: translateY(-50%);">
                    <!-- スピナー -->
                    <div class="spinner-border text-info" role="status">

                    </div>
                    <p style="color: white; margin-top: 10px;">処理中です。少々お待ちください...</p>
                </div>
            </div>
        <?php else: ?>
            <button class="btn btn-secondary" disabled>一括予約不可（当日から1ヶ月後までは登録不可）</button>
        <?php endif; ?>

    </form>
</div>

<script>
    function fetchUsersByRoom(roomId) {
        if (!roomId) return;

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

    function toggleAllUsers(mealTime, isChecked) {
        const checkboxes = document.querySelectorAll(`input[name$="[${mealTime}]"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
    }

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
