<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\MRoomInfo> $mRoomInfo
 */

// 管理者権限の確認
$isAdmin = $user->get('i_admin') === 1;
?>
<style>
    /* ページネーションリンク間の感覚を調整 */
    .custom-pagination .page-item {
        margin: 0 5px; /* 左右に5px間隔を設定 */
    }

    .custom-pagination .page-item a {
        padding: 8px 12px; /* 内側のスペースを調整 */
        border-radius: 5px; /* 少し角を丸くする */
        border: 1px solid #ddd; /* 境界線を追加して視認性を向上 */
        text-decoration: none; /* 下線を消す */
        color: #007bff; /* 色を調整 */
    }

    .custom-pagination .page-item a:hover {
        background-color: #f8f9fa; /* ホバー時の背景色 */
        border-color: #007bff; /* ホバー時のボーダーカラー */
        color: #0056b3; /* ホバー時のテキスト色 */
    }
</style>
<div class="mRoomInfo index content">
    <?= $this->Html->link(__('新しい部屋情報を追加'), ['action' => 'add'], ['class' => 'btn btn-success float-right mb-3']) ?>
    <h3><?= __('部屋情報一覧') ?></h3>
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead class="thead-dark">
            <tr>
                <th><?= $this->Paginator->sort('i_id_room', '部屋ID') ?></th>
                <th><?= $this->Paginator->sort('c_room_name', '部屋名') ?></th>
                <th><?= $this->Paginator->sort('i_disp_no','表示順')?></th>
                <th class="text-center"><?= __('アクション') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mRoomInfo as $room): ?>
                <tr>
                    <td><?= $this->Number->format($room->i_id_room) ?></td>
                    <td><?= h($room->c_room_name) ?></td>
                    <td><?= $this->Number->format($room->i_disp_no) ?></td>
                    <td class="text-center">
                        <?= $this->Html->link(__('表示'), ['action' => 'view', $room->i_id_room], ['class' => 'btn btn-info btn-sm']) ?>
                        <?php if ($isAdmin): // 管理者のみが編集と削除を行える ?>
                            <?= $this->Html->link(__('編集'), ['action' => 'edit', $room->i_id_room], ['class' => 'btn btn-warning btn-sm']) ?>
                            <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $room->i_id_room], ['confirm' => __('本当に削除してもよろしいですか？', $room->i_id_room), 'class' => 'btn btn-danger btn-sm']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination justify-content-center custom-pagination">
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
</div>
