<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 * @var array $userRooms
 * @var \App\Model\Entity\User $user
 */

$isAdmin = in_array((int)$user->get('i_admin'), [1, 3]);
$isSystemAdmin = isset($isSystemAdmin) ? $isSystemAdmin : ((int)$user->get('i_admin') === 3);
$currentUserId = $user->get('i_id_user');

echo $this->Html->css(['bootstrap.min']);
echo $this->Html->css('pages/m_user_info_index.css');
$this->assign('title', 'ユーザー情報一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="mUserInfo index content">
    <?php if ($isAdmin || $user->get('i_user_level') === 0): ?>
        <div class="d-flex gap-2 mb-3">
            <?= $this->Html->link(__('新しくユーザを追加'), ['action' => 'add'], ['class' => 'btn btn-success']) ?>
            <?= $this->Html->link(__('一括ユーザー登録'), ['action' => 'importForm'], ['class' => 'btn btn-primary']) ?>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin || $isSystemAdmin): ?>
        <div class="mb-3">
            <div class="user-view-toggle-container">
                <button type="button" class="toggle-btn toggle-btn-left <?= !isset($showDeleted) || !$showDeleted ? 'active' : '' ?>" id="toggleNormal">
                    <i class="bi bi-people-fill"></i>
                    <span>通常ユーザー</span>
                </button>
                <button type="button" class="toggle-btn toggle-btn-right <?= isset($showDeleted) && $showDeleted ? 'active' : '' ?>" id="toggleDeleted">
                    <i class="bi bi-trash-fill"></i>
                    <span>削除済み</span>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-hover">
            <tr>
                <th class="d-none d-md-table-cell" style="width:5%;"><?= $this->Paginator->sort('i_id_user', ['label' => 'No.']) ?></th>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => 'ユーザー名']) ?></th>
                <th class="d-none d-md-table-cell" style="width:8%;"><?= $this->Paginator->sort('i_disp_no', ['label' => '表示順']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <?php if ($isAdmin || $isSystemAdmin): ?>
                    <th><?= __('ブロック長') ?></th>
                    <th><?= __('管理者権限') ?></th>
                    <?php if ($isSystemAdmin): ?>
                        <th><?= __('システム管理者') ?></th>
                    <?php endif; ?>
                <?php endif; ?>
                <th class="actions"><?= __('操作') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mUserInfo as $userInfo): ?>
                <tr>
                    <td class="d-none d-md-table-cell text-muted small"><?= h($userInfo->i_id_user) ?></td>
                    <td><?= h($userInfo->c_user_name) ?></td>
                    <td class="d-none d-md-table-cell text-center"><?= $userInfo->i_disp_no !== null ? $this->Number->format($userInfo->i_disp_no) : '' ?></td>
                    <td><?= !empty($userRooms[$userInfo->i_id_user]) ? h(implode(', ', $userRooms[$userInfo->i_id_user])) : '未所属' ?></td>
                    <?php if ($isAdmin || $isSystemAdmin): ?>
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input block-leader-checkbox" type="checkbox" role="switch"
                                       <?= (int)$userInfo->i_admin === 2 ? 'checked' : '' ?>
                                       data-user-id="<?= h($userInfo->i_id_user) ?>"
                                       data-user-name="<?= h($userInfo->c_user_name) ?>"
                                       data-current-admin="<?= (int)($userInfo->i_admin ?? 0) ?>">
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input admin-checkbox" type="checkbox" role="switch"
                                       <?= (int)$userInfo->i_admin === 1 ? 'checked' : '' ?>
                                       data-user-id="<?= h($userInfo->i_id_user) ?>"
                                       data-user-name="<?= h($userInfo->c_user_name) ?>">
                            </div>
                        </td>
                        <?php if ($isSystemAdmin): ?>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input system-admin-checkbox" type="checkbox" role="switch"
                                           <?= (int)$userInfo->i_admin === 3 ? 'checked' : '' ?>
                                           data-user-id="<?= h($userInfo->i_id_user) ?>"
                                           data-user-name="<?= h($userInfo->c_user_name) ?>">
                                </div>
                            </td>
                        <?php endif; ?>
                    <?php endif; ?>
                    <td class="actions">
                        <div class="d-flex gap-1">
                        <?php if (isset($showDeleted) && $showDeleted): ?>
                            <?php if ($isAdmin || $isSystemAdmin): ?>
                                <?= $this->Form->postLink(__('復元'), ['action' => 'restore', $userInfo->i_id_user], [
                                        'confirm' => __('「{0}」を復元してもよろしいですか？', $userInfo->c_user_name),
                                        'class' => 'btn btn-success btn-sm'
                                ]) ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $this->Html->link(__('詳細'), ['action' => 'view', $userInfo->i_id_user], ['class' => 'btn btn-info btn-sm']) ?>
                            <?php if ($isAdmin || $userInfo->i_id_user === $currentUserId): ?>
                                <?= $this->Html->link(__('編集'), ['action' => 'edit', $userInfo->i_id_user], ['class' => 'btn btn-warning btn-sm ms-1']) ?>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <?= $this->Form->postLink(__('🗑 削除'), ['action' => 'delete', $userInfo->i_id_user], [
                                        'class' => 'btn btn-danger btn-sm ms-3 js-delete-btn',
                                        'data-confirm-msg' => __('「{0}」を削除してもよろしいですか？', $userInfo->c_user_name),
                                ]) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
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
    const BASE_PATH = <?= json_encode(rtrim($this->request->getAttribute('base') ?? '', '/'), JSON_UNESCAPED_SLASHES) ?>;
    document.addEventListener('DOMContentLoaded', () => {
        const toggleNormal  = document.getElementById('toggleNormal');
        const toggleDeleted = document.getElementById('toggleDeleted');
        const csrfToken =
            document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ||
            document.querySelector('input[name="_csrfToken"]')?.value ||
            '';

        // ---- 管理者トグル ----
        document.querySelectorAll('.admin-checkbox').forEach(cb => {
            cb.addEventListener('change', async function () {
                const userId   = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                const isAdmin  = this.checked ? 1 : 0;
                const message  = isAdmin
                    ? `${userName} に管理者権限を付与しますか？`
                    : `${userName} から管理者権限を削除しますか？`;

                const ok = await window.ConfirmPopup.show(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch(BASE_PATH + '/MUserInfo/update-admin-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_admin: isAdmin })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        window.ConfirmPopup.showResult('管理者権限を更新しました。');
                    } else {
                        window.ConfirmPopup.showResult(payload.message || '管理者権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { window.ConfirmPopup.showResult('エラーが発生しました。', false); this.checked = !this.checked; });
            });
        });

        // ---- ブロック長トグル ----
        document.querySelectorAll('.block-leader-checkbox').forEach(cb => {
            cb.addEventListener('change', async function () {
                const userId       = this.getAttribute('data-user-id');
                const userName     = this.getAttribute('data-user-name');
                const currentAdmin = parseInt(this.getAttribute('data-current-admin'), 10);
                const isBlock      = this.checked;
                const newAdmin     = isBlock ? 2 : (currentAdmin === 2 ? 0 : currentAdmin);
                const message      = isBlock
                    ? `${userName} をブロック長に設定しますか？`
                    : `${userName} からブロック長権限を削除しますか？`;

                const ok = await window.ConfirmPopup.show(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch(BASE_PATH + '/MUserInfo/update-user-level', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_admin: newAdmin })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        this.setAttribute('data-current-admin', newAdmin);
                        window.ConfirmPopup.showResult('ブロック長権限を更新しました。');
                    } else {
                        window.ConfirmPopup.showResult(payload.message || 'ブロック長権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { window.ConfirmPopup.showResult('エラーが発生しました。', false); this.checked = !this.checked; });
            });
        });

        // ---- システム管理者トグル ----
        document.querySelectorAll('.system-admin-checkbox').forEach(cb => {
            cb.addEventListener('change', async function () {
                const userId        = this.getAttribute('data-user-id');
                const userName      = this.getAttribute('data-user-name');
                const isSystemAdmin = this.checked ? 1 : 0;
                const message       = isSystemAdmin
                    ? `${userName} にシステム管理者権限を付与しますか？`
                    : `${userName} からシステム管理者権限を削除しますか？`;

                const ok = await window.ConfirmPopup.show(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch(BASE_PATH + '/MUserInfo/update-system-admin-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_system_admin: isSystemAdmin })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        window.ConfirmPopup.showResult('システム管理者権限を更新しました。');
                    } else {
                        window.ConfirmPopup.showResult(payload.message || 'システム管理者権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { window.ConfirmPopup.showResult('エラーが発生しました。', false); this.checked = !this.checked; });
            });
        });

        // ---- 削除ボタン（カスタム確認ダイアログ） ----
        document.querySelectorAll('.js-delete-btn').forEach(btn => {
            const originalOnclick = btn.getAttribute('onclick');
            btn.removeAttribute('onclick');
            btn.addEventListener('click', async function (e) {
                e.preventDefault();
                const msg = this.dataset.confirmMsg;
                const ok = await window.ConfirmPopup.show(msg, {
                    okLabel: '削除する',
                    okColor: 'danger',
                    type: 'danger',
                });
                if (!ok) return;
                const match = originalOnclick && originalOnclick.match(/getElementById\(['"]([^'"]+)['"]\)/);
                if (match) document.getElementById(match[1]).submit();
            });
        });

        // ---- 通常/削除済みトグル ----
        if (toggleNormal) {
            toggleNormal.addEventListener('click', () => {
                if (!toggleNormal.classList.contains('active')) {
                    window.location.href = BASE_PATH + '/MUserInfo/';
                }
            });
        }
        if (toggleDeleted) {
            toggleDeleted.addEventListener('click', () => {
                if (!toggleDeleted.classList.contains('active')) {
                    window.location.href = BASE_PATH + '/MUserInfo?show_deleted=1';
                }
            });
        }
    });
</script>