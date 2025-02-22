<div class="container">
    <h1>予約一覧</h1>
    <h3>日付: <?= h($date) ?></h3>

    <?php foreach (['朝' => 1, '昼' => 2, '夜' => 3,'弁当'=>4] as $mealLabel => $mealType): ?>
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
                            <?php
                            $url = "/TReservationInfo/roomDetails/{$data['room_id']}/{$date}/{$mealType}";
                            echo $this->Html->link('詳細', $url, ['class' => 'btn btn-primary btn-sm']);
                            ?>
                            <?php
                            $url = "/TReservationInfo/edit/{$data['room_id']}/{$date}/{$mealType}";
                            echo $this->Html->link('編集', $url, ['class' => 'btn btn-primary btn-sm']);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>予約データがありません。</p>
        <?php endif; ?>
    <?php endforeach; ?>

    <button class="btn btn-primary" onclick="location.href='<?= $this->Url->build(['action' => 'add', '?' => ['date' => $date]]) ?>'">追加する</button>
</div>
