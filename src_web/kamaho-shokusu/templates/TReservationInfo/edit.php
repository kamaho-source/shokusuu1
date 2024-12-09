<div class="row">
    <div class="col-md-9 offset-md-1">
        <h3><?= h($room->c_room_name) ?>の予約編集</h3>
        <p>日付: <?= h($date) ?></p>

        <?= $this->Form->create(null, ['url' => ['action' => 'edit', $room->i_id_room, $date, $mealType]]) ?>
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
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][1]) && $userReservations[$user->m_user_info->i_id_user][1] == 1, // 朝の予約がある場合はチェック
                                    ]
                                ) ?>
                            </td>
                        <?php elseif ($mealType == 2): ?>
                            <td>
                                <?= $this->Form->checkbox(
                                    "users[{$user->m_user_info->i_id_user}][2]",
                                    [
                                        'value' => 1,
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][2]) && $userReservations[$user->m_user_info->i_id_user][2] == 1, // 昼の予約がある場合はチェック
                                    ]
                                ) ?>
                            </td>
                        <?php elseif ($mealType == 3): ?>
                            <td>
                                <?= $this->Form->checkbox(
                                    "users[{$user->m_user_info->i_id_user}][3]",
                                    [
                                        'value' => 1,
                                        'checked' => isset($userReservations[$user->m_user_info->i_id_user][3]) && $userReservations[$user->m_user_info->i_id_user][3] == 1, // 夜の予約がある場合はチェック
                                    ]
                                ) ?>
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

<!-- 必要なスクリプトを読み込み -->
<?php
$this->Html->script('edit.js', ['block' => true]);
?>
