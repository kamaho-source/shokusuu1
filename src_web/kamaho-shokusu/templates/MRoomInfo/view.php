<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 * @var array $users
 * @var object $user
 */
$this->assign('title', __('部屋情報') . ' ' . h($mRoomInfo->c_room_name));
?>
<div class="row">
    <?php
    $sideItems = [];
    if (in_array((int)$user->get('i_admin'), [1, 3])) {
        $sideItems[] = ['tone' => 'edit', 'label' => '部屋情報を編集する', 'url' => ['action' => 'edit', $mRoomInfo->i_id_room], 'primary' => true];
        $sideItems[] = ['tone' => 'add', 'label' => '新しい部屋を追加', 'url' => ['action' => 'add']];
    }
    $sideItems[] = ['tone' => 'back', 'label' => '部屋一覧へ戻る', 'url' => ['action' => 'index']];
    ?>
    <?= $this->element('side_actions', ['items' => $sideItems]) ?>
    <div class="col-md-9">
        <div class="card">
            <h5 class="card-header"><?= __('部屋情報') . ' ' . h($mRoomInfo->c_room_name) ?></h5>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-striped">
                    <tr>
                        <th><?= __('部屋名') ?></th>
                        <td><?= h($mRoomInfo->c_room_name) ?></td>
                    </tr>
                    <tr>
                        <th><?= __('部屋ID') ?></th>
                        <td><?= $this->Number->format($mRoomInfo->i_id_room) ?></td>
                    </tr>
                </table>
                </div>

                <?php if (!empty($users)): ?>
                    <h4><?= __('所属メンバー') ?></h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                            <tr>
                                <th><?= __('ユーザー識別ID') ?></th>
                                <th><?= __('ユーザー名') ?></th>
                                <th><?= __('表示順') ?></th>
                                <th><?= __('ユーザーレベル') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            // i_del_flag が 0 のユーザーのみを抽出
                            // ※ ループ変数にはログインユーザーの $user と別名を使う（シャドーイング防止）
                            $filteredUsers = array_filter($users, function ($member) {
                                return $member->i_del_flag === 0;
                            });

                            // ユーザーを分類
                            $staff = array_filter($filteredUsers, function ($member) {
                                return $member->i_user_level !== null && \App\Domain\ValueObject\UserRole::isStaffLevel((int)$member->i_user_level);
                            });
                            $children = array_filter($filteredUsers, function ($member) {
                                return $member->i_user_level === 1;
                            });
                            $others = array_filter($filteredUsers, function ($member) {
                                return $member->i_user_level !== 1 && !($member->i_user_level !== null && \App\Domain\ValueObject\UserRole::isStaffLevel((int)$member->i_user_level));
                            });

                            $groupedUsers = array_merge($staff, $children, $others);
                            ?>
                            <?php foreach ($groupedUsers as $member): ?>
                                <tr>
                                    <td><?= $this->Number->format($member->i_id_user) ?></td>
                                    <td><?= h($member->c_user_name) ?></td>
                                    <td><?= $member->i_disp_no === null ? '' : $this->Number->format($member->i_disp_no) ?></td>
                                    <td>
                                        <?= h(($member->i_user_level !== null && \App\Domain\ValueObject\UserRole::isStaffLevel((int)$member->i_user_level)) ? '職員' : ($member->i_user_level === 1 ? '児童' : 'その他')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        <?= __('この部屋には現在所属メンバーがいません。') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
