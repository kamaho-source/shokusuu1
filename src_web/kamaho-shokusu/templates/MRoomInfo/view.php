<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 * @var array $users
 */

$this->Html->css(['bootstrap.min']);
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item-heading"><?= __('アクション') ?></h4>
            <?= $this->Html->link(__('部屋情報を編集'), ['action' => 'edit', $mRoomInfo->i_id_room], ['class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Form->postLink(__('部屋情報を削除'), ['action' => 'delete', $mRoomInfo->i_id_room], ['confirm' => __('本当に削除しますか？ # {0}', $mRoomInfo->i_id_room), 'class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Html->link(__('部屋情報一覧'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Html->link(__('新しい部屋情報'), ['action' => 'add'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <h5 class="card-header"><?= __('部屋情報') . ' ' . h($mRoomInfo->i_id_room) ?></h5>
            <div class="card-body">
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

                <?php if (!empty($users)): ?>
                    <h4><?= __('所属メンバー') ?></h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="thead-dark">
                            <tr>
                                <th><?= __('ユーザー識別ID') ?></th>
                                <th><?= __('ユーザー名') ?></th>
                                <th><?= __('表示順') ?></th>
                                <th><?= __('ユーザーレベル') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $staff = array_filter($users, function ($user) {
                                return $user->i_user_level === 0;
                            });
                            $children = array_filter($users, function ($user) {
                                return $user->i_user_level === 1;
                            });
                            $others = array_filter($users, function ($user) {
                                return $user->i_user_level !== 0 && $user->i_user_level !== 1;
                            });
                            $groupedUsers = array_merge($staff, $children, $others);
                            ?>
                            <?php foreach ($groupedUsers as $user): ?>
                                <tr>
                                    <td><?= $this->Number->format($user->i_id_user) ?></td>
                                    <td><?= h($user->c_user_name) ?></td>
                                    <td><?= $user->i_disp_no === null ? '' : $this->Number->format($user->i_disp_no) ?></td>
                                    <td>
                                        <?= h($user->i_user_level === 0 ? '職員' : ($user->i_user_level === 1 ? '児童' : 'その他')) ?>
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
