<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 * @var array $userRooms
 * @var \App\Model\Entity\User $user
 */

$isAdmin = $user->get('i_admin') === 1;
$currentUserId = $user->get('i_id_user');

echo $this->Html->css(['bootstrap.min']);
$this->assign('title', 'ユーザー情報一覧');
$csrfToken = $this->request->getAttribute('csrfToken');
$this->Html->css('bootstrap-icons.css', ['block' => true]);
$this->Html->css('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')

?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">

<div class="mUserInfo index content">
    <?php if ($isAdmin || $user->get('i_user_level') === 0): ?>
        <?= $this->Html->link(__('新しくユーザを追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
        <?= $this->Html->link(__('一括ユーザー登録'), ['action' => 'importForm'], ['class' => 'btn btn-primary float-right mb-3 mr-2']) ?>
    <?php endif; ?>

    <h3><?= __('ユーザー一覧') ?></h3>

    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th><?= $this->Paginator->sort('i_id_user', ['label' => 'ユーザー識別ID']) ?></th>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => 'ユーザー名']) ?></th>
                <th><?= $this->Paginator->sort('i_disp_no', ['label' => '表示順']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <?php if ($isAdmin): ?>
                    <th><?= __('管理者権限') ?></th>
                <?php endif; ?>
                <th class="actions"><?= __('操作') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mUserInfo as $userInfo): ?>
                <tr>
                    <td><?= h($userInfo->i_id_user) ?></td>
                    <td><?= h($userInfo->c_user_name) ?></td>
                    <td><?= $userInfo->i_disp_no !== null ? $this->Number->format($userInfo->i_disp_no) : '' ?></td>
                    <td><?= !empty($userRooms[$userInfo->i_id_user]) ? h(implode(', ', $userRooms[$userInfo->i_id_user])) : '未所属' ?></td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <?= $this->Form->checkbox('i_admin', [
                                    'checked' => $userInfo->i_admin === 1,
                                    'value' => $userInfo->i_admin,
                                    'data-user-id' => $userInfo->i_id_user,
                                    'data-user-name' => h($userInfo->c_user_name),
                                    'class' => 'admin-checkbox'
                            ]) ?>
                        </td>
                    <?php endif; ?>
                    <td class="actions">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $userInfo->i_id_user], ['class' => 'btn btn-primary btn-sm']) ?>
                        <?php if ($isAdmin || $userInfo->i_id_user === $currentUserId): ?>
                            <?= $this->Html->link(__('編集'), ['action' => 'edit', $userInfo->i_id_user], ['class' => 'btn btn-warning btn-sm']) ?>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $userInfo->i_id_user], [
                                    'confirm' => __(' {0} を削除してもよろしいですか？', $userInfo->c_user_name),
                                    'class' => 'btn btn-danger btn-sm'
                            ]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ページネーション（« 1 2 3 » 表示） -->
    <nav aria-label="Page navigation example">
        <ul class="pagination justify-content-center">
            <?= $this->Paginator->prev(
                    '<span aria-hidden="true">«</span>',
                    [
                            'escape' => false,           // ← spanをそのまま出力
                            'tag' => 'li',
                            'class' => 'page-item',
                            'linkAttributes' => [
                                    'class' => 'page-link',
                                    'aria-label' => 'Previous'
                            ]
                    ],
                    null,
                    [
                            'escape' => false,
                            'tag' => 'li',
                            'class' => 'page-item disabled',
                            'linkAttributes' => [
                                    'class' => 'page-link',
                                    'aria-label' => 'Previous',
                                    'tabindex' => '-1',
                                    'aria-disabled' => 'true'
                            ]
                    ]
            ) ?>

            <?= $this->Paginator->numbers([
                    'tag' => 'li',
                    'class' => 'page-item',
                    'currentTag' => 'li',
                    'currentClass' => 'page-item active',
                    'linkAttributes' => ['class' => 'page-link'],
                    'escape' => false
            ]) ?>

            <?= $this->Paginator->next(
                    '<span aria-hidden="true">»</span>',
                    [
                            'escape' => false,
                            'tag' => 'li',
                            'class' => 'page-item',
                            'linkAttributes' => [
                                    'class' => 'page-link',
                                    'aria-label' => 'Next'
                            ]
                    ],
                    null,
                    [
                            'escape' => false,
                            'tag' => 'li',
                            'class' => 'page-item disabled',
                            'linkAttributes' => [
                                    'class' => 'page-link',
                                    'aria-label' => 'Next',
                                    'tabindex' => '-1',
                                    'aria-disabled' => 'true'
                            ]
                    ]
            ) ?>
        </ul>
    </nav>

    <p class="text-muted text-center">
        <?= $this->Paginator->counter('ページ {{page}}/{{pages}} (全{{count}}件中 {{current}}件を表示)') ?>
    </p>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const adminCheckboxes = document.querySelectorAll('.admin-checkbox');
        const csrfToken =
            document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ||
            document.querySelector('input[name="_csrfToken"]')?.value ||
            '';

        function handleAdminCheckboxChange(event) {
            const target = event.target;
            const userId = target.getAttribute('data-user-id');
            const userName = target.getAttribute('data-user-name');
            const isAdmin = target.checked ? 1 : 0;

            const confirmMessage = isAdmin
                ? `${userName}に管理者権限を付与しますか？`
                : `${userName}から管理者権限を削除しますか？`;

            if (!confirm(confirmMessage)) {
                target.checked = !target.checked;
                return;
            }

            fetch('/kamaho-shokusu/MUserInfo/update-admin-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ i_id_user: userId, i_admin: isAdmin })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('管理者権限が更新されました。');
                    } else {
                        alert(data.message || '管理者権限の更新に失敗しました。再試行してください。');
                        target.checked = !target.checked;
                    }
                })
                .catch(() => {
                    alert('エラーが発生しました。再試行してください。');
                    target.checked = !target.checked;
                });
        }

        adminCheckboxes.forEach(cb => cb.addEventListener('change', handleAdminCheckboxChange));
    });
</script>
