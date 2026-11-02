<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Infrastructure\Persistence\Entity\MRoomInfo $mRoomInfo
 * @var list<\App\Application\Query\Dto\RoomMemberView> $users
 * @var bool $canReorder
 * @var object $user
 */
$this->assign('title', __('部屋情報') . ' ' . h($mRoomInfo->c_room_name));
?>
<div class="row">
    <aside class="col-md-3">
        <div class="card">
            <div class="card-header"><?= __('アクション') ?></div>
            <div class="list-group list-group-flush">
                <?php if (in_array((int)$user->get('i_admin'), [1, 3])): ?>
                <?= $this->Html->link(__('部屋情報を編集（メンバーの並び替え）'), ['action' => 'edit', $mRoomInfo->i_id_room], ['class' => 'list-group-item list-group-item-action']) ?>
                <?= $this->Html->link(__('新しい部屋情報'), ['action' => 'add'], ['class' => 'list-group-item list-group-item-action']) ?>
                <?php elseif ($canReorder): ?>
                <?= $this->Html->link(__('メンバーの並び替え'), ['action' => 'edit', $mRoomInfo->i_id_room], ['class' => 'list-group-item list-group-item-action']) ?>
                <?php endif; ?>
                <?= $this->Html->link(__('部屋情報一覧'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            </div>
        </div>
    </aside>
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
                            <?php // $users は削除されていない所属メンバーだけ。並びは取得順のまま（区分では分けない）。ループ変数はログインユーザーの $user と別名にする ?>
                            <?php foreach ($users as $member): ?>
                                <tr>
                                    <td><?= $this->Number->format($member->userId) ?></td>
                                    <td><?= h($member->name) ?></td>
                                    <td><?= $member->displayNo === null ? '' : $this->Number->format($member->displayNo) ?></td>
                                    <td>
                                        <?= h(\App\Domain\ValueObject\UserRole::levelLabel($member->level)) ?>
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
