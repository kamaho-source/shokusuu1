<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 */

$this->Html->css(['bootstrap.min']);
?>
<div class="mUserInfo index content">
    <?= $this->Html->link(__('新しくユーザを追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
    <h3><?= __('ユーザー一覧') ?></h3>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th><?= $this->Paginator->sort('i_id_user',['label'=>'ユーザー識別ID']) ?></th>
                <th><?= $this->Paginator->sort('c__user_name',['label'=>'ユーザ名']) ?></th>
                <th><?= $this->Paginator->sort('i_disp__no',['label'=>'表示順']) ?></th>
                <th class="actions"><?= __('Actions') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mUserInfo as $mUserInfo): ?>
                <tr>
                    <td><?= $this->Number->format($mUserInfo->i_id_user) ?></td>
                    <td><?= h($mUserInfo->c__user_name) ?></td>
                    <td><?= $mUserInfo->i_disp__no === null ? '' : $this->Number->format($mUserInfo->i_disp__no) ?></td>
                    <td class="actions">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $mUserInfo->i_id_user], ['class' => 'btn btn-primary']) ?>
                        <?= $this->Html->link(__('編集'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'btn btn-primary']) ?>
                        <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $mUserInfo->i_id_user], ['confirm' => __('Are you sure you want to delete # {0}?', $mUserInfo->i_id_user), 'class' => 'btn btn-danger']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
