<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 * @var array $users
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
                <?= $this->Html->link(__('部屋情報を編集'), ['action' => 'edit', $mRoomInfo->i_id_room], ['class' => 'list-group-item list-group-item-action']) ?>
                <?= $this->Html->link(__('新しい部屋情報'), ['action' => 'add'], ['class' => 'list-group-item list-group-item-action']) ?>
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
                    <?php
                    // 並びは RoomMemberOrderService::orderMembers（i_user_level → 表示順 → 名前）。並び替えは同じ i_user_level の中で効く
                    // ※ ループ変数にはログインユーザーの $user と別名を使う（シャドーイング防止）
                    $groups = [];
                    foreach ($users as $member) {
                        $groups[(int)($member->i_user_level ?? -1)][] = $member;
                    }
                    $groupLabel = static fn(int $level): string => match (true) {
                        $level === 0 => '職員',
                        $level === 7 => '職員（区分7）',
                        $level === 1 => '児童',
                        default => 'その他',
                    };
                    ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <h4 class="mb-0 me-auto"><?= __('所属メンバー') ?></h4>
                        <?php if ($canReorder): ?>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="member-order-drag">
                                <label class="form-check-label" for="member-order-drag"><?= __('ドラッグで並び替え') ?></label>
                            </div>
                            <span class="badge text-bg-warning" id="member-order-dirty" hidden></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="member-order-undo" disabled><?= __('元に戻す') ?></button>
                            <button type="button" class="btn btn-primary btn-sm" id="member-order-save" disabled
                                    data-url="<?= h($this->Url->build(['action' => 'reorderMembers', $mRoomInfo->i_id_room])) ?>"><?= __('保存') ?></button>
                        <?php endif; ?>
                    </div>
                    <?php if ($canReorder): ?>
                        <p class="small text-muted mb-2"><?= __('「順番」に番号を入れて Enter で移動します。並び替えは同じ区分（職員・児童など）の中だけです。') ?></p>
                        <div class="alert d-none" id="member-order-message" role="status"></div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle" id="member-order-table">
                            <thead class="table-dark">
                            <tr>
                                <th style="width:6.5rem"><?= __('順番') ?></th>
                                <th><?= __('ユーザー名') ?></th>
                                <th><?= __('年齢') ?></th>
                                <th><?= __('ユーザー識別ID') ?></th>
                            </tr>
                            </thead>
                            <?php foreach ($groups as $level => $members): ?>
                            <tbody data-level="<?= (int)$level ?>">
                                <tr class="table-light">
                                    <th colspan="4"><div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="me-auto"><?= h($groupLabel((int)$level)) ?>（<?= count($members) ?>人）</span>
                                        <?php if ($canReorder && $level === 1 && count($members) > 1): ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-age-sort="desc"><?= __('年上から') ?></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-age-sort="asc"><?= __('年下から') ?></button>
                                        <?php endif; ?>
                                    </div></th>
                                </tr>
                                <?php foreach ($members as $i => $member): ?>
                                <tr data-user-id="<?= (int)$member->i_id_user ?>" data-age="<?= $member->i_user_age === null ? '' : (int)$member->i_user_age ?>">
                                    <td>
                                        <?php if ($canReorder): ?>
                                            <span class="member-order-grip text-muted me-1" aria-hidden="true" hidden>⋮⋮</span>
                                            <input type="number" class="form-control form-control-sm d-inline-block member-order-input" style="width:4.2rem"
                                                   min="1" max="<?= count($members) ?>" value="<?= $i + 1 ?>"
                                                   aria-label="<?= h($member->c_user_name) ?> の順番">
                                        <?php else: ?>
                                            <?= $i + 1 ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($member->c_user_name) ?></td>
                                    <td><?= $member->i_user_age === null ? '' : (int)$member->i_user_age . '歳' ?></td>
                                    <td><?= $this->Number->format($member->i_id_user) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php if ($canReorder): ?>
                        <?= $this->Html->script('room-member-order.js', ['defer' => true]) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        <?= __('この部屋には現在所属メンバーがいません。') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
