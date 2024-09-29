<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('ユーザ情報を編集する'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Html->link(__('ユーザ一覧を表示する'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= h($mUserInfo->i_id_user) ?></h3>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <tr>
                        <th><?= __('C Login Account') ?></th>
                        <td><?= h($mUserInfo->c_login_account) ?></td>
                    </tr>

                    <tr>
                        <th><?= __('C User Name') ?></th>
                        <td><?= h($mUserInfo->c_user_name) ?></td>
                    </tr>

                </table>
            </div>
        </div>
    </div>
</div>
