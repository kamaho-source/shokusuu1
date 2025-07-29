<?php
/**
 * 直前編集ビュー（18:00 まで有効）
 *
 * @var \Cake\Datasource\EntityInterface $room
 * @var string                           $date       対象日 (YYYY-mm-dd)
 * @var int                              $mealType   1:朝 2:昼 3:夜 4:弁当
 * @var \Cake\Collection\Collection      $users      編集対象利用者一覧
 * @var array                            $userReservations 既存予約情報
 */

$loginUser = $this->request->getAttribute('identity');   // ログインユーザー
?>
<div class="row">
    <div class="col-md-9 offset-md-1">
        <h3><?= h($room->c_room_name ?? '未設定の部屋') ?> の直前予約編集</h3>
        <p>日付: <?= h($date) ?></p>

        <?= $this->Form->create(
            null,
            ['url' => ['action' => 'changeEdit', $room->i_id_room ?? '0', $date, $mealType]]
        ) ?>
        <fieldset>
            <legend><?= __('利用者と予約情報') ?></legend>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>利用者名</th>
                    <?php switch ($mealType) {
                        case 1: echo '<th>朝</th>'; break;
                        case 2: echo '<th>昼</th>'; break;
                        case 3: echo '<th>夜</th>'; break;
                        case 4: echo '<th>弁当</th>'; break;
                    } ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $rowUser): ?>
                    <?php
                    /* ──────────────────────────────────────
                     * 権限制御
                     *   ‐ 管理者 / 一般管理者は全員編集可
                     *   ‐ それ以外は自身のみ編集可
                     * ────────────────────────────────────── */
                    $isAdmin   = ($loginUser->get('i_admin') === 1 || $loginUser->get('i_user_level') == 0);
                    $isSelf    = ($loginUser->get('i_id_user') == $rowUser->m_user_info->i_id_user);
                    $allowEdit = ($isAdmin || $isSelf);

                    /* 対象ユーザー予約情報を取得 */
                    $reservation = $userReservations[$rowUser->m_user_info->i_id_user][$mealType] ?? null;
                    $checked     = $reservation && $reservation['eat_flag'] == 1;
                    $existsRoom  = $reservation['room_id']  ?? null;
                    $existsFlag  = $reservation['eat_flag'] ?? null;

                    /* 職員（i_user_level = 0）で eat_flag=1 の場合は「食べない」へ変更禁止 */
                    $isStaffTarget      = ($rowUser->m_user_info->i_user_level === 0);
                    $disallowUncheck    = ($isStaffTarget && $checked); // ＝食べている職員

                    /* チェックボックス共通属性 */
                    $checkboxOptions = [
                        'value'               => 1,
                        'checked'             => $checked,
                        'class'               => 'meal-checkbox',
                        'data-reservation-type' => $mealType,
                        'data-user-id'        => $rowUser->m_user_info->i_id_user,
                        'data-room-id'        => $room->i_id_room ?? '0',
                        'data-existing-room-id' => $existsRoom,
                        'data-eat-flag'       => $existsFlag,
                    ];

                    /* 権限制御 & 職員変更不可対応 */
                    if (!$allowEdit || $disallowUncheck) {
                        $checkboxOptions['disabled'] = 'disabled';
                    }
                    ?>
                    <tr>
                        <td><?= h($rowUser->m_user_info->c_user_name) ?></td>
                        <td>
                            <?php if ($allowEdit): ?>
                                <?= $this->Form->checkbox(
                                    "users[{$rowUser->m_user_info->i_id_user}][{$mealType}]",
                                    $checkboxOptions
                                ) ?>
                                <?php if ($disallowUncheck): ?>
                                    <span class="text-muted">
                                        （職員のため「食べない」へ変更不可）
                                    </span>
                                <?php endif; ?>

                                <?php /* 部屋衝突や食べないフラグのインジケータ */ ?>
                                <span class="eat-flag-indicator">
                                    <?php if ($reservation): ?>
                                        <?php if ($existsFlag == 0): ?>
                                            <i class="text-warning">
                                                他の部屋で食べないとして登録されています
                                                (部屋名: <?= h($reservation['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php elseif ($existsRoom != ($room->i_id_room ?? '0')): ?>
                                            <i class="text-danger">
                                                他の部屋で登録されています
                                                (部屋名: <?= h($reservation['room_name'] ?? '不明な部屋') ?>)。
                                            </i>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                -   <?php /* 編集不可利用者 */ ?>
                            <?php endif; ?>
                        </td>
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
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.meal-checkbox').forEach(cb => {
            cb.addEventListener('click', function (e) {
                /* 「別部屋予約」チェック */
                const existingRoomId = this.dataset.existingRoomId;
                const currentRoomId  = this.dataset.roomId;
                const eatFlag        = this.dataset.eatFlag;

                if (existingRoomId && existingRoomId !== currentRoomId && eatFlag !== '0') {
                    e.preventDefault();
                    alert("この利用者は別の部屋で予約されています。");
                }
            });
        });
    });
</script>