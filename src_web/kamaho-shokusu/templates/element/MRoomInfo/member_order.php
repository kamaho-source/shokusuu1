<?php
/**
 * 部屋の所属メンバー一覧（#871）。$canReorder が true のときだけ並び替えの操作を出す。
 * 並び替えは部屋情報の編集フォームの中に置き、行ごとの user_ids[] を画面の並び順のまま送る（保存はフォームの［保存］）。
 * 「順番」の数字は何人分でも入れておけて、［保存］のときにまとめてその順に並べる。
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MRoomInfo $mRoomInfo
 * @var array $users
 * @var bool $canReorder
 */
?>
<?php if (!empty($users)): ?>
    <?php
    // 並びは RoomMemberOrderService::orderMembers（表示順 → 名前）。区分（職員・児童・その他）をまたいで並べ替えられる
    $levelLabel = static fn(?int $level): string => match (true) {
        $level === null => '未設定',
        \App\Domain\ValueObject\UserRole::isStaffLevel($level) => '職員',
        $level === 1 => '児童',
        default => 'その他',
    };
    ?>
    <?php $this->Html->css('room-member-order.css', ['block' => true]); ?>
    <h5 class="mt-3"><?= __('所属メンバー') ?>（<?= count($users) ?>人）</h5>
    <div class="xl-wrap">
        <?php if ($canReorder): ?>
            <div class="xlbar">
                <label><input type="checkbox" id="member-order-drag"> <?= __('ドラッグで並び替え') ?></label>
                <span><?= __('「順番」に数字を入れて［保存］でまとめて並べ替え') ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-age-sort="desc"><?= __('年上から') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-age-sort="asc"><?= __('年下から') ?></button>
                <span class="sp"></span>
                <span id="member-order-dirty"><?= __('保存済み') ?></span>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="xl-sheet" id="member-order-table">
                <thead>
                <tr>
                    <th class="grip"></th>
                    <th class="rh"></th>
                    <th><?= __('順番') ?></th>
                    <th><?= __('区分') ?></th>
                    <th><?= __('ユーザー名') ?></th>
                    <th><?= __('年齢') ?></th>
                    <th><?= __('ID') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php // ※ ループ変数にはログインユーザーの $user と別名を使う（シャドーイング防止） ?>
                <?php foreach (array_values($users) as $i => $member): ?>
                    <tr data-user-id="<?= (int)$member->i_id_user ?>" data-age="<?= $member->i_user_age === null ? '' : (int)$member->i_user_age ?>">
                        <td class="grip" title="<?= __('ドラッグして移動') ?>">⋮⋮</td>
                        <td class="rh"><?= $i + 1 ?></td>
                        <td class="num ord">
                            <?php if ($canReorder): ?>
                                <input type="hidden" name="user_ids[]" value="<?= (int)$member->i_id_user ?>">
                                <input type="number" class="member-order-input" step="1" min="1" max="<?= count($users) ?>" value="<?= $i + 1 ?>"
                                       aria-label="<?= h($member->c_user_name) ?> の順番">
                            <?php else: ?>
                                <?= $i + 1 ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h($levelLabel($member->i_user_level === null ? null : (int)$member->i_user_level)) ?></td>
                        <td><?= h($member->c_user_name) ?></td>
                        <td class="age"><?= $member->i_user_age === null ? '' : (int)$member->i_user_age . '歳' ?></td>
                        <td class="num"><?= (int)$member->i_id_user ?></td>
                        <td class="chgmark"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($canReorder): ?>
        <?php $this->Form->unlockField('user_ids'); ?>
        <?= $this->Html->script('room-member-order.js', ['defer' => true]) ?>
    <?php endif; ?>
<?php else: ?>
    <div class="alert alert-warning" role="alert">
        <?= __('この部屋には現在所属メンバーがいません。') ?>
    </div>
<?php endif; ?>
