<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MUserInfo> $mUserInfo
 * @var array $userRooms
 * @var array<int, string> $userRoomLabels
 * @var \App\Model\Entity\User $user
 * @var mixed $showDeleted
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
    <?php if (!empty($canRegisterUsers)): ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
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
                <th style="width:10%;"><?= $this->Paginator->sort('c_login_account', ['label' => 'ログインID']) ?></th>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => 'ユーザー名']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <?php if ($isAdmin || $isSystemAdmin): ?>
                    <th><?= __('ブロック長') ?></th>
                    <th><?= __('管理者権限') ?></th>
                    <?php if ($isSystemAdmin): ?>
                        <th><?= __('システム管理者') ?></th>
                        <th><?= __('レポート閲覧') ?></th>
                    <?php endif; ?>
                <?php endif; ?>
                <th class="actions"><?= __('操作') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mUserInfo as $userInfo): ?>
                <tr data-role="<?= (int)($userInfo->i_admin ?? 0) ?>">
                    <td class="d-none d-md-table-cell text-muted small"><?= h($userInfo->i_id_user) ?></td>
                    <td><?= h($userInfo->c_login_account) ?></td>
                    <td><?= h($userInfo->c_user_name) ?></td>
                    <td><?= h($userRoomLabels[$userInfo->i_id_user] ?? '未所属') ?></td>
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
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input report-access-checkbox" type="checkbox" role="switch"
                                           <?= (int)$userInfo->i_report_access === 1 ? 'checked' : '' ?>
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

        // ---- 権限トグル（ブロック長・管理者・システム管理者は排他：1ユーザー1つまで） ----
        // 行の現在権限は tr[data-role]（0=一般 / 1=管理者 / 2=ブロック長 / 3=システム管理者）で持つ。
        const ROLES = {
            1: { label: '管理者',         cls: 'admin-checkbox',        url: '/MUserInfo/update-admin-status',        body: (id, on, ow) => ({ i_id_user: id, i_admin: on ? 1 : 0, overwrite: ow }) },
            2: { label: 'ブロック長',     cls: 'block-leader-checkbox', url: '/MUserInfo/update-user-level',          body: (id, on, ow) => ({ i_id_user: id, i_admin: on ? 2 : 0, overwrite: ow }) },
            3: { label: 'システム管理者', cls: 'system-admin-checkbox', url: '/MUserInfo/update-system-admin-status', body: (id, on, ow) => ({ i_id_user: id, i_system_admin: on ? 1 : 0, overwrite: ow }) },
        };

        // 行の表示を現在権限に合わせる（該当するスイッチだけ ON）
        const syncRow = (tr, role) => {
            tr.dataset.role = String(role);
            Object.keys(ROLES).forEach(r => {
                const el = tr.querySelector('.' + ROLES[r].cls);
                if (el) el.checked = (parseInt(r, 10) === role);
            });
        };

        Object.keys(ROLES).forEach(key => {
            const target = parseInt(key, 10);
            const cfg    = ROLES[key];
            document.querySelectorAll('.' + cfg.cls).forEach(cb => {
                cb.addEventListener('change', async function () {
                    const tr       = this.closest('tr');
                    const userId   = this.getAttribute('data-user-id');
                    const userName = this.getAttribute('data-user-name');
                    const turnOn   = this.checked;
                    const current  = parseInt(tr.dataset.role || '0', 10);
                    const revert   = () => syncRow(tr, current);

                    let message;
                    let overwrite = false;
                    if (turnOn && current !== 0 && current !== target) {
                        // 別の権限からの変更（排他のため置き換わる）
                        overwrite = true;
                        message = `${userName} は現在「${ROLES[current].label}」です。「${cfg.label}」に変更すると「${ROLES[current].label}」権限は外れます。本当に変更しますか？`;
                    } else if (turnOn) {
                        message = `${userName} を${cfg.label}に設定しますか？`;
                    } else {
                        message = `${userName} から${cfg.label}権限を削除しますか？`;
                    }

                    const ok = await window.ConfirmPopup.show(message);
                    if (!ok) { revert(); return; }

                    try {
                        const res  = await fetch(BASE_PATH + cfg.url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                            body: JSON.stringify(cfg.body(userId, turnOn, overwrite))
                        });
                        const data    = await res.json();
                        const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                        if (payload.ok === true || payload.success) {
                            syncRow(tr, turnOn ? target : 0);
                            window.ConfirmPopup.showResult(`${cfg.label}権限を更新しました。`);
                        } else {
                            revert();
                            window.ConfirmPopup.showResult(payload.message || `${cfg.label}権限の更新に失敗しました。`, false);
                        }
                    } catch (e) {
                        revert();
                        window.ConfirmPopup.showResult('エラーが発生しました。', false);
                    }
                });
            });
        });

        // ---- レポート閲覧権限トグル ----
        document.querySelectorAll('.report-access-checkbox').forEach(cb => {
            cb.addEventListener('change', async function () {
                const userId       = this.getAttribute('data-user-id');
                const userName     = this.getAttribute('data-user-name');
                const reportAccess = this.checked ? 1 : 0;
                const message      = reportAccess
                    ? `${userName} にシステムレポートの閲覧権限を付与しますか？`
                    : `${userName} からシステムレポートの閲覧権限を削除しますか？`;

                const ok = await window.ConfirmPopup.show(message);
                if (!ok) { this.checked = !this.checked; return; }

                fetch(BASE_PATH + '/MUserInfo/update-report-access', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ i_id_user: userId, i_report_access: reportAccess })
                })
                .then(r => r.json())
                .then(data => {
                    const payload = window.normalizeApiPayload ? window.normalizeApiPayload(data) : data;
                    if (payload.ok === true || payload.success) {
                        window.ConfirmPopup.showResult('レポート閲覧権限を更新しました。');
                    } else {
                        window.ConfirmPopup.showResult(payload.message || 'レポート閲覧権限の更新に失敗しました。', false);
                        this.checked = !this.checked;
                    }
                })
                .catch(() => { window.ConfirmPopup.showResult('エラーが発生しました。', false); this.checked = !this.checked; });
            });
        });

        // ---- 削除ボタン（カスタム確認ダイアログ） ----
        document.querySelectorAll('.js-delete-btn').forEach(btn => {
            // CakePHP 既定の確認(ネイティブ confirm → 送信)を止め、共通モーダルに差し替える。
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

                // postLink はこのリンクの直前に hidden form を出力する。その form を送信する。
                // 旧実装は onclick 内の getElementById('...') を正規表現で拾っていたが、
                // CakePHP 5.3 の postLink は onclick に getElementById を使わない（form 名指定）ため
                // 一致せず、確認後に何も送信されず「削除できない」状態になっていた。
                let form = this.previousElementSibling;
                if (!form || form.tagName !== 'FORM') {
                    const scope = this.closest('td, div') || document;
                    form = scope.querySelector('form[action*="/delete/"]');
                }
                if (!form) return;

                // requestSubmit 非対応の古い端末（iOS Safari 16 未満など）に備えてフォールバック。
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
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