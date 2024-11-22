<div class="container">
    <h1>予約一覧</h1>
    <h3>日付: <?= h($date) ?></h3>

    <?php foreach (['朝' => 1, '昼' => 2, '夜' => 3] as $mealLabel => $mealType): ?>
        <h2><?= h($mealLabel) ?>の予約</h2>

        <?php if (!empty($mealDataArray[$mealLabel])): ?>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>部屋名</th>
                    <th>食べる人数</th>
                    <th>食べない人数</th>
                    <th>詳細</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mealDataArray[$mealLabel] as $data): ?>
                    <tr>
                        <td><?= h($data['room_name']) ?></td>
                        <td><?= h($data['taberu_ninzuu']) ?></td>
                        <td><?= h($data['tabenai_ninzuu']) ?></td>
                        <td>
                            <?= $this->Html->link(
                                __('詳細'),
                                [
                                    'controller' => 'TReservationInfo',
                                    'action' => 'roomDetails',
                                    $data['room_id'], // 部屋ID
                                    $date,
                                    $mealType // 食事タイプ
                                ],
                                ['class' => 'btn btn-primary btn-sm']
                            ) ?>
                        </td>

                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>予約データがありません。</p>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- 他のページに戻るリンク -->
    <div>
        <?= $this->Html->link(__('新しい予約を追加'), ['action' => 'add'], ['class' => 'btn btn-success']) ?>
    </div>
</div>
