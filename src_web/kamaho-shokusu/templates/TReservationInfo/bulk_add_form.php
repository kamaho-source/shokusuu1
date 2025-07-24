<?php
// 変数の初期化（コントローラから渡されていない場合の保険）
$user          = $this->request->getAttribute('identity'); // ユーザー情報を取得
$selectedDate  = $selectedDate  ?? date('Y-m-d');
$dates         = $dates         ?? [];
$rooms         = $rooms         ?? [];
$users         = $users         ?? [];
$dayOfWeekList = ['月', '火', '水', '木', '金', '土', '日'];

// 今日の日付と2週間後の日付を取得
$currentDate   = new \DateTime();
$twoWeeksLater = (clone $currentDate)->modify('+14 days'); // ★ 変数名を修正

// 選択された日付
$selectedDateObj = new \DateTime($selectedDate);
$this->Html->script('bulk_add_form.js', ['block' => 'script']);
// 予約不可の条件（今日から2週間後まで “含む”）
$isDisabled = ($selectedDateObj >= $currentDate && $selectedDateObj <= $twoWeeksLater); // ★ 判定を修正
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>一括予約</title>
    <meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
</head>
<body>
<div class="container">
    <h1>一括予約</h1>
    <h3>日付: <?= h($selectedDate) ?></h3>

    <form action="<?= $this->Url->build(['action' => 'bulkAddSubmit']) ?>" method="post" id="reservation-form">
        <!-- ★ CakePHP CSRF 用 hidden フィールド -->
        <input type="hidden" name="_csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
        <!-- =========================================================
             日付選択
        ========================================================= -->
        <fieldset>
            <legend>一括予約の日付を選択</legend>
            <?php foreach ($dates as $dateObj): ?>
                <?php
                $dateStr   = is_object($dateObj) ? $dateObj->format('Y-m-d') : $dateObj;
                $dayOfWeek = $dayOfWeekList[(new \DateTime($dateStr))->format('N') - 1];

                // ★ 判定を修正（今日≦日付≦2週間後）
                $dateTimeObj    = new \DateTime($dateStr);
                $isDateDisabled = ($dateTimeObj >= $currentDate && $dateTimeObj <= $twoWeeksLater);
                ?>
                <div class="form-group">
                    <label><?= h($dateStr) ?> (<?= $dayOfWeek ?>)</label>
                    <input type="checkbox"
                           name="dates[<?= h($dateStr) ?>]"
                           value="1"
                        <?= $isDateDisabled ? 'disabled' : '' ?>>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <!-- =========================================================
             予約タイプ選択
        ========================================================= -->
        <fieldset>
            <legend>予約タイプ選択</legend>
            <div class="form-group">
                <select id="reservation_type"
                        name="reservation_type"
                        class="form-control"
                        required>
                    <option value="" selected disabled>-- 予約タイプを選択 --</option>
                    <option value="personal">個人</option>
                    <?php if ($user->get('i_admin') === 1 || $user->get('i_user_level') == 0): ?>
                        <option value="group">集団</option>
                    <?php endif; ?>
                </select>
            </div>
        </fieldset>

        <!-- =========================================================
             個人予約：部屋単位の食事選択
        ========================================================= -->
        <div class="form-group" id="room-selection-table" style="display: none;">
            <?= $this->Form->label('rooms', '部屋名と食事選択') ?>
            <div id="room-table-container">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>部屋名</th>
                        <!-- ★ id を付与してヘッダ同士の排他制御に利用 -->
                        <th><input type="checkbox" id="toggle-room-all-1" onclick="toggleAllRooms(1, this.checked)">朝</th>
                        <th><input type="checkbox" id="toggle-room-all-2" onclick="toggleAllRooms(2, this.checked)">昼</th>
                        <th><input type="checkbox" id="toggle-room-all-3" onclick="toggleAllRooms(3, this.checked)">夜</th>
                        <th><input type="checkbox" id="toggle-room-all-4" onclick="toggleAllRooms(4, this.checked)">弁当</th>
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

        <!-- =========================================================
             集団予約
        ========================================================= -->
        <fieldset id="group-reservation-fieldset" style="display: none;">
            <legend>集団予約</legend>

            <div class="form-group">
                <?= $this->Form->label('i_id_room', '部屋を選択') ?>
                <?= $this->Form->control('i_id_room', [
                    'type'     => 'select',
                    'label'    => false,
                    'options'  => $rooms,
                    'empty'    => '-- 部屋を選択 --',
                    'class'    => 'form-control',
                    'onchange' => "fetchUsersByRoom(this.value)",
                    'disabled' => $isDisabled,
                    'id'       => 'i-id-room'
                ]) ?>
            </div>

            <div id="user-table-container">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>利用者名</th>
                        <!-- ★ id を付与 -->
                        <th><input type="checkbox" id="toggle-user-all-morning" onclick="toggleAllUsers('morning', this.checked)">朝</th>
                        <th><input type="checkbox" id="toggle-user-all-noon"    onclick="toggleAllUsers('noon',    this.checked)">昼</th>
                        <th><input type="checkbox" id="toggle-user-all-night"   onclick="toggleAllUsers('night',   this.checked)">夜</th>
                        <th><input type="checkbox" id="toggle-user-all-bento"   onclick="toggleAllUsers('bento',   this.checked)">弁当</th>
                    </tr>
                    </thead>
                    <tbody id="user-checkboxes"><!-- Ajax で動的に挿入 --></tbody>
                </table>
            </div>
        </fieldset>

        <!-- =========================================================
             送信ボタン
        ========================================================= -->
        <?php if (!$isDisabled): ?>
            <button class="btn btn-primary" type="submit">一括予約を登録</button>
            <div id="loading-overlay"
                 style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;text-align:center;">
                <div style="position:relative;top:50%;transform:translateY(-50%);">
                    <div class="spinner-border text-info" role="status"></div>
                    <p style="color:#fff;margin-top:10px;">処理中です。少々お待ちください...</p>
                </div>
            </div>
        <?php else: ?>
            <button class="btn btn-secondary" disabled>一括予約不可（当日から2週間後までは登録不可）</button>
        <?php endif; ?>
    </form>
