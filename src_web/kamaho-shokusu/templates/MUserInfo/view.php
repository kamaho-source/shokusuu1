<?php
/**
 * ユーザー情報の表示
 *
 * 一覧からは「開く」でここへ来る。権限の変更と削除は一覧に置くと誤タップの元になるため、
 * この画面に集約している。
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MUserInfo $mUserInfo
 * @var array $userRooms
 * @var bool $isAdmin
 * @var bool $isSystemAdmin
 * @var int $currentUserId
 */

$this->assign('title', 'ユーザー情報');
$this->Html->css('pages/user_screens.css', ['block' => 'css']);

$targetId = (int)$mUserInfo->i_id_user;
$isSelf   = $targetId === (int)$currentUserId;
$canEdit  = $isAdmin || $isSelf;

/** 権限の4値。ラベルだけでは何ができるか伝わらないため説明を添える。 */
$roleLabels = [
    0 => ['一般', '自分の食数だけ入力できます', 'general'],
    2 => ['ブロック長', '担当部屋の承認ができます', 'block'],
    1 => ['管理者', '全部屋の承認と設定ができます', 'admin'],
    3 => ['システム管理者', 'すべての操作ができます', 'system'],
];
$currentRole = $roleLabels[(int)($mUserInfo->i_admin ?? 0)] ?? $roleLabels[0];
?>
<div class="u-shell u-shell--narrow">

    <div class="u-head">
        <div>
            <div class="u-eyebrow">ユーザー情報</div>
            <h1 class="u-title"><?= h($mUserInfo->c_user_name) ?><?= $isSelf ? '<span class="u-self">あなた</span>' : '' ?></h1>
        </div>
        <a class="u-back" href="<?= $this->Url->build(['action' => 'index']) ?>">
            <span aria-hidden="true">←</span> ユーザー一覧へ戻る
        </a>
    </div>

    <section class="u-card">
        <h2 class="u-card__title">基本情報</h2>
        <dl class="u-list">
            <?php if ($canEdit): ?>
                <div class="u-list__row">
                    <dt>ログインID</dt>
                    <dd><?= h($mUserInfo->c_login_account) ?></dd>
                </div>
            <?php endif; ?>
            <div class="u-list__row">
                <dt>名前</dt>
                <dd><?= h($mUserInfo->c_user_name) ?></dd>
            </div>
            <?php if ($mUserInfo->i_id_staff): ?>
                <div class="u-list__row">
                    <dt>職員ID</dt>
                    <dd><?= h($mUserInfo->i_id_staff) ?></dd>
                </div>
            <?php endif; ?>
            <div class="u-list__row">
                <dt>所属部屋</dt>
                <dd>
                    <?php if (!empty($userRooms)): ?>
                        <?= h(implode(' / ', $userRooms)) ?>
                    <?php else: ?>
                        <span class="u-empty">未所属</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="u-list__row">
                <dt>できること</dt>
                <dd>
                    <span class="u-role u-role--<?= h($currentRole[2]) ?>"><?= h($currentRole[0]) ?></span>
                    <span class="u-role-help"><?= h($currentRole[1]) ?></span>
                </dd>
            </div>
        </dl>

        <?php if ($canEdit): ?>
            <div class="u-actions">
                <a class="u-btn u-btn--primary" href="<?= $this->Url->build(['action' => 'edit', $targetId]) ?>">
                    この人の情報を変更する
                </a>
            </div>
        <?php endif; ?>
    </section>

    <?php /* 権限の変更: 管理者のみ。一覧のトグルを廃止した代わりの操作 */ ?>
    <?php if ($isAdmin && !$isSelf): ?>
        <section class="u-card">
            <h2 class="u-card__title">できることを変える</h2>
            <p class="u-card__note">選んで「変更する」を押すと、確認のうえ保存します。</p>
            <div class="u-role-form"
                 data-url="<?= h($this->Url->build('/MUserInfo/update-admin-status')) ?>"
                 data-user-id="<?= h($targetId) ?>"
                 data-user-name="<?= h($mUserInfo->c_user_name) ?>">
                <label class="u-label" for="u-role-select">権限</label>
                <select class="u-select" id="u-role-select">
                    <?php foreach ($roleLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= (int)($mUserInfo->i_admin ?? 0) === $value ? 'selected' : '' ?>>
                            <?= h($label[0]) ?>（<?= h($label[1]) ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="u-btn u-btn--primary" id="u-role-save">変更する</button>
                <p class="u-msg" id="u-msg" role="status"></p>
            </div>
        </section>
    <?php endif; ?>

    <?php /* 削除: 管理者のみ。一覧から外してここに置く */ ?>
    <?php if ($isAdmin && !$isSelf): ?>
        <section class="u-card u-card--danger">
            <h2 class="u-card__title">このユーザーを削除する</h2>
            <p class="u-card__note">
                削除すると一覧に表示されなくなります。過去の食数データは残ります。
                削除済みユーザーは一覧の「削除済み」から元に戻せます。
            </p>
            <?= $this->Form->postLink('削除する', ['action' => 'delete', $targetId], [
                'class' => 'u-btn u-btn--danger',
                'confirm' => sprintf('「%s」を削除します。よろしいですか？', $mUserInfo->c_user_name),
            ]) ?>
        </section>
    <?php endif; ?>

</div>

<script>
/* 権限変更: 確認してから POST する。成功しても画面を残し、結果を文字で伝える。 */
(() => {
    const form = document.querySelector('.u-role-form');
    if (!form) return;
    const select = document.getElementById('u-role-select');
    const btn    = document.getElementById('u-role-save');
    const msg    = document.getElementById('u-msg');
    const original = select.value;

    btn.addEventListener('click', async () => {
        if (select.value === original) {
            msg.textContent = '変更されていません。';
            msg.className = 'u-msg is-info';
            return;
        }
        const label = select.options[select.selectedIndex].textContent.trim();
        const name  = form.dataset.userName;
        const ok = window.ConfirmPopup
            ? await window.ConfirmPopup.show(`「${name}」の権限を「${label}」に変更します。よろしいですか？`)
            : window.confirm(`「${name}」の権限を「${label}」に変更します。よろしいですか？`);
        if (!ok) return;

        btn.disabled = true;
        msg.textContent = '変更中です...';
        msg.className = 'u-msg is-info';
        try {
            const res = await fetch(form.dataset.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ i_id_user: form.dataset.userId, i_admin: select.value }),
            });
            const data = await res.json();
            if (res.ok && data && (data.ok || data.status === 'success')) {
                msg.textContent = `「${label}」に変更しました。`;
                msg.className = 'u-msg is-done';
                setTimeout(() => window.location.reload(), 900);
                return;
            }
            msg.textContent = data?.message || '変更できませんでした。もう一度お試しください。';
            msg.className = 'u-msg is-error';
            btn.disabled = false;
        } catch (e) {
            msg.textContent = '通信に失敗しました。変更されたか画面を再読み込みして確認してください。';
            msg.className = 'u-msg is-error';
            btn.disabled = false;
        }
    });
})();
</script>
