<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 */
?>
<div class="row">
    <aside class="column">
        <div class="side-nav">
            <h4 class="heading"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('Edit M User Info'), ['action' => 'edit', $mUserInfo->i_id_user], ['class' => 'side-nav-item']) ?>
            <?= $this->Form->postLink(__('Delete M User Info'), ['action' => 'delete', $mUserInfo->i_id_user], ['confirm' => __('Are you sure you want to delete # {0}?', $mUserInfo->i_id_user), 'class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('List M User Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('New M User Info'), ['action' => 'add'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="mUserInfo view content">
            <h3><?= h($mUserInfo->i_id_user) ?></h3>
            <table>
                <tr>
                    <th><?= __('C Login Account') ?></th>
                    <td><?= h($mUserInfo->c_login_account) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Login Passwd') ?></th>
                    <td><?= h($mUserInfo->c_login_passwd) ?></td>
                </tr>
                <tr>
                    <th><?= __('C  User Name') ?></th>
                    <td><?= h($mUserInfo->c__user_name) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Create User') ?></th>
                    <td><?= h($mUserInfo->c_create_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Update User') ?></th>
                    <td><?= h($mUserInfo->c_update_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Id User') ?></th>
                    <td><?= $this->Number->format($mUserInfo->i_id_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Admin') ?></th>
                    <td><?= $mUserInfo->i_admin === null ? '' : $this->Number->format($mUserInfo->i_admin) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Disp  No') ?></th>
                    <td><?= $mUserInfo->i_disp__no === null ? '' : $this->Number->format($mUserInfo->i_disp__no) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Enable') ?></th>
                    <td><?= $mUserInfo->i_enable === null ? '' : $this->Number->format($mUserInfo->i_enable) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Del Flag') ?></th>
                    <td><?= $mUserInfo->i_del_flag === null ? '' : $this->Number->format($mUserInfo->i_del_flag) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Create') ?></th>
                    <td><?= h($mUserInfo->dt_create) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Update') ?></th>
                    <td><?= h($mUserInfo->dt_update) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
