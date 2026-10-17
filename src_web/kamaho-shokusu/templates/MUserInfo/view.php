<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var object $user
 * @var array<string> $userRooms
 */

$this->assign('title', 'ユーザー情報の表示');
$currentUserId = $user->get('i_id_user');
$isAdmin = in_array((int)$user->get('i_admin'), [1, 3]);
$canEdit = $mUserInfo->i_id_user === $currentUserId || $isAdmin;
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('操作') ?></h4>
            <?php if ($canEdit): ?>
            <?= $this->Html->link(__('ユーザ情報を編集する'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <?= $this->Html->link(__('ユーザ一覧を表示する'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0"><?= __('ユーザー情報') ?>：<?= h($mUserInfo->c_user_name) ?></h4>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <?php if ($canEdit): ?>
                    <dt class="col-sm-3 text-muted fw-normal"><?= __('ログインID') ?></dt>
                    <dd class="col-sm-9 fw-semibold"><?= h($mUserInfo->c_login_account) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-3 text-muted fw-normal"><?= __('ユーザ名') ?></dt>
                    <dd class="col-sm-9 fw-semibold"><?= h($mUserInfo->c_user_name) ?></dd>

                    <?php if ($mUserInfo->i_id_staff): ?>
                    <dt class="col-sm-3 text-muted fw-normal"><?= __('職員ID') ?></dt>
                    <dd class="col-sm-9 fw-semibold"><?= h($mUserInfo->i_id_staff) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-3 text-muted fw-normal"><?= __('所属部屋') ?></dt>
                    <dd class="col-sm-9 mb-0">
                        <?php if ($userRooms === []): ?>
                            <span class="text-muted"><?= __('所属部屋はありません') ?></span>
                        <?php else: ?>
                            <?php foreach ($userRooms as $roomName): ?>
                                <span class="badge rounded-pill text-bg-light border fw-normal fs-6 text-wrap text-break text-start me-1 mb-1"><?= h($roomName) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>
