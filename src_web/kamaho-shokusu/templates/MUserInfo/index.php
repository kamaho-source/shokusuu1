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

<style>
    /* ---- 確認ポップアップ ---- */
    #popup-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,.35);
        backdrop-filter: blur(2px);
        z-index: 1040;
        animation: overlay-in .15s ease;
    }
    #popup-overlay.show { display: block; }

    #confirm-popup {
        display: none;
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -60%) scale(.92);
        background: #fff;
        border-radius: 16px;
        padding: 28px 28px 22px;
        width: min(360px, 92vw);
        box-shadow: 0 20px 60px rgba(0,0,0,.22);
        z-index: 1050;
        text-align: center;
        transition: transform .18s cubic-bezier(.34,1.56,.64,1), opacity .15s ease;
        opacity: 0;
    }
    #confirm-popup.show {
        display: block;
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
    }
    .popup-icon-wrap {
        width: 52px; height: 52px;
        margin: 0 auto 14px;
        background: #fffbeb;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
    }
    .popup-icon-svg {
        width: 28px; height: 28px;
        stroke: #f59e0b;
    }
    #popup-message {
        font-size: .95rem;
        font-weight: 500;
        color: #374151;
        margin: 0 0 20px;
        line-height: 1.6;
    }
    .popup-actions {
        display: flex; gap: 10px; justify-content: center;
    }
    .popup-actions button {
        flex: 1;
        padding: 9px 0;
        border: none;
        border-radius: 10px;
        font-size: .88rem;
        font-weight: 600;
        cursor: pointer;
        transition: filter .15s;
    }
    .popup-actions button:hover { filter: brightness(.93); }
    #popup-cancel {
        background: #f3f4f6;
        color: #6b7280;
    }
    #popup-ok {
        background: #6366f1;
        color: #fff;
    }

    /* ---- 完了ポップアップ ---- */
    #result-popup {
        position: fixed;
        bottom: 28px; right: 28px;
        display: flex; align-items: center; gap: 10px;
        background: #fff;
        border-radius: 14px;
        padding: 14px 20px;
        font-size: .9rem;
        font-weight: 600;
        box-shadow: 0 8px 32px rgba(0,0,0,.16);
        z-index: 1060;
        transform: translateY(20px);
        opacity: 0;
        pointer-events: none;
        transition: transform .22s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;
    }
    #result-popup.show {
        transform: translateY(0);
        opacity: 1;
        pointer-events: auto;
    }
    #result-popup.success { border-left: 4px solid #10b981; color: #065f46; }
    #result-popup.error   { border-left: 4px solid #ef4444; color: #991b1b; }
    #result-popup-icon { font-size: 1.3rem; }

    @keyframes overlay-in { from { opacity: 0; } to { opacity: 1; } }

    /* トグルボタンコンテナ */
    .user-view-toggle-container {
        display: inline-flex;
        background: #fff;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    /* トグルボタン共通スタイル */
    .toggle-btn {
        padding: 10px 20px;
        border: none;
        background: #fff;
        color: #6c757d;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 6px;
        outline: none;
        border-right: 1px solid #dee2e6;
    }
    
    .toggle-btn:last-child {
        border-right: none;
    }
    
    .toggle-btn i {
        font-size: 16px;
    }
    
    /* ホバー時 */
    .toggle-btn:hover:not(.active) {
        background: #f8f9fa;
    }
    
    /* 左ボタン（通常ユーザー） */
    .toggle-btn-left.active {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: #fff;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
    }
    
    /* 右ボタン（削除済み） */
    .toggle-btn-right.active {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: #fff;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
    }
    
    /* アクティブボタンのアイコン */
    .toggle-btn.active i {
        animation: iconPulse 0.3s ease;
    }
    
    @keyframes iconPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.2); }
    }
    
    /* タイトルバッジ */
    .badge-lg {
        font-size: 18px;
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 6px;
    }
    
    .badge-lg i {
        margin-right: 8px;
    }
</style>
