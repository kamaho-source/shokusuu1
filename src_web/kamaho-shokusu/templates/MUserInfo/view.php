<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */

$this->assign('title', 'ユーザー情報の表示');
$currentUserId = $user->get('i_id_user');
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?php if ($mUserInfo->i_id_user === $currentUserId || $user->get('i_admin') == 1 ): ?>
            <?= $this->Html->link(__('ユーザ情報を編集する'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'list-group-item list-group-item-action']) ?>
            <?php endif; ?>
            <?= $this->Html->link(__('ユーザ一覧を表示する'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= 'ユーザー情報:'.h($mUserInfo->c_user_name) ?></h3>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <?php if($mUserInfo->i_id_user === $currentUserId || $user->get('i_admin') == 1): ?>
                    <tr>
                        <th><?= __('ログインID') ?></th>
                        <td><?= h($mUserInfo->c_login_account) ?></td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th><?= __('ユーザー名') ?></th>
                        <td><?= h($mUserInfo->c_user_name) ?></td>
                    </tr>
                    <?php if ($mUserInfo->i_id_staff): ?>
                        <tr>
                            <th><?= __('職員ID') ?></th>
                            <td><?= h($mUserInfo->i_id_staff) ?></td>
                        </tr>
                    <?php endif; ?>


                </table>
            </div>
        </div>
    </div>
</div>
