<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Infrastructure\Persistence\Entity\MUserInfo $mUserInfo
 * @var object $user
 * @var list<string> $userRooms
 */
use App\Domain\ValueObject\UserProfileOptions;

$this->assign('title', 'ユーザー情報の表示');
$currentUserId = $user->get('i_id_user');
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?php if ($canEdit ?? ($mUserInfo->i_id_user === $currentUserId || in_array((int)$user->get('i_admin'), [1, 3]))): ?>
            <?= $this->Html->link(__('ユーザ情報を編集する'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
            <?php if (in_array((int)$user->get('i_admin'), [1, 3])): ?>
            <?= $this->Html->link(__('ユーザ一覧を表示する'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= 'ユーザー情報:'.h($mUserInfo->c_user_name) ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-striped">
                    <?php if($mUserInfo->i_id_user === $currentUserId || in_array((int)$user->get('i_admin'), [1, 3])): ?>
                    <tr>
                        <th><?= __('ログインID') ?></th>
                        <td><?= h($mUserInfo->c_login_account) ?></td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th><?= __('ユーザー名') ?></th>
                        <td><?= h($mUserInfo->c_user_name) ?></td>
                    </tr>
                    <?php /* 新規登録と同じ並び（性別・年代・年齢・役職・職員ID・所属部屋） */ ?>
                    <tr>
                        <th><?= __('性別') ?></th>
                        <td><?= h(UserProfileOptions::label(UserProfileOptions::GENDERS, $mUserInfo->i_user_gender)) ?></td>
                    </tr>
                    <tr>
                        <th><?= __('年代') ?></th>
                        <td><?= h(UserProfileOptions::label(UserProfileOptions::AGE_GROUPS, $mUserInfo->i_user_rank)) ?></td>
                    </tr>
                    <tr>
                        <th><?= __('年齢') ?></th>
                        <td><?= $mUserInfo->i_user_age ? h($mUserInfo->i_user_age) . '歳' : __('未設定') ?></td>
                    </tr>
                    <tr>
                        <th><?= __('役職') ?></th>
                        <td><?= h(UserProfileOptions::label(UserProfileOptions::USER_LEVELS, $mUserInfo->i_user_level)) ?></td>
                    </tr>
                    <?php if ($mUserInfo->i_id_staff): ?>
                        <tr>
                            <th><?= __('職員ID') ?></th>
                            <td><?= h($mUserInfo->i_id_staff) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?= __('所属部屋') ?></th>
                        <td>
                            <?php if (empty($userRooms)): ?>
                                <span class="text-muted"><?= __('所属なし') ?></span>
                            <?php else: ?>
                                <?php foreach ($userRooms as $roomName): ?>
                                    <span class="badge bg-primary me-1"><?= h($roomName) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
