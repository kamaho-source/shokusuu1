<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TIndividualReservationInfo $tReservationInfo
 * @var array $users
 * @var array $rooms
 */

$this->assign('title', '食数予約の追加');
$this->Html->script('reservation.js', ['block' => true]);
$this->Html->script('add.js', ['block' => true]);
$this->Html->css(['bootstrap.min']);
echo $this->Html->css('pages/t_reservation_add.css');
echo $this->Html->meta('csrfToken', $this->request->getAttribute('csrfToken'));
$user = $this->request->getAttribute('identity'); // ユーザー情報を取得

// 画面で使う日付（週リンクやAPIのdateパラメータで使用）
$date = $this->request->getQuery('date') ?? date('Y-m-d');

// ✅ モーダル判定
$isModal = (string)($this->request->getQuery('modal') ?? '') === '1';

// ✅ 既定の部屋（?room= があれば優先。なければログインユーザーの所属部屋を採用）
$defaultRoomId = $this->request->getQuery('room') ?? ($user ? $user->get('i_id_room') : null);
if (!isset($rooms[$defaultRoomId])) {
    // 所属部屋が rooms に無い場合は先頭要素を既定に（null安全）
    $firstKey = is_array($rooms) && $rooms ? array_key_first($rooms) : null;
    if ($firstKey !== null) {
        $defaultRoomId = $firstKey;
    }
}

// ✅ サーバ側でフルURLを作成（モーダル/サブディレクトリ対応）
$URL_GET_PERSONAL = $this->Url->build(
        ['controller' => 'TReservationInfo', 'action' => 'getPersonalReservation', '?' => ['date' => $date]],
        ['fullBase' => true]
);