</div>

<!-- =========================================================
     JavaScript
========================================================= -->
<script>
    /**
     * 部屋テーブル：ヘッダ「全選択」チェックボックス
     */
    function toggleAllRooms(mealType, isChecked) {
        document.querySelectorAll(`input[name^="meals[${mealType}]"]`).forEach(cb => {
            cb.checked = isChecked;
            cb.dispatchEvent(new Event('change'));

            // 昼⇔弁当 行単位の排他制御
            const match = cb.name.match(/^meals\[(\d+)]\[(\d+)]$/); // meals[mealType][roomId]
            if (match && (mealType === 2 || mealType === 4) && isChecked) {
                const roomId         = match[2];
                const counterpart    = mealType === 2 ? 4 : 2;
                const counterpartCb  = document.querySelector(`input[name="meals[${counterpart}][${roomId}]"]`);
                if (counterpartCb && isChecked) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }
        });

        // ★ ヘッダ同士の排他制御
        if ((mealType === 2 || mealType === 4) && isChecked) {
            const counterpartType   = mealType === 2 ? 4 : 2;
            const counterpartHeader = document.getElementById(`toggle-room-all-${counterpartType}`);
            if (counterpartHeader) counterpartHeader.checked = false;
        }
    }

    /**
     * ユーザーテーブル：ヘッダ「全選択」チェックボックス
     */
    function toggleAllUsers(mealTime, isChecked) {
        const map      = {morning: 1, noon: 2, night: 3, bento: 4};
        const mealType = map[mealTime];
        if (!mealType) return;

        document.querySelectorAll(`#user-table-container input[name$="[${mealTime}]"]`).forEach(cb => {
            cb.checked = isChecked;
            cb.dispatchEvent(new Event('change'));

            // 昼⇔弁当 行単位の排他制御
            const m = cb.name.match(/^users\[(\d+)]\[(.+)]$/); // users[userId][mealTime]
            if (m && (mealType === 2 || mealType === 4) && isChecked) {
                const userId              = m[1];
                const counterpartMealTime = mealType === 2 ? 'bento' : 'noon';
                const counterpartCb       = document.querySelector(`input[name="users[${userId}][${counterpartMealTime}]"]`);
                if (counterpartCb) {
                    counterpartCb.checked = false;
                    counterpartCb.dispatchEvent(new Event('change'));
                }
            }
        });

        // ★ ヘッダ同士の排他制御
        if ((mealType === 2 || mealType === 4) && isChecked) {
            const counterpartHeaderId = mealType === 2 ? 'toggle-user-all-bento' : 'toggle-user-all-noon';
            const counterpartHeader   = document.getElementById(counterpartHeaderId);
            if (counterpartHeader) counterpartHeader.checked = false;
        }
    }

    /* ------------------------------------------------------------------
       朝・夜 チェック時にヘッダを同期させるユーティリティ
    ------------------------------------------------------------------ */
    function updateRoomHeader(mealType) {
        const headerCb = document.getElementById(`toggle-room-all-${mealType}`);
        if (!headerCb) return;
        const allOn = [...document.querySelectorAll(`input[name^="meals[${mealType}]"]`)].every(c => c.checked);
        headerCb.checked = allOn;
    }
    function updateUserHeader(mealKey) {
        const headerId = mealKey === 'morning' ? 'toggle-user-all-morning' : 'toggle-user-all-night';
        const headerCb = document.getElementById(headerId);
        if (!headerCb) return;
        const selector = `#user-table-container input[name$="[${mealKey}]"]`;
        const allOn = [...document.querySelectorAll(selector)].every(c => c.checked);
        headerCb.checked = allOn;
    }

    /**
     * 昼⇔弁当　行単位の排他制御ペアを組む
     * （ヘッダチェックボックスの状態も随時更新）
     */
    function setupLunchBentoPair(lunchCb, bentoCb) {
        if (!lunchCb || !bentoCb) return;
        if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

        const updateHeaderCheckbox = (mealTypeKey) => {
            const selector = `#user-table-container input[name$="[${mealTypeKey}]"]`;
            const allCbs   = document.querySelectorAll(selector);
            const allOn    = [...allCbs].length > 0 && [...allCbs].every(c => c.checked);

            const headerId = mealTypeKey === 'noon' ? 'toggle-user-all-noon'
                : 'toggle-user-all-bento';
            const headerCb = document.getElementById(headerId);
            if (headerCb) headerCb.checked = allOn;
        };

        lunchCb.addEventListener('change', () => {
            if (lunchCb.checked) {
                bentoCb.checked = false;
                bentoCb.dispatchEvent(new Event('change'));
            }
            updateHeaderCheckbox('noon');
            updateHeaderCheckbox('bento');
        });

        bentoCb.addEventListener('change', () => {
            if (bentoCb.checked) {
                lunchCb.checked = false;
                lunchCb.dispatchEvent(new Event('change'));
            }
            updateHeaderCheckbox('noon');
            updateHeaderCheckbox('bento');
        });

        lunchCb.dataset._paired = '1';
        bentoCb.dataset._paired = '1';
    }

    /**
     * 既存の部屋テーブルについて昼⇔弁当ペアを設定
     */
    function setupAllRoomPairs() {
        document.querySelectorAll('input[name^="meals[2]["]').forEach(lunchCb => {
            const m = lunchCb.name.match(/^meals\[2]\[(.+)]$/);
            if (!m) return;
            const roomId  = m[1];
            const bentoCb = document.querySelector(`input[name="meals[4][${roomId}]"]`);
            setupLunchBentoPair(lunchCb, bentoCb);
        });
    }

    /* ------------------------------------------------------------------
       Ajax: 部屋 ID から利用者一覧を取得
    ------------------------------------------------------------------ */
    function showLoading() {
        document.getElementById('loading-overlay').style.display = 'block';
        document.querySelector('#reservation-form button[type="submit"]').disabled = true;
    }
    function hideLoading() {
        document.getElementById('loading-overlay').style.display = 'none';
        document.querySelector('#reservation-form button[type="submit"]').disabled = false;
    }
    function fetchUsersByRoom(roomId) {
        if (!roomId) return;
        showLoading();
        fetch(`/kamaho-shokusu/TReservationInfo/getUsersByRoomForBulk/${roomId}`)
            .then(res   => res.json())
            .then(data  => renderUsers(data.users))
            .catch(()   => alert('利用者情報の取得に失敗しました。'))
            .finally(hideLoading);
    }
    function renderUsers(users) {
        const tbody = document.getElementById('user-checkboxes');
        tbody.innerHTML = '';
        users.forEach(u => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${u.name}</td>
                <td><input type="checkbox" name="users[${u.id}][morning]" value="1"></td>
                <td><input type="checkbox" name="users[${u.id}][noon]"    value="1"></td>
                <td><input type="checkbox" name="users[${u.id}][night]"   value="1"></td>
                <td><input type="checkbox" name="users[${u.id}][bento]"   value="1"></td>
            `;
            tbody.appendChild(tr);

            // 朝・夜 チェック変更時にヘッダ同期
            tr.querySelector(`input[name="users[${u.id}][morning]"]`)
                .addEventListener('change', () => updateUserHeader('morning'));
            tr.querySelector(`input[name="users[${u.id}][night]"]`)
                .addEventListener('change', () => updateUserHeader('night'));

            // 昼⇔弁当 ペア設定
            setupLunchBentoPair(
                tr.querySelector(`input[name="users[${u.id}][noon]"]`),
                tr.querySelector(`input[name="users[${u.id}][bento]"]`)
            );
        });

        // ヘッダ初期同期
        updateUserHeader('morning');
        updateUserHeader('night');
        updateUserHeader('noon');
        updateUserHeader('bento');
    }

    /* ------------------------------------------------------------------
       DOMContentLoaded
    ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', () => {
        const reservationType = document.getElementById('reservation_type');
        const roomSelection   = document.getElementById('room-selection-table');
        const groupFieldset   = document.getElementById('group-reservation-fieldset');
        const iIdRoom         = document.getElementById('i-id-room');

        reservationType.addEventListener('change', () => {
            if (reservationType.value === 'personal') {
                roomSelection.style.display = '';
                groupFieldset.style.display = 'none';
                iIdRoom.removeAttribute('required');
            } else if (reservationType.value === 'group') {
                roomSelection.style.display = 'none';
                groupFieldset.style.display = '';
                iIdRoom.setAttribute('required', 'required');
            } else {
                roomSelection.style.display = 'none';
                groupFieldset.style.display = 'none';
                iIdRoom.removeAttribute('required');
            }
        });

        setupAllRoomPairs(); // 既存行の昼⇔弁当ペア

        /* ----------------------------------------------------------
           朝・夜（部屋テーブル）ヘッダ同期
        ---------------------------------------------------------- */
        document.querySelectorAll('input[name^="meals[1]["], input[name^="meals[3]["]')
            .forEach(cb => {
                const mealType = cb.name.match(/^meals\[(\d)]\[/)[1];
                cb.addEventListener('change', () => updateRoomHeader(mealType));
            });
        // 初期状態を反映
        updateRoomHeader(1);
        updateRoomHeader(3);
    });
</script>
</body>
</html>