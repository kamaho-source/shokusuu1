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

$this->assign('title', '食数予約の追加');
$this->Html->script('reservation.js', ['block' => true]);
$this->Html->css(['bootstrap.min']);
echo $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken'));
$user = $this->request->getAttribute('identity'); // ユーザー情報を取得
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>

            <?php
            $date = $this->request->getQuery('date') ?? date('Y-m-d');
            if (date('N', strtotime($date)) == 1): ?>
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

                    <div class="form-group">
                        <label for="c_reservation_type">予約タイプ(個人/集団)</label>
                        <select id="c_reservation_type" name="reservation_type" class="form-control">
                                <option value="" selected disabled>-- 予約タイプを選択 --</option>
                                <option value="1">個人</option>
                            <?php if ($user->get('i_admin') === 1 || $user->get('i_user_level') == 0): ?>
                                <option value="2">集団</option>
                            <?php endif; ?>
                        </select>
                    </div>

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

                    <?php if ($user->get('i_admin') === 1 || $user->get('i_user_level') == 0): ?>
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

                        <div class="form-group" id="user-selection-table" style="display: none;">
                            <?= $this->Form->label('users', '部屋に属する利用者と食事選択') ?>
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
                                    <tbody id="user-checkboxes"></tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    <script>
                        function toggleAllRooms(mealType, isChecked) {
                            const checkboxes = document.querySelectorAll(
                                `input[type="checkbox"][name^="meals[${mealType}]"]`
                            );

                            checkboxes.forEach(cb => {
                                cb.checked = isChecked;
                                cb.dispatchEvent(new Event('change'));
                            });

                            const headerCheckbox = document.querySelector(
                                `input[type="checkbox"][onclick^="toggleAllRooms(${mealType},"]`
                            );
                            if (headerCheckbox) {
                                const allChecked = [...checkboxes].every(cb => cb.checked);
                                headerCheckbox.checked = allChecked;
                            }
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            // 個人予約テーブルの各チェックボックスに change イベントバインド
                            const mealTypes = [1, 2, 3, 4];
                            mealTypes.forEach(mealType => {
                                const checkboxes = document.querySelectorAll(
                                    `input[type="checkbox"][name^="meals[${mealType}]"]`
                                );

                                const headerCheckbox = document.querySelector(
                                    `input[type="checkbox"][onclick^="toggleAllRooms(${mealType},"]`
                                );

                                checkboxes.forEach(cb => {
                                    cb.removeEventListener('change', cb._onchangeHandler ?? (() => {}));
                                    cb._onchangeHandler = () => {
                                        const allChecked = [...checkboxes].every(c => c.checked);
                                        if (headerCheckbox) {
                                            headerCheckbox.checked = allChecked;
                                        }
                                    };
                                    cb.addEventListener('change', cb._onchangeHandler);
                                });
                            });
                        });
                    </script>
                    <!-- ======================== Script ======================== -->
                    <script>
                        /* 既存のユーティリティ関数はそのまま -------------------- */
                        function toggleAllUsers(mealTime, isChecked) {
                            const map = {morning: 1, noon: 2, night: 3, bento: 4};
                            const mealType = map[mealTime];
                            if (!mealType) return;

                            const checkboxes = document.querySelectorAll(
                                `input[type="checkbox"][name^="users"][name$="[${mealType}]"]`
                            );

                            const headerCheckbox = document.querySelector(
                                `input[type="checkbox"][onclick^="toggleAllUsers('${mealTime}'"]`
                            );

                            checkboxes.forEach(cb => {
                                cb.checked = isChecked;

                                // 昼⇔弁当 排他制御（一括チェック時）
                                const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                                if (match && (mealType === 2 || mealType === 4)) {
                                    const userId = match[1];
                                    const counterpartType = mealType === 2 ? 4 : 2;
                                    const counterpartMealTime = mealType === 2 ? 'bento' : 'noon';
                                    const counterpartCb = document.querySelector(
                                        `input[name="users[${userId}][${counterpartType}]"]`
                                    );
                                    if (counterpartCb && isChecked) {
                                        counterpartCb.checked = false;

                                        // ✅ 変更イベントを明示的に発火（これが重要）
                                        counterpartCb.dispatchEvent(new Event('change'));
                                    }
                                }

                                // 個別チェック変更イベント
                                cb.removeEventListener('change', cb._onchangeHandler ?? (() => {}));
                                cb._onchangeHandler = () => {
                                    const allChecked = [...checkboxes].every(c => c.checked);
                                    if (headerCheckbox) {
                                        headerCheckbox.checked = allChecked;
                                    }

                                    // 排他制御（個別変更時）
                                    const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                                    if (match && (mealType === 2 || mealType === 4)) {
                                        const userId = match[1];
                                        const counterpartType = mealType === 2 ? 4 : 2;
                                        const counterpartMealTime = mealType === 2 ? 'bento' : 'noon';
                                        const counterpartCb = document.querySelector(
                                            `input[name="users[${userId}][${counterpartType}]"]`
                                        );
                                        if (counterpartCb && cb.checked) {
                                            counterpartCb.checked = false;
                                            counterpartCb.dispatchEvent(new Event('change'));
                                        }
                                    }
                                };
                                cb.addEventListener('change', cb._onchangeHandler);
                            });

                            // 初期時点で一括チェックボックスも更新
                            const allChecked = [...checkboxes].every(c => c.checked);
                            if (headerCheckbox) {
                                headerCheckbox.checked = allChecked;
                            }
                        }

                        /* ==== 昼⇄弁当ペアリング用ユーティリティ ================= */
                        function setupLunchBentoPair(lunchCb, bentoCb) {
                            if (!lunchCb || !bentoCb) return;
                            if (lunchCb.dataset._paired || bentoCb.dataset._paired) return;

                            const updateHeaderCheckbox = (changedCb, mealType) => {
                                const allCbs = document.querySelectorAll(`input[name^="users"][name$="[${mealType}]"]`);
                                const headerCb = document.querySelector(
                                    `input[type="checkbox"][onclick^="toggleAllUsers('${mealType === 2 ? 'noon' : 'bento'}'"]`
                                );
                                if (!headerCb) return;
                                const allChecked = [...allCbs].every(c => c.checked);
                                headerCb.checked = allChecked;
                            };

                            lunchCb.addEventListener('change', () => {
                                if (lunchCb.checked) {
                                    bentoCb.checked = false;
                                    bentoCb.dispatchEvent(new Event('change'));

                                    // 昼をチェック → 弁当が外れる → 弁当の一括状態更新
                                    updateHeaderCheckbox(bentoCb, 4);
                                }
                            });

                            bentoCb.addEventListener('change', () => {
                                if (bentoCb.checked) {
                                    lunchCb.checked = false;
                                    lunchCb.dispatchEvent(new Event('change'));

                                    // 弁当をチェック → 昼が外れる → 昼の一括状態更新
                                    updateHeaderCheckbox(lunchCb, 2);
                                }
                            });

                            lunchCb.dataset._paired = '1';
                            bentoCb.dataset._paired = '1';
                        }


                        /* ==== meals[2][roomId] ⇄ meals[4][roomId] 自動ペアリング === */
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

                        /* --------------------------------------------------------- */
                        function fetchPersonalReservationData() {
                            const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getPersonalReservation?date=${encodeURIComponent("<?= $date ?>")}`;
                            showLoading();
                            fetch(url)
                                .then(r => r.json())
                                .then(d => {
                                    const res = d.data.reservation || {};
                                    document
                                        .querySelectorAll('#room-checkboxes input[type="checkbox"]')
                                        .forEach(cb => {
                                            const m = cb.getAttribute('name').match(/^meals\[(\d+)]/);
                                            if (!m) return;
                                            const type = m[1];
                                            cb.checked = res[type] == true || Number(res[type]) === 1;
                                            cb.dispatchEvent(new Event('change')); // 排他制御のため
                                        });

                                    // ✅ ここでペアリング実行（チェックボックスが描画されたあと）
                                    setupAllRoomPairs();
                                })
                                .catch(e => console.error('個人予約取得失敗', e))
                                .finally(hideLoading);
                        }

                        function fetchUserData(roomId) {
                            const url = `${window.location.origin}/kamaho-shokusu/TReservationInfo/getUsersByRoom/${roomId}` +
                                `?date=${encodeURIComponent("<?= $date ?>")}`;
                            showLoading();
                            fetch(url)
                                .then(r => r.json())
                                .then(d => {
                                    const users = d.usersByRoom;
                                    if (!Array.isArray(users)) {
                                        console.error('usersByRoom が配列では無い', users);
                                        return;
                                    }
                                    const tbody = document.getElementById('user-checkboxes');
                                    tbody.innerHTML = '';
                                    users.forEach(u => {
                                        const tr = document.createElement('tr');
                                        tr.innerHTML = `
    <td>${u.name}</td>
    <td><input type="checkbox" name="users[${u.id}][1]" value="1" ${Number(u.morning) === 1 ? 'checked' : ''}></td>
    <td><input type="checkbox" name="users[${u.id}][2]" value="1" ${Number(u.noon) === 1 ? 'checked' : ''}></td>
    <td><input type="checkbox" name="users[${u.id}][3]" value="1" ${Number(u.night) === 1 ? 'checked' : ''}></td>
    <td><input type="checkbox" name="users[${u.id}][4]" value="1" ${Number(u.bento) === 1 ? 'checked' : ''}></td>
`;
                                        tbody.appendChild(tr);

                                        /* ユーザー行の昼⇄弁当排他 */
                                        setupLunchBentoPair(
                                            tr.querySelector(`input[name="users[${u.id}][2]"]`),
                                            tr.querySelector(`input[name="users[${u.id}][4]"]`)
                                        );
                                    });
                                })
                                .catch(e => console.error('ユーザ取得失敗', e))
                                .finally(hideLoading);
                        }
                        function showLoading() {
                            document.getElementById('loading-overlay').style.display = 'block';
                            document.querySelector('#reservation-form button[type="submit"]').disabled = true;
                        }
                        function hideLoading() {
                            document.getElementById('loading-overlay').style.display = 'none';
                            document.querySelector('#reservation-form button[type="submit"]').disabled = false;
                        }

                        /* ========== ページロード時の初期化 ========================= */
                        document.addEventListener('DOMContentLoaded', () => {
                            /* 個人予約テーブル（部屋名エリア）の昼⇄弁当排他 */
                            document.querySelectorAll('#room-checkboxes tr').forEach(tr => {
                                setupLunchBentoPair(
                                    tr.querySelector('input[name^="meals[2]"]'), // 昼
                                    tr.querySelector('input[name^="meals[4]"]')  // 弁当
                                );
                            });

                            /* roomId ごとのペアリング（行外にあっても機能） */
                            setupAllRoomPairs();

                            /* ヘッダーの “全選択” チェックボックス排他 */
                            setupLunchBentoPair(
                                document.querySelector(
                                    '#room-table-container thead input[onclick^="toggleAllRooms(2"]'
                                ),
                                document.querySelector(
                                    '#room-table-container thead input[onclick^="toggleAllRooms(4"]'
                                )
                            );
                            setupLunchBentoPair(
                                document.querySelector(
                                    '#user-table-container thead input[onclick^="toggleAllUsers(\'noon\'"]'
                                ),
                                document.querySelector(
                                    '#user-table-container thead input[onclick^="toggleAllUsers(\'bento\'"]'
                                )
                            );

                            /* 個人予約データを取得して反映（排他処理と連動） */
                            fetchPersonalReservationData();
                        });
                    </script>
                    <!-- ====================== /Script ======================== -->

                </fieldset>
                <?= $this->Form->button(__('登録'), ['class' => 'btn btn-primary']) ?>
                <div id="loading-overlay"
                     style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                    <div style="position: relative; top: 50%; transform: translateY(-50%);">
                        <div class="spinner-border text-info" role="status"></div>
                        <p style="color: white; margin-top: 10px;">処理中です。少々お待ちください...</p>
                    </div>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
<script>
    var roomsData = <?= json_encode($rooms); ?>;
</script>
<script>
    /**
     * 予約タイプ・部屋選択制御と、昼／弁当チェックボックスの相互排他制御
     */
    function initReservationForm() {
        /* ────────── 予約タイプ・部屋選択表示制御 ────────── */
        const typeSelect   = document.getElementById('c_reservation_type');
        const roomTable    = document.getElementById('room-selection-table');
        const roomSelectGp = document.getElementById('room-select-group');  // ★ null 可
        const userTableGp  = document.getElementById('user-selection-table'); // ★ null 可

        const handleTypeChange = () => {
            const val = typeSelect.value;
            if (val === '1') {
                if (roomTable)    roomTable.style.display    = '';
                if (roomSelectGp) roomSelectGp.style.display = 'none';
                if (userTableGp)  userTableGp.style.display  = 'none';
                fetchPersonalReservationData();
            } else if (val === '2') {
                if (roomTable)    roomTable.style.display    = 'none';
                if (roomSelectGp) roomSelectGp.style.display = '';
                if (userTableGp)  userTableGp.style.display  = 'none';
            } else {
                if (roomTable)    roomTable.style.display    = 'none';
                if (roomSelectGp) roomSelectGp.style.display = 'none';
                if (userTableGp)  userTableGp.style.display  = 'none';
            }
        };


        const roomSelect = document.getElementById('room-select');
        const handleRoomChange = () => {
            const roomId = roomSelect.value;
            document.getElementById('user-checkboxes').innerHTML = '';
            if (!roomId) {
                userTableGp.style.display = 'none';
                return;
            }
            userTableGp.style.display = '';
            fetchUserData(roomId);
        };

        /* ────────── 昼／弁当 相互排他制御 ────────── */
        const lunchCb  = document.getElementById('meal-lunch');             // 「昼」
        const bentoCbs = document.querySelectorAll('.meal-bento');         // 複数「弁当」

        const onLunchChange = () => {
            if (lunchCb.checked) {
                // 「昼」がチェックされたら弁当は全てチェックを外す
                bentoCbs.forEach(cb => { cb.checked = false; });
            }
        };

        const onBentoChange = () => {
            // 弁当が一つでもチェックされたら「昼」を外す
            if ([...bentoCbs].some(cb => cb.checked)) {
                lunchCb.checked = false;
            }
        };

        /* ────────── イベント登録 ────────── */
        typeSelect.addEventListener('change', handleTypeChange);
        if (roomSelect) roomSelect.addEventListener('change', handleRoomChange);
        if (lunchCb) lunchCb.addEventListener('change', onLunchChange);
        bentoCbs.forEach(cb => cb.addEventListener('change', onBentoChange));

        /* ────────── 初期表示 ────────── */
        handleTypeChange();
    }

    document.addEventListener('DOMContentLoaded', initReservationForm);
</script>