// ✅ 「:roomId」ではなく無害なトークン __RID__ を含むテンプレURLを生成（JS で置換）
$URL_GET_USERS_BY_ROOM_TPL = $this->Url->build(
        ['controller' => 'TReservationInfo', 'action' => 'getUsersByRoom', '__RID__'],
        ['fullBase' => true]
);
?>
<!-- ★ 親の抽出ロジックが最優先で拾うラッパー -->
<div id="ce-root" <?= $isModal ? 'data-modal="1"' : '' ?>>
    <div class="row">
        <aside class="col-md-3" <?= $isModal ? 'style="display:none;"' : '' ?>>
            <div class="list-group">
                <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
                <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>

                <?php if (date('N', strtotime($date)) == 1): ?>
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
                    <fieldset class="form-section">
                        <legend><?= __("食数予約") ?></legend>

                        <!-- 予約日 -->
                        <div class="row mb-3">
                            <?= $this->Form->label('d_reservation_date', '予約日', ['class' => 'col-sm-3 col-form-label']) ?>
                            <div class="col-sm-9">
                                <?php
                                $weekMap = ['日','月','火','水','木','金','土'];
                                $weekday = $weekMap[(int)date('w', strtotime($date))] ?? '';
                                ?>
                                <div class="d-flex align-items-center">
                                    <?= $this->Form->control('d_reservation_date', [
                                            'type' => 'date',
                                            'label' => false,
                                            'class' => 'form-control',
                                            'disabled' => true,
                                            'value' => $date
                                    ]) ?>
                                    <span class="ms-2">(<?= h($weekday) ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- 予約タイプ -->
                        <div class="mb-3">
                            <label for="c_reservation_type" class="form-label">予約タイプ(個人/集団)</label>
                            <select id="c_reservation_type" name="reservation_type" class="form-select">
                                <option value="" selected disabled>-- 予約タイプを選択 --</option>
                                <option value="1">個人</option>
                                <?php if (in_array((int)$user->get('i_admin'), [1, 3]) || $user->get('i_user_level') == 0): ?>
                                    <option value="2">集団</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- 個人：部屋ごとのチェック -->
                        <div class="mb-3 d-none" id="room-selection-table">
                            <?= $this->Form->label('rooms', '部屋名と食事選択', ['class' => 'form-label']) ?>
                            <div id="room-table-container" class="table-responsive">
                                <table class="table table-bordered mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th scope="col">部屋名</th>
                                        <th scope="col" class="text-center">
                                            <label for="select-all-room-1">朝</label>
                                            <input type="checkbox" id="select-all-room-1">
                                        </th>
                                        <th scope="col" class="text-center">
                                            <label for="select-all-room-2">昼</label>
                                            <input type="checkbox" id="select-all-room-2">
                                        </th>
                                        <th scope="col" class="text-center">
                                            <label for="select-all-room-3">夜</label>
                                            <input type="checkbox" id="select-all-room-3">
                                        </th>
                                        <th scope="col" class="text-center">
                                            <label for="select-all-room-4">弁当</label>
                                            <input type="checkbox" id="select-all-room-4">
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody id="room-checkboxes">
                                    <?php foreach ($rooms as $roomId => $roomName): ?>
                                        <tr>
                                            <td><?= h($roomName) ?></td>
                                            <td class="text-center"><?= $this->Form->checkbox("meals[1][$roomId]", ['value' => 1]) ?></td>
                                            <td class="text-center"><?= $this->Form->checkbox("meals[2][$roomId]", ['value' => 1]) ?></td>
                                            <td class="text-center"><?= $this->Form->checkbox("meals[3][$roomId]", ['value' => 1]) ?></td>
                                            <td class="text-center"><?= $this->Form->checkbox("meals[4][$roomId]", ['value' => 1]) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <?php if (in_array((int)$user->get('i_admin'), [1, 3]) || $user->get('i_user_level') == 0): ?>
                            <!-- 集団：部屋選択 -->
                            <div class="mb-3 d-none" id="room-select-group">
                                <?= $this->Form->label('room-select', '部屋を選択', ['class' => 'form-label']) ?>
                                <?= $this->Form->control('i_id_room', [
                                        'type' => 'select',
                                        'label' => false,
                                        'options' => $rooms,
                                        'empty' => '-- 部屋を選択 --',
                                        'class' => 'form-select',
                                        'id' => 'room-select',
                                        'value' => '', // デフォルト選択なし
                                ]) ?>
                            </div>

                            <!-- 集団：利用者×食事 -->
                            <div class="mb-3 d-none" id="user-selection-table">
                                <?= $this->Form->label('users', '部屋に属する利用者と食事選択', ['class' => 'form-label']) ?>
                                <div id="user-table-container" class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th scope="col">利用者名</th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-user-morning">朝</label>
                                                <input type="checkbox" id="select-all-user-morning">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-user-noon">昼</label>
                                                <input type="checkbox" id="select-all-user-noon">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-user-night">夜</label>
                                                <input type="checkbox" id="select-all-user-night">
                                            </th>
                                            <th scope="col" class="text-center">
                                                <label for="select-all-user-bento">弁当</label>
                                                <input type="checkbox" id="select-all-user-bento">
                                            </th>
                                        </tr>
                                        </thead>
                                        <tbody id="user-checkboxes">
                                        <?php if ($isModal): ?>
                                            <!-- モーダル挿入時は script が実行されない可能性があるため、
                                                 親の ensureAddModalCompat による fetch 前に “読み込み中” 表示を出しておく -->
                                            <tr><td colspan="5" class="text-center text-muted">部屋の情報を読み込み中...</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ======================== Script ======================== -->
                        <script>
                            // PHP から安全にフルURLを受け取ってfetchする（__RID__ を JS で置換）
                            const GET_PERSONAL_URL      = <?= json_encode($URL_GET_PERSONAL, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
                            const GET_USERS_BY_ROOM_TPL = <?= json_encode($URL_GET_USERS_BY_ROOM_TPL, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>; // 例: .../getUsersByRoom/__RID__
                            const QUERY_DATE            = <?= json_encode($date, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

                            // __RID__ を安全に差し替えてクエリを付与
                            function buildGetUsersByRoomUrl(roomId) {
                                let url = GET_USERS_BY_ROOM_TPL || '';
                                if (url.indexOf('__RID__') !== -1) {
                                    url = url.replace('__RID__', encodeURIComponent(roomId));
                                } else {
                                    url = url.replace(/\/$/, '') + '/' + encodeURIComponent(roomId);
                                }
                                url += (url.indexOf('?') === -1 ? '?' : '&') + 'date=' + encodeURIComponent(QUERY_DATE);
                                return url;
                            }

                            function toggleAllRooms(mealType, isChecked) {
                                const checkboxes = document.querySelectorAll(
                                    `input[type="checkbox"][name^="meals[${mealType}]"]`
                                );

                                checkboxes.forEach(cb => {
                                    cb.checked = isChecked;
                                    cb.dispatchEvent(new Event('change'));
                                });

                                const headerCheckbox = document.getElementById('select-all-room-' + mealType);
                                if (headerCheckbox) {
                                    const allChecked = [...checkboxes].every(cb => cb.checked);
                                    headerCheckbox.checked = allChecked;
                                }
                            }

                            // DOMContentLoaded 依存だとモーダル挿入時に動かないため、即時・冪等バインド
                            (function bindRoomHeaderSyncOnce(){
                                if (window.__ADD_BIND_ROOM_SYNC__) return;
                                window.__ADD_BIND_ROOM_SYNC__ = true;

                                const mealTypes = [1, 2, 3, 4];
                                mealTypes.forEach(mealType => {
                                    const checkboxes = document.querySelectorAll(
                                        `input[type="checkbox"][name^="meals[${mealType}]"]`
                                    );
                                    const headerCheckbox = document.getElementById('select-all-room-' + mealType);

                                    // onclick 属性の代わりに addEventListener でバインド
                                    if (headerCheckbox) {
                                        headerCheckbox.addEventListener('change', function() {
                                            toggleAllRooms(mealType, this.checked);
                                        });
                                    }

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

                                    // 初期同期
                                    const allChecked = [...checkboxes].every(c => c.checked);
                                    if (headerCheckbox) headerCheckbox.checked = allChecked;
                                });
                            })();
                        </script>

                        <!-- ======================== Script (Users) ======================== -->
                        <script>
                            /* 既存のユーティリティ関数はそのまま -------------------- */
                            function toggleAllUsers(mealTime, isChecked) {
                                const map = {morning: 1, noon: 2, night: 3, bento: 4};
                                const mealType = map[mealTime];
                                if (!mealType) return;

                                const checkboxes = document.querySelectorAll(
                                    `input[type="checkbox"][name^="users"][name$="[${mealType}]"]`
                                );

                                const headerCheckbox = document.getElementById('select-all-user-' + mealTime);

                                // onclick 属性の代わりに addEventListener でバインド（初回のみ）
                                if (headerCheckbox && !headerCheckbox.dataset._bound) {
                                    headerCheckbox.dataset._bound = '1';
                                    headerCheckbox.addEventListener('change', function() {
                                        toggleAllUsers(mealTime, this.checked);
                                    });
                                }

                                checkboxes.forEach(cb => {
                                    cb.checked = isChecked;

                                    // 昼⇔弁当 排他制御（一括チェック時）
                                    const match = cb.name.match(/^users\[(\d+)]\[(\d+)]$/);
                                    if (match && (mealType === 2 || mealType === 4)) {
                                        const userId = match[1];
                                        const counterpartType = mealType === 2 ? 4 : 2;
                                        const counterpartCb = document.querySelector(
                                            `input[name="users[${userId}][${counterpartType}]"]`
                                        );
                                        if (counterpartCb && isChecked) {
                                            counterpartCb.checked = false;
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

                                const updateHeaderCheckbox = (mealType) => {
                                    const allCbs = document.querySelectorAll(`input[name^="users"][name$="[${mealType}]"]`);
                                    const mealKey = mealType === 2 ? 'noon' : 'bento';
                                    const headerCb = document.getElementById('select-all-user-' + mealKey);
                                    if (!headerCb) return;
                                    const allChecked = [...allCbs].every(c => c.checked);
                                    headerCb.checked = allChecked;
                                };

                                lunchCb.addEventListener('change', () => {
                                    if (lunchCb.checked) {
                                        bentoCb.checked = false;
                                        bentoCb.dispatchEvent(new Event('change'));
                                        updateHeaderCheckbox(4);
                                    }
                                });

                                bentoCb.addEventListener('change', () => {
                                    if (bentoCb.checked) {
                                        lunchCb.checked = false;
                                        lunchCb.dispatchEvent(new Event('change'));
                                        updateHeaderCheckbox(2);
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
                                const url = GET_PERSONAL_URL; // ✅ サーバ生成URLを使用
                                showLoading();
                                fetch(url, { credentials: 'same-origin' })
                                    .then(r => {
                                        if (!r.ok) throw new Error('HTTP ' + r.status);
                                        return r.json();
                                    })
                                    .then(d => {
                                        const res = (d && d.data && d.data.reservation) ? d.data.reservation : {};
                                        document
                                            .querySelectorAll('#room-checkboxes input[type="checkbox"]')
                                            .forEach(cb => {
                                                const m = cb.getAttribute('name').match(/^meals\[(\d+)]/);
                                                if (!m) return;
                                                const type = m[1];
                                                cb.checked = res[type] == true || Number(res[type]) === 1;
                                                cb.dispatchEvent(new Event('change')); // 排他制御のため
                                            });
                                        setupAllRoomPairs();
                                    })
                                    .catch(e => {
                                        console.error('個人予約取得失敗', e);
                                    })
                                    .finally(hideLoading);
                            }

                            function fetchUserData(roomId) {
                                // ✅ ここで __RID__ を確実に置換してからアクセス（%3AroomId のまま飛ばさない）
                                const url = buildGetUsersByRoomUrl(roomId);
                                showLoading();
                                fetch(url, { credentials: 'same-origin' })
                                    .then(r => {
                                        if (!r.ok) throw new Error('HTTP ' + r.status);
                                        return r.json();
                                    })
                                    .then(d => {
                                        const users = d.usersByRoom;
                                        if (!Array.isArray(users)) {
                                            console.error('usersByRoom が配列では無い', users);
                                            return;
                                        }
                                        const tbody = document.getElementById('user-checkboxes');
                                        tbody.innerHTML = '';
                                        const escHtml = (s) => String(s).replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));
                                        users.forEach(u => {
                                            const tr = document.createElement('tr');
                                            const safeName = escHtml(u.name || '');
                                            const safeId   = escHtml(u.id   || '');
                                            tr.innerHTML = `
    <td>${safeName}</td>
    <td class="text-center"><input type="checkbox" name="users[${safeId}][1]" value="1" ${Number(u.morning) === 1 ? 'checked data-existing="1"' : ''}></td>
    <td class="text-center"><input type="checkbox" name="users[${safeId}][2]" value="1" ${Number(u.noon) === 1 ? 'checked data-existing="1"' : ''}></td>
    <td class="text-center"><input type="checkbox" name="users[${safeId}][3]" value="1" ${Number(u.night) === 1 ? 'checked data-existing="1"' : ''}></td>
    <td class="text-center"><input type="checkbox" name="users[${safeId}][4]" value="1" ${Number(u.bento) === 1 ? 'checked data-existing="1"' : ''}></td>
`;
                                            tbody.appendChild(tr);

                                            /* ユーザー行の昼⇄弁当排他 */
                                            setupLunchBentoPair(
                                                tr.querySelector(`input[name="users[${safeId}][2]"]`),
                                                tr.querySelector(`input[name="users[${safeId}][4]"]`)
                                            );
                                        });
                                    })
                                    .catch(e => {
                                        console.error('ユーザ取得失敗', e);
                                        const tbody = document.getElementById('user-checkboxes');
                                        if (tbody) {
                                            tbody.innerHTML = '<tr><td colspan="5" class="text-danger">利用者一覧の取得に失敗しました。</td></tr>';
                                        }
                                    })
                                    .finally(hideLoading);
                            }

                            function showLoading() {
                                const ovl = document.getElementById('loading-overlay');
                                if (ovl) ovl.style.display = 'block';
                                const btn = document.querySelector('#reservation-form button[type="submit"]');
                                if (btn) btn.disabled = true;
                            }
                            function hideLoading() {
                                const ovl = document.getElementById('loading-overlay');
                                if (ovl) ovl.style.display = 'none';
                                const btn = document.querySelector('#reservation-form button[type="submit"]');
                                if (btn) btn.disabled = false;
                            }

                            // --- 初期化（DOM Ready 依存せず即時も呼べるように） ---
                            function __add_init_once() {
                                if (window.__ADD_INIT_DONE__) return;
                                window.__ADD_INIT_DONE__ = true;

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
                                    document.getElementById('select-all-room-2'),
                                    document.getElementById('select-all-room-4')
                                );
                                setupLunchBentoPair(
                                    document.getElementById('select-all-user-noon'),
                                    document.getElementById('select-all-user-bento')
                                );

                                /* 個人予約データを取得して反映（排他処理と連動） */
                                fetchPersonalReservationData();
                            }

                            // DOM がある環境では直ちに初期化（モーダルでも動く）
                            try { __add_init_once(); } catch (e) {}

                        </script>
                        <!-- ====================== /Script ======================== -->

                    </fieldset>

                    <!-- 送信ボタン & ローディング -->
                    <div class="form-text text-muted mb-2">チェックを外して登録すると予約をキャンセルできます。</div>
                    <?= $this->Form->button(__('登録'), ['class' => 'btn btn-primary']) ?>
                    <div id="loading-overlay"
                         role="status"
                         aria-live="polite"
                         aria-label="処理中"
                         style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999; text-align: center;">
                        <div style="position: relative; top: 50%; transform: translateY(-50%);">
                            <div class="spinner-border text-info" aria-hidden="true"></div>
                            <p style="color: white; margin-top: 10px;">処理中です。少々お待ちください...</p>
                        </div>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div><!-- /#ce-root -->

<script>
    var roomsData = <?= json_encode($rooms); ?>;
</script>

<script>
    /**
     * 予約タイプ・部屋選択制御と、昼／弁当チェックボックスの相互排他制御
     * （モーダル関連の仕様・関数は変更しない）
     */
    function initReservationForm() {
        /* ────────── 予約タイプ・部屋選択表示制御 ────────── */
        const typeSelect   = document.getElementById('c_reservation_type');
        const roomTable    = document.getElementById('room-selection-table');
        const roomSelectGp = document.getElementById('room-select-group');  // ★ null 可
        const userTableGp  = document.getElementById('user-selection-table'); // ★ null 可

        const showEl = (el) => { if (el) el.classList.remove('d-none'); };
        const hideEl = (el) => { if (el) el.classList.add('d-none'); };

        const handleTypeChange = () => {
            const val = typeSelect.value;
            if (val === '1') {
                showEl(roomTable);
                hideEl(roomSelectGp);
                hideEl(userTableGp);
                // 個人の既存予約を取得・反映
                if (typeof fetchPersonalReservationData === 'function') {
                    fetchPersonalReservationData();
                }
            } else if (val === '2') {
                hideEl(roomTable);
                showEl(roomSelectGp);
                hideEl(userTableGp);
                // ★ select に既定値が入っているので、親 ensureAddModalCompat が自動 fetch します
            } else {
                hideEl(roomTable);
                hideEl(roomSelectGp);
                hideEl(userTableGp);
            }
        };

        const roomSelect = document.getElementById('room-select');
        const handleRoomChange = () => {
            const roomId = roomSelect.value;
            const tbody = document.getElementById('user-checkboxes');
            if (tbody) tbody.innerHTML = '';
            if (!roomId) {
                hideEl(userTableGp);
                return;
            }
            showEl(userTableGp);
            if (typeof fetchUserData === 'function') {
                fetchUserData(roomId);
            }
        };

        /* ────────── 昼／弁当 相互排他制御（存在すれば） ────────── */
        const lunchCb  = document.getElementById('meal-lunch');             // 「昼」※UIに無くても問題なし
        const bentoCbs = document.querySelectorAll('.meal-bento');         // 複数「弁当」

        const onLunchChange = () => {
            if (lunchCb && lunchCb.checked) {
                bentoCbs.forEach(cb => { cb.checked = false; });
            }
        };

        const onBentoChange = () => {
            if ([...bentoCbs].some(cb => cb.checked)) {
                if (lunchCb) lunchCb.checked = false;
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


    // 利用者ごとの昼⇔弁当排他制御
    function setupUserLunchBentoExclusion() {
        document.querySelectorAll('#user-checkboxes tr').forEach(tr => {
            const lunchCb = tr.querySelector('input[name*="[2]"]');  // 昼
            const bentoCb = tr.querySelector('input[name*="[4]"]');  // 弁当
            if (!lunchCb || !bentoCb) return;

            lunchCb.addEventListener('change', () => {
                if (lunchCb.checked) {
                    bentoCb.checked = false;
                    bentoCb.disabled = true;
                    bentoCb.title = '昼食と弁当は同時に選択できません';
                } else {
                    bentoCb.disabled = false;
                    bentoCb.title = '';
                }
            });

            bentoCb.addEventListener('change', () => {
                if (bentoCb.checked) {
                    lunchCb.checked = false;
                    lunchCb.disabled = true;
                    lunchCb.title = '昼食と弁当は同時に選択できません';
                } else {
                    lunchCb.disabled = false;
                    lunchCb.title = '';
                }
            });

            // 初期状態反映
            if (lunchCb.checked) {
                bentoCb.disabled = true;
                bentoCb.title = '昼食と弁当は同時に選択できません';
            } else if (bentoCb.checked) {
                lunchCb.disabled = true;
                lunchCb.title = '昼食と弁当は同時に選択できません';
            } else {
                lunchCb.disabled = false;
                lunchCb.title = '';
                bentoCb.disabled = false;
                bentoCb.title = '';
            }
        });
    }

    // 利用者一覧取得後に呼び出す
    // 例：fetchUserData(roomId) の then 内
    setupUserLunchBentoExclusion();


    // ページ直開き時の保険（モーダルでは親が主導）
    document.addEventListener('DOMContentLoaded', initReservationForm);
</script>
