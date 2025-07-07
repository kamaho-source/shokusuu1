<?php
$loginUser = $this->request->getAttribute('identity'); // 認証済みユーザー情報
?>

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
                    <?php elseif ($mealType == 4): ?> <!-- 弁当のみ -->
                        <th>弁当</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $rowUser): ?>
                    <tr>
                        <td><?= h($rowUser->m_user_info->c_user_name) ?></td>
                        <?php
                        // 管理者または一般管理者の場合は全員編集可能
                        $isAdmin = ($loginUser->get('i_admin') === 1 || $loginUser->get('i_user_level') == 0);
                        // それ以外の場合は自分自身だけ編集可能
                        $isSelf = ($loginUser->get('i_id_user') == $rowUser->m_user_info->i_id_user);
                        $allowEdit = ($isAdmin || $isSelf);
                        ?>
                        <?php if ($allowEdit): ?>
                            <?php if ($mealType == 1): ?>
                                <td>
                                    <?= $this->Form->checkbox(
                                        "users[{$rowUser->m_user_info->i_id_user}][1]",
                                        [
                                            'value' => 1,
                                            'checked' => isset($userReservations[$rowUser->m_user_info->i_id_user][1]) && $userReservations[$rowUser->m_user_info->i_id_user][1]['eat_flag'] == 1,
                                            'class' => 'meal-checkbox',
                                            'data-reservation-type' => 1,
                                            'data-user-id' => $rowUser->m_user_info->i_id_user,
                                            'data-room-id' => $room->i_id_room ?? '0',
                                            'data-existing-room-id' => $userReservations[$rowUser->m_user_info->i_id_user][1]['room_id'] ?? null,
                                            'data-eat-flag' => $userReservations[$rowUser->m_user_info->i_id_user][1]['eat_flag'] ?? null
                                        ]
                                    ) ?>
                                    <span class="eat-flag-indicator">
                                        <?php if (isset($userReservations[$rowUser->m_user_info->i_id_user][1])): ?>
                                            <?php if ($userReservations[$rowUser->m_user_info->i_id_user][1]['eat_flag'] == 0): ?>
                                                <i class="text-warning">
                                                    他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][1]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php elseif ($userReservations[$rowUser->m_user_info->i_id_user][1]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                                <i class="text-danger">
                                                    他の部屋で登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][1]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            <?php elseif ($mealType == 2): ?>
                                <td>
                                    <?= $this->Form->checkbox(
                                        "users[{$rowUser->m_user_info->i_id_user}][2]",
                                        [
                                            'value' => 1,
                                            'checked' => isset($userReservations[$rowUser->m_user_info->i_id_user][2]) && $userReservations[$rowUser->m_user_info->i_id_user][2]['eat_flag'] == 1,
                                            'class' => 'meal-checkbox',
                                            'data-reservation-type' => 2,
                                            'data-user-id' => $rowUser->m_user_info->i_id_user,
                                            'data-room-id' => $room->i_id_room ?? '0',
                                            'data-existing-room-id' => $userReservations[$rowUser->m_user_info->i_id_user][2]['room_id'] ?? null,
                                            'data-eat-flag' => $userReservations[$rowUser->m_user_info->i_id_user][2]['eat_flag'] ?? null
                                        ]
                                    ) ?>
                                    <span class="eat-flag-indicator">
                                        <?php if (isset($userReservations[$rowUser->m_user_info->i_id_user][2])): ?>
                                            <?php if ($userReservations[$rowUser->m_user_info->i_id_user][2]['eat_flag'] == 0): ?>
                                                <i class="text-warning">
                                                    他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][2]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php elseif ($userReservations[$rowUser->m_user_info->i_id_user][2]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                                <i class="text-danger">
                                                    他の部屋で登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][2]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            <?php elseif ($mealType == 3): ?>
                                <td>
                                    <?= $this->Form->checkbox(
                                        "users[{$rowUser->m_user_info->i_id_user}][3]",
                                        [
                                            'value' => 1,
                                            'checked' => isset($userReservations[$rowUser->m_user_info->i_id_user][3]) && $userReservations[$rowUser->m_user_info->i_id_user][3]['eat_flag'] == 1,
                                            'class' => 'meal-checkbox',
                                            'data-reservation-type' => 3,
                                            'data-user-id' => $rowUser->m_user_info->i_id_user,
                                            'data-room-id' => $room->i_id_room ?? '0',
                                            'data-existing-room-id' => $userReservations[$rowUser->m_user_info->i_id_user][3]['room_id'] ?? null,
                                            'data-eat-flag' => $userReservations[$rowUser->m_user_info->i_id_user][3]['eat_flag'] ?? null
                                        ]
                                    ) ?>
                                    <span class="eat-flag-indicator">
                                        <?php if (isset($userReservations[$rowUser->m_user_info->i_id_user][3])): ?>
                                            <?php if ($userReservations[$rowUser->m_user_info->i_id_user][3]['eat_flag'] == 0): ?>
                                                <i class="text-warning">
                                                    他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][3]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php elseif ($userReservations[$rowUser->m_user_info->i_id_user][3]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                                <i class="text-danger">
                                                    他の部屋で登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][3]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            <?php elseif ($mealType == 4): ?>
                                <td>
                                    <?= $this->Form->checkbox(
                                        "users[{$rowUser->m_user_info->i_id_user}][4]",
                                        [
                                            'value' => 1,
                                            'checked' => isset($userReservations[$rowUser->m_user_info->i_id_user][4]) && $userReservations[$rowUser->m_user_info->i_id_user][4]['eat_flag'] == 1,
                                            'class' => 'meal-checkbox',
                                            'data-reservation-type' => 4,
                                            'data-user-id' => $rowUser->m_user_info->i_id_user,
                                            'data-room-id' => $room->i_id_room ?? '0',
                                            'data-existing-room-id' => $userReservations[$rowUser->m_user_info->i_id_user][4]['room_id'] ?? null,
                                            'data-eat-flag' => $userReservations[$rowUser->m_user_info->i_id_user][4]['eat_flag'] ?? null
                                        ]
                                    ) ?>
                                    <span class="eat-flag-indicator">
                                        <?php if (isset($userReservations[$rowUser->m_user_info->i_id_user][4])): ?>
                                            <?php if ($userReservations[$rowUser->m_user_info->i_id_user][4]['eat_flag'] == 0): ?>
                                                <i class="text-warning">
                                                    他の部屋で食べないとして登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][4]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php elseif ($userReservations[$rowUser->m_user_info->i_id_user][4]['room_id'] != ($room->i_id_room ?? '0')): ?>
                                                <i class="text-danger">
                                                    他の部屋で登録されています (部屋名: <?= h($userReservations[$rowUser->m_user_info->i_id_user][4]['room_name'] ?? '不明な部屋') ?>)。
                                                </i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php // 編集不可の場合はチェックボックスは表示せず、「-」で表示 ?>
                            <td>-</td>
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