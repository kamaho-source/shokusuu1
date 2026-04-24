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
echo $this->Html->css('pages/m_user_info_index.css');
$this->assign('title', 'ユーザー情報一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="mUserInfo index content">
    <?php if ($isAdmin || $user->get('i_user_level') === 0): ?>
        <?= $this->Html->link(__('新しくユーザを追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
        <?= $this->Html->link(__('一括ユーザー登録'), ['action' => 'importForm'], ['class' => 'btn btn-primary float-right mb-3 mr-2']) ?>
    <?php endif; ?>
    
    <?php if ($isAdmin): ?>
        <div class="float-right mb-3 mr-2">
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

    <h3 id="userListTitle" class="mb-4">
        <?php if (isset($showDeleted) && $showDeleted): ?>
            <span class="badge badge-danger badge-lg">
                <i class="bi bi-trash"></i> 削除済みユーザー一覧
            </span>
        <?php else: ?>
            <span class="badge badge-primary badge-lg">
                <i class="bi bi-people"></i> ユーザー一覧
            </span>
        <?php endif; ?>
    </h3>

    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th><?= $this->Paginator->sort('i_id_user', ['label' => 'ユーザー識別ID']) ?></th>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => 'ユーザー名']) ?></th>
                <th><?= $this->Paginator->sort('i_disp_no', ['label' => '表示順']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <?php if ($isAdmin): ?>
                    <th><?= __('ブロック長') ?></th>
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
                                       <?= $userInfo->i_admin === 1 ? 'checked' : '' ?>
                                       data-user-id="<?= h($userInfo->i_id_user) ?>"
                                       data-user-name="<?= h($userInfo->c_user_name) ?>">
                            </div>
                        </td>
                    <?php endif; ?>
                    <td class="actions">
                        <?php if (isset($showDeleted) && $showDeleted): ?>
                            <?php if ($isAdmin): ?>
                                <?= $this->Form->postLink(__('復元'), ['action' => 'restore', $userInfo->i_id_user], [
                                        'confirm' => __('「{0}」を復元してもよろしいですか？', $userInfo->c_user_name),
                                        'class' => 'btn btn-success btn-sm'
                                ]) ?>
                            <?php endif; ?>
                        <?php else: ?>
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

<!-- 確認ポップアップ -->
<div id="popup-overlay"></div>
<div id="confirm-popup" role="dialog" aria-modal="true" aria-labelledby="popup-message">
    <div class="popup-icon-wrap">
        <svg class="popup-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <circle cx="12" cy="16" r=".5" fill="currentColor"/>
        </svg>
    </div>
    <p id="popup-message"></p>
    <div class="popup-actions">
        <button id="popup-cancel" type="button">キャンセル</button>
        <button id="popup-ok"     type="button">確定</button>
    </div>
</div>

<!-- 完了ポップアップ -->
<div id="result-popup" role="status">
    <span id="result-popup-icon"></span>
    <span id="result-popup-msg"></span>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleNormal  = document.getElementById('toggleNormal');
        const toggleDeleted = document.getElementById('toggleDeleted');
        const csrfToken =
            document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ||
            document.querySelector('input[name="_csrfToken"]')?.value ||
            '';

        // ---- ポップアップユーティリティ ----
        const overlay      = document.getElementById('popup-overlay');
        const confirmPopup = document.getElementById('confirm-popup');
        const resultPopup  = document.getElementById('result-popup');

        function showConfirm(message) {
            return new Promise(resolve => {
                document.getElementById('popup-message').textContent = message;
                overlay.classList.add('show');
                confirmPopup.classList.add('show');

                const okBtn     = document.getElementById('popup-ok');
                const cancelBtn = document.getElementById('popup-cancel');

                function cleanup() {
                    okBtn.removeEventListener('click', onOk);
                    cancelBtn.removeEventListener('click', onCancel);
                    overlay.removeEventListener('click', onCancel);
                }
                function hide()     { overlay.classList.remove('show'); confirmPopup.classList.remove('show'); }
                function onOk()     { cleanup(); hide(); resolve(true);  }
                function onCancel() { cleanup(); hide(); resolve(false); }

                okBtn.addEventListener('click', onOk);
                cancelBtn.addEventListener('click', onCancel);
                overlay.addEventListener('click', onCancel);
            });
        }

        let resultTimer = null;
        function showResult(message, success = true) {
            document.getElementById('result-popup-icon').textContent = success ? '✅' : '❌';
            document.getElementById('result-popup-msg').textContent  = message;
            resultPopup.className = success ? 'show success' : 'show error';
            clearTimeout(resultTimer);
            resultTimer = setTimeout(() => { resultPopup.className = ''; }, 2000);
        }

        // ---- 管理者トグル ----
        document.querySelectorAll('.admin-checkbox').forEach(cb => {
            cb.addEventListener('change', async function () {
                const userId   = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                const isAdmin  = this.checked ? 1 : 0;
                const message  = isAdmin
                    ? `${userName} に管理者権限を付与しますか？`
                    : `${userName} から管理者権限を削除しますか？`;

                const ok = await showConfirm(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch('/kamaho-shokusu/MUserInfo/update-admin-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_admin: isAdmin })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        showResult('管理者権限を更新しました。');
                    } else {
                        showResult(payload.message || '管理者権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { showResult('エラーが発生しました。', false); this.checked = !this.checked; });
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

                const ok = await showConfirm(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch('/kamaho-shokusu/MUserInfo/update-user-level', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_admin: newAdmin })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        this.setAttribute('data-current-admin', newAdmin);
                        showResult('ブロック長権限を更新しました。');
                    } else {
                        showResult(payload.message || 'ブロック長権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { showResult('エラーが発生しました。', false); this.checked = !this.checked; });
            });
        });

        // ---- 通常/削除済みトグル ----
        if (toggleNormal) {
            toggleNormal.addEventListener('click', () => {
                if (!toggleNormal.classList.contains('active')) {
                    window.location.href = '/kamaho-shokusu/MUserInfo/';
                }
            });
        }
        if (toggleDeleted) {
            toggleDeleted.addEventListener('click', () => {
                if (!toggleDeleted.classList.contains('active')) {
                    window.location.href = '/kamaho-shokusu/MUserInfo?show_deleted=1';
                }
            });
        }
    });
</script>
