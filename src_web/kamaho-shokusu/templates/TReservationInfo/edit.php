<div class="row">
    <div class="col-md-9 offset-md-1">
        <h3><?= h($room->c_room_name ?? '未設定の部屋') ?>の予約編集</h3>
        <p>日付: <?= h($date) ?></p>

        <?= $this->Form->create(null, ['url' => ['action' => 'edit', $room->i_id_room ?? '0', $date, $mealType]]) ?>
        <fieldset>
            <legend><?= __('利用者と予約情報') ?></legend>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>利用者名</th>
                    <?php if ($mealType == 1): ?> <!-- 朝のみ -->
                        <th>朝</th>
                    <?php elseif ($mealType == 2): ?> <!-- 昼のみ -->
                        <th>昼</th>
                    <?php elseif ($mealType == 3): ?> <!-- 夜のみ -->
                        <th>夜</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= h($user->m_user_info->c_user_name) ?></td>
                        <?php if ($mealType == 1): ?>
                            <td>
                                <?= $this->Form->checkbox(
                                    "users[{$user->m_user_info->i_id_user}][1]",
                                    [
                                        'value' => 1,
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][1]) && $userReservations[$user->m_user_info->i_id_user][1]['eat_flag'] == 1,
                                        'class' => 'meal-checkbox',
                                        'data-reservation-type' => 1,
                                        'data-user-id' => $user->m_user_info->i_id_user,
                                        'data-room-id' => $room->i_id_room ?? '0',
                                        'data-existing-room-id' => $userReservations[$user->m_user_info->i_id_user][1]['room_id'] ?? null,
                                        'data-eat-flag' => $userReservations[$user->m_user_info->i_id_user][1]['eat_flag'] ?? null
                                    ]
                                ) ?>
                                <span class="eat-flag-indicator">
                                    <?php if (isset($userReservations[$user->m_user_info->i_id_user][1])): ?>
                                        <?php if ($userReservations[$user->m_user_info->i_id_user][1]['eat_flag'] == 0): ?>
                                            <i class="text-warning">
                                                他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][1]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php elseif ($userReservations[$user->m_user_info->i_id_user][1]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                            <i class="text-danger">
                                                他の部屋で登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][1]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                        <?php elseif ($mealType == 2): ?>
                            <td>
                                <?= $this->Form->checkbox(
                                    "users[{$user->m_user_info->i_id_user}][2]",
                                    [
                                        'value' => 1,
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][2]) && $userReservations[$user->m_user_info->i_id_user][2]['eat_flag'] == 1,
                                        'class' => 'meal-checkbox',
                                        'data-reservation-type' => 2,
                                        'data-user-id' => $user->m_user_info->i_id_user,
                                        'data-room-id' => $room->i_id_room ?? '0',
                                        'data-existing-room-id' => $userReservations[$user->m_user_info->i_id_user][2]['room_id'] ?? null,
                                        'data-eat-flag' => $userReservations[$user->m_user_info->i_id_user][2]['eat_flag'] ?? null
                                    ]
                                ) ?>
                                <span class="eat-flag-indicator">
                                    <?php if (isset($userReservations[$user->m_user_info->i_id_user][2])): ?>
                                        <?php if ($userReservations[$user->m_user_info->i_id_user][2]['eat_flag'] == 0): ?>
                                            <i class="text-warning">
                                                他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][2]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php elseif ($userReservations[$user->m_user_info->i_id_user][2]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                            <i class="text-danger">
                                                他の部屋で登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][2]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                        <?php elseif ($mealType == 3): ?>
                            <td>
                                <?= $this->Form->checkbox(
                                    "users[{$user->m_user_info->i_id_user}][3]",
                                    [
                                        'value' => 1,
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][3]) && $userReservations[$user->m_user_info->i_id_user][3]['eat_flag'] == 1,
                                        'class' => 'meal-checkbox',
                                        'data-reservation-type' => 3,
                                        'data-user-id' => $user->m_user_info->i_id_user,
                                        'data-room-id' => $room->i_id_room ?? '0',
                                        'data-existing-room-id' => $userReservations[$user->m_user_info->i_id_user][3]['room_id'] ?? null,
                                        'data-eat-flag' => $userReservations[$user->m_user_info->i_id_user][3]['eat_flag'] ?? null
                                    ]
                                ) ?>
                                <span class="eat-flag-indicator">
                                    <?php if (isset($userReservations[$user->m_user_info->i_id_user][3])): ?>
                                        <?php if ($userReservations[$user->m_user_info->i_id_user][3]['eat_flag'] == 0): ?>
                                            <i class="text-warning">
                                                他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][3]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php elseif ($userReservations[$user->m_user_info->i_id_user][3]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                            <i class="text-danger">
                                                他の部屋で登録されています (部屋名: <?= h($userReservations[$user->m_user_info->i_id_user][3]['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>
        <?= $this->Form->button(__('保存'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const checkboxes = document.querySelectorAll('.meal-checkbox');

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('click', function (e) {
                const existingRoomId = this.dataset.existingRoomId;
                const currentRoomId = this.dataset.roomId;
                const eatFlag = this.dataset.eatFlag;

                // 他の部屋への登録チェック
                if (existingRoomId && existingRoomId !== currentRoomId && eatFlag !== '0') {
                    e.preventDefault(); // チェックの動作をキャンセル
                    alert("この利用者は別の部屋で予約されています。");
                }
            });
        });
    });
</script>
