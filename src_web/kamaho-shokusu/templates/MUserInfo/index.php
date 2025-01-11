<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 * @var array $userRooms
 */

echo $this->Html->css(['bootstrap.min']);
$this->assign('title', 'ユーザー情報一覧');
?>
<div class="mUserInfo index content">
    <?= $this->Html->link(__('新しくユーザを追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
    <h3><?= __('ユーザー一覧') ?></h3>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th><?= $this->Paginator->sort('i_id_user', ['label' => 'ユーザー識別ID']) ?></th>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => 'ユーザー名']) ?></th>
                <th><?= $this->Paginator->sort('i_disp_no', ['label' => '表示順']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <th class="actions"><?= __('操作') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mUserInfo as $user): ?>
                <tr>
                    <td><?= h($user->i_id_user) ?></td>
                    <td><?= h($user->c_user_name) ?></td>
                    <td><?= $user->i_disp_no !== null ? $this->Number->format($user->i_disp_no) : '' ?></td>
                    <td><?= !empty($userRooms[$user->i_id_user]) ? h(implode(', ', $userRooms[$user->i_id_user])) : '未所属' ?></td>
                    <td class="actions">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $user->i_id_user], ['class' => 'btn btn-primary btn-sm']) ?>
                        <?= $this->Html->link(__('編集'), ['action' => 'edit', $user->i_id_user], ['class' => 'btn btn-warning btn-sm']) ?>
                        <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $user->i_id_user], ['confirm' => __('ユーザー ID {0} を削除してもよろしいですか？', $user->i_id_user), 'class' => 'btn btn-danger btn-sm']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination" style="align-content: center">
            <?= $this->Paginator->first('<< ' . __('最初')) ?>&nbsp;
            <?= $this->Paginator->prev('< ' . __('前へ')) ?>&nbsp;
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('次へ') . ' >') ?>&nbsp;
            <?= $this->Paginator->last(__('最後') . ' >>') ?>&nbsp;
        </ul>
        <p><?= $this->Paginator->counter(__('ページ {{page}} / {{pages}}, 全 {{count}} 件中の {{current}} 件を表示')) ?></p>
    </div>
</div>
