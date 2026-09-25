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
echo $this->Html->css(['pages/m_user_info_index.css', 'pages/user_screens.css']);
$this->assign('title', 'ユーザー情報一覧');
$csrfToken = $this->request->getAttribute('csrfToken');

?>
<meta name="csrfToken" content="<?= h($csrfToken) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="mUserInfo index content">
    <?php if ($isAdmin || $user->get('i_user_level') === 0): ?>
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

    <?php
    /*
     * 一覧は「探して開く」ことに専念させる。
     * 権限の切り替えと削除は詳細画面へ移した。一覧に置くと隣同士が近く、
     * PC に不慣れな利用者が押し間違えたときに取り消す手段が画面に無いため。
     *
     * 権限(i_admin)の4値。名前だけでは何ができるか伝わらないため説明を添える。
     */
    $roleLabels = [
        0 => ['一般', '自分の食数だけ入力できます', 'general'],
        2 => ['ブロック長', '担当部屋の承認ができます', 'block'],
        1 => ['管理者', '全部屋の承認と設定ができます', 'admin'],
        3 => ['システム管理者', 'すべての操作ができます', 'system'],
    ];
    $canSeeRole = $isAdmin || $isSystemAdmin;

    // 絞り込み用の部屋名。表示中の利用者が実際に所属している部屋だけを出す。
    $filterRooms = [];
    foreach ($mUserInfo as $row) {
        foreach (explode(',', (string)($userRoomLabels[$row->i_id_user] ?? '')) as $name) {
            $name = trim($name);
            if ($name !== '' && $name !== '未所属' && $name !== '全部屋所属') {
                $filterRooms[$name] = true;
            }
        }
    }
    $filterRooms = array_slice(array_keys($filterRooms), 0, 6);
    ?>

    <div class="u-toolbar">
        <label class="u-search" for="user-search">
            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <circle cx="7" cy="7" r="4.5"></circle><path d="M10.5 10.5 14 14"></path>
            </svg>
            <input type="search" id="user-search" placeholder="名前で探す" autocomplete="off">
        </label>
        <div class="u-filters" role="group" aria-label="部屋で絞り込む">
            <button type="button" class="u-pill is-on" data-room="">すべて</button>
            <?php foreach ($filterRooms as $roomName): ?>
                <button type="button" class="u-pill" data-room="<?= h($roomName) ?>"><?= h($roomName) ?></button>
            <?php endforeach; ?>
            <button type="button" class="u-pill" data-room="未所属">未所属</button>
        </div>
        <span class="u-count" id="user-count" role="status"></span>
    </div>

    <div class="table-responsive">
        <table class="table u-table align-middle">
            <thead>
            <tr>
                <th><?= $this->Paginator->sort('c_user_name', ['label' => '名前']) ?></th>
                <th><?= __('所属部屋') ?></th>
                <?php if ($canSeeRole): ?>
                    <th><?= __('できること') ?></th>
                <?php endif; ?>
                <th class="u-table__action"><span class="visually-hidden">操作</span></th>
            </tr>
            </thead>
            <tbody id="user-rows">
            <?php foreach ($mUserInfo as $userInfo): ?>
                <?php
                $rowId    = (int)$userInfo->i_id_user;
                $isSelf   = $rowId === (int)$currentUserId;
                $roomText = $userRoomLabels[$rowId] ?? '未所属';
                $role     = $roleLabels[(int)($userInfo->i_admin ?? 0)] ?? $roleLabels[0];
                // 押せる人にだけボタンを出す。押しても弾かれるボタンは出さない。
                $canOpen  = $isAdmin || $isSelf;
                ?>
                <tr data-name="<?= h($userInfo->c_user_name) ?>" data-rooms="<?= h($roomText) ?>">
                    <td class="u-name">
                        <?= h($userInfo->c_user_name) ?>
                        <?php if ($isSelf): ?><span class="u-self">あなた</span><?php endif; ?>
                    </td>
                    <td class="u-rooms<?= $roomText === '未所属' ? ' is-empty' : '' ?>">
                        <?= h(str_replace(', ', ' / ', $roomText)) ?>
                    </td>
                    <?php if ($canSeeRole): ?>
                        <td>
                            <span class="u-role u-role--<?= h($role[2]) ?>"><?= h($role[0]) ?></span>
                            <span class="u-role-help"><?= h($role[1]) ?></span>
                        </td>
                    <?php endif; ?>
                    <td class="u-table__action">
                        <?php if (isset($showDeleted) && $showDeleted): ?>
                            <?php if ($isAdmin || $isSystemAdmin): ?>
                                <?= $this->Form->postLink('元に戻す', ['action' => 'restore', $rowId], [
                                    'class' => 'u-open u-open--restore',
                                    'confirm' => sprintf('「%s」を元に戻します。よろしいですか？', $userInfo->c_user_name),
                                ]) ?>
                            <?php endif; ?>
                        <?php elseif ($canOpen): ?>
                            <?= $this->Html->link(
                                $isSelf && !$isAdmin ? '自分の情報を見る' : '開く',
                                ['action' => 'view', $rowId],
                                ['class' => 'u-open']
                            ) ?>
                        <?php else: ?>
                            <span class="u-noaction" aria-hidden="true">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="u-noresult" id="user-noresult" hidden>該当する人がいません。</p>
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
        /* ---- 通常 / 削除済み の切り替え ---- */
        const toggleNormal  = document.getElementById('toggleNormal');
        const toggleDeleted = document.getElementById('toggleDeleted');
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

        /* ---- 名前で探す / 部屋で絞り込む ----
           表示中のページ内で絞り込む。人数が増えてもスクロールだけにならないよう、
           結果の件数を必ず出す。 */
        const search   = document.getElementById('user-search');
        const pills    = document.querySelectorAll('.u-pill');
        const rows     = Array.from(document.querySelectorAll('#user-rows tr'));
        const countEl  = document.getElementById('user-count');
        const noResult = document.getElementById('user-noresult');
        let roomFilter = '';

        const apply = () => {
            const q = (search?.value || '').trim();
            let shown = 0;
            rows.forEach(tr => {
                const name  = tr.dataset.name || '';
                const rooms = tr.dataset.rooms || '';
                const hitName = q === '' || name.includes(q);
                const hitRoom = roomFilter === ''
                    || (roomFilter === '未所属' ? rooms === '未所属' : rooms.includes(roomFilter));
                const ok = hitName && hitRoom;
                tr.hidden = !ok;
                if (ok) shown++;
            });
            if (countEl)  countEl.textContent = shown + '人';
            if (noResult) noResult.hidden = shown !== 0;
        };

        if (search) search.addEventListener('input', apply);
        pills.forEach(pill => {
            pill.addEventListener('click', () => {
                pills.forEach(p => p.classList.toggle('is-on', p === pill));
                roomFilter = pill.dataset.room || '';
                apply();
            });
        });
        apply();
    });
</script>