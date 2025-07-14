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
<script>
    /* ========= 汎用ユーティリティ ===================================== */
    function toggleAllRooms(mealType, isChecked) {
        const cbs = document.querySelectorAll(
            `input[type="checkbox"][name^="meals[${mealType}]"]`
        );
        cbs.forEach(cb => {
            cb.checked = isChecked;
            /* 排他制御と連動させるため change イベントを発火 */
            cb.dispatchEvent(new Event('change'));
        });
    }
    function toggleAllUsers(mealTime, isChecked) {
        const cbs = document.querySelectorAll(
            `#user-table-container input[type="checkbox"][name$="[${mealTime}]"]`
        );
        cbs.forEach(cb => {
            cb.checked = isChecked;
            cb.dispatchEvent(new Event('change'));
        });
    }

    /* ========= 昼⇄弁当 排他ペアリング ================================ */
    function setupLunchBentoPair(lunchCb, bentoCb) {
        if (!lunchCb || !bentoCb) return;
        if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

        const sync = () => {
            if (lunchCb.checked) bentoCb.checked = false;
            if (bentoCb.checked) lunchCb.checked = false;
            bentoCb.disabled = lunchCb.checked;
            lunchCb.disabled = bentoCb.checked;
        };
        lunchCb.addEventListener('change', sync);
        bentoCb.addEventListener('change', sync);
        sync();

        lunchCb.dataset._paired = '1';
        bentoCb.dataset._paired = '1';
    }

    /* ========= 部屋行（meals[2] ⇄ meals[4]）自動ペアリング ============ */
    function setupAllRoomPairs() {
        document
            .querySelectorAll('input[type="checkbox"][name^="meals[2]["]')
            .forEach(lunchCb => {
                const m = lunchCb.name.match(/^meals\[2]\[(.+)]$/);
                if (!m) return;
                const roomId = m[1];
                const bentoCb = document.querySelector(
                    `input[type="checkbox"][name="meals[4][${roomId}]"]`
                );
                setupLunchBentoPair(lunchCb, bentoCb);
            });
    }

    /* ========= Ajax で個人予約情報を取得 & 反映 ===================== */
    function fetchPersonalReservationData() {
        const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getPersonalReservation` +
            `?date=${encodeURIComponent("<?= $selectedDate ?>")}`;
        showLoading();
        fetch(url)
            .then(r => r.json())
            .then(d => {
                const res = (d.data && d.data.reservation) || {};
                document
                    .querySelectorAll('#room-checkboxes input[type="checkbox"]')
                    .forEach(cb => {
                        const m = cb.name.match(/^meals\[(\d+)]/);
                        if (!m) return;
                        const type = m[1];
                        cb.checked = res[type] == true || Number(res[type]) === 1;
                        cb.dispatchEvent(new Event('change'));
                    });
            })
            .catch(e => console.error('個人予約取得失敗', e))
            .finally(hideLoading);
    }

    /* ========= 共通ローディング制御 ================================ */
    const overlay = document.getElementById('loading-overlay');
    const submitButton = document.querySelector('#reservation-form button[type="submit"]');
    function showLoading() {
        if (overlay) overlay.style.display = 'block';
        if (submitButton) submitButton.disabled = true;
    }
    function hideLoading() {
        if (overlay) overlay.style.display = 'none';
        if (submitButton) submitButton.disabled = false;
    }

    /* ========= DOMContentLoaded ==================================== */
    document.addEventListener('DOMContentLoaded', () => {
        const reservationType = document.getElementById('reservation_type');
        const roomSelectionTable = document.getElementById('room-selection-table');
        const groupFieldset = document.getElementById('group-reservation-fieldset');
        const form = document.getElementById('reservation-form');
        const iIdRoom = document.getElementById('i-id-room');

        /* 予約タイプ切替 */
        reservationType.addEventListener('change', function () {
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
        roomSelectionTable.style.display = 'none';
        groupFieldset.style.display = 'none';
        if (iIdRoom) iIdRoom.removeAttribute('required');

        /* ---------- 排他ペアリング設定 ------------------------------- */
        /* 部屋テーブル行内（昼⇄弁当） */
        document.querySelectorAll('#room-checkboxes tr').forEach(tr => {
            setupLunchBentoPair(
                tr.querySelector('input[name^="meals[2]"]'),
                tr.querySelector('input[name^="meals[4]"]')
            );
        });
        /* 部屋 ID 別ペアリング（行外配置にも対応） */
        setupAllRoomPairs();

        /* ヘッダー “全選択” チェックボックス排他 */
        setupLunchBentoPair(
            document.querySelector('#room-table-container thead input[onclick^="toggleAllRooms(2"]'),
            document.querySelector('#room-table-container thead input[onclick^="toggleAllRooms(4"]')
        );
        setupLunchBentoPair(
            document.querySelector('#user-table-container thead input[onclick^="toggleAllUsers(\'noon\'"]'),
            document.querySelector('#user-table-container thead input[onclick^="toggleAllUsers(\'bento\'"]')
        );

        /* 個人予約データを取得して反映 */
        fetchPersonalReservationData();

        /* 送信処理（変更なし） */
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showLoading();
            const formData = new FormData(form);
            const csrfMeta = document.querySelector('meta[name="csrfToken"]');
            const csrfToken = csrfMeta ? csrfMeta.content : '';
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {'X-CSRF-Token': csrfToken}
            })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        alert(res.message || '登録が完了しました');
                        if (res.redirect_url) {
                            window.location.href = res.redirect_url;
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(res.message || 'エラーが発生しました');
                        hideLoading();
                    }
                })
                .catch(() => {
                    alert('通信エラーが発生しました');
                    hideLoading();
                });
        });
    });

    /* ========= Ajax：部屋選択時に利用者取得 ======================= */
    function fetchUsersByRoom(roomId) {
        if (!roomId) return;
        showLoading();
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
            .then(r => r.json())
            .then(d => {
                if (d && Array.isArray(d.users)) {
                    renderUsers(d.users);
                } else {
                    document.getElementById('user-checkboxes').innerHTML =
                        '<tr><td colspan="5">利用者が見つかりません。</td></tr>';
                }
            })
            .catch(() => {
                document.getElementById('user-checkboxes').innerHTML =
                    '<tr><td colspan="5">データを取得できませんでした。</td></tr>';
            })
            .finally(hideLoading);
    }

    function renderUsers(users) {
        const tbody = document.getElementById('user-checkboxes');
        tbody.innerHTML = '';
        users.forEach(u => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${u.name}</td>
                <td><input class="form-check-input" type="checkbox" name="users[${u.id}][morning]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${u.id}][noon]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${u.id}][night]" value="1"></td>
                <td><input class="form-check-input" type="checkbox" name="users[${u.id}][bento]" value="1"></td>
            `;
            tbody.appendChild(tr);

            /* 利用者ごとの昼⇄弁当排他 */
            setupLunchBentoPair(
                tr.querySelector(`input[name="users[${u.id}][noon]"]`),
                tr.querySelector(`input[name="users[${u.id}][bento]"]`)
            );
        });
    }
</script>
</body>
</html>