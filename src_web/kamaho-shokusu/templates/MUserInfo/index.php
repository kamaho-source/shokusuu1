<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 * @var array $userRooms
 */

// 管理者権限の確認 (例: ログインユーザー情報から取得)
$isAdmin = $user->get('i_admin') === 1;
// 現在ログインしているユーザーのID
$currentUserId = $user->get('i_id_user');

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
                <?php if ($isAdmin): ?>
                    <th><?= __('管理者権限') ?></th>
                <?php endif; ?>
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
                    <?php if ($isAdmin): ?>
                        <td>
                            <?= $this->Form->checkbox('i_admin', [
                                'checked' => $user->i_admin === 1,
                                'value' => $user->i_admin,
                                'data-user-id' => $user->i_id_user,
                                'data-user-name' => h($user->c_user_name), // ユーザー名属性を追加
                                'class' => 'admin-checkbox'
                            ]) ?>
                        </td>
                    <?php endif; ?>
                    <td class="actions">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $user->i_id_user], ['class' => 'btn btn-primary btn-sm']) ?>
                        <?php if ($isAdmin || $user->i_id_user === $currentUserId): ?>
                            <?= $this->Html->link(__('編集'), ['action' => 'edit', $user->i_id_user], ['class' => 'btn btn-warning btn-sm']) ?>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $user->i_id_user], ['confirm' => __('ユーザー ID {0} を削除してもよろしいですか？', $user->i_id_user), 'class' => 'btn btn-danger btn-sm']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination justify-content-center">
            <?= $this->Paginator->first('<< 最初', ['class' => 'page-item']) ?>
            <?= $this->Paginator->prev('< 前', ['class' => 'page-item']) ?>
            <?= $this->Paginator->numbers(['class' => 'page-item']) ?>
            <?= $this->Paginator->next('次 >', ['class' => 'page-item']) ?>
            <?= $this->Paginator->last('最後 >>', ['class' => 'page-item']) ?>
        </ul>
        <p class="text-muted text-center">
            <?= $this->Paginator->counter('ページ {{page}}/{{pages}} (全{{count}}件中 {{current}}件を表示)') ?>
        </p>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const adminCheckboxes = document.querySelectorAll('.admin-checkbox');

        adminCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (event) => {
                const userId = event.target.getAttribute('data-user-id'); // ユーザーID取得
                const userName = event.target.getAttribute('data-user-name'); // ユーザー名取得
                const isAdmin = event.target.checked ? 1 : 0;

                // 確認ダイアログを生成
                const confirmMessage = isAdmin
                    ? `${userName}に管理者権限を付与しますか？`
                    : `${userName}から管理者権限を削除しますか？`;

                // ユーザーによる確認
                if (!confirm(confirmMessage)) {
                    // キャンセルの場合、チェック状態を元に戻す
                    event.target.checked = !event.target.checked;
                    return;
                }

                // サーバーに非同期リクエスト送信
                fetch('/kamaho-shokusu/MUserInfo/update-admin-status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('input[name="_csrfToken"]').value
                    },
                    body: JSON.stringify({
                        i_id_user: userId,
                        i_admin: isAdmin
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('管理者権限が更新されました。');
                        } else {
                            alert(data.message || '管理者権限の更新に失敗しました。再試行してください。');
                            // 更新失敗時には元のチェック状態に戻す
                            event.target.checked = !event.target.checked;
                        }
                    })
                    .catch(() => {
                        alert('エラーが発生しました。再試行してください。');
                        // エラー時に元のチェック状態に戻す
                        event.target.checked = !event.target.checked;
                    });
            });
        });
    });
</script>
