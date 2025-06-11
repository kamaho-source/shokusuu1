<?php
$this->assign('title', h($date).'の食数予約一覧');
?>

<div class="container">
    <h1>予約一覧</h1>
    <h3>日付: <?= h($date) ?></h3>

    <?php
    // 今日の日付と1ヶ月後の日付を取得
    $currentDate = new \DateTime();
    $oneMonthLater = (clone $currentDate)->modify('+30 days');
    $selectedDate = new \DateTime($date);

    // 「当日から30日後」より前の日付ならボタン無効
    $isDisabled = ($selectedDate < $oneMonthLater);
    ?>

    <?php foreach (['朝' => 1, '昼' => 2, '夜' => 3, '弁当' => 4] as $mealLabel => $mealType): ?>
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
                            $urlDetails = "/TReservationInfo/roomDetails/{$data['room_id']}/{$date}/{$mealType}";
                            echo $this->Html->link('詳細', $urlDetails, ['class' => 'btn btn-primary btn-sm']);
                            ?>
                            <?php
                            $urlEdit = "/TReservationInfo/edit/{$data['room_id']}/{$date}/{$mealType}";
                            echo $this->Html->link('編集', $urlEdit, [
                                'class' => 'btn btn-primary btn-sm' . ($isDisabled ? ' disabled' : ''),
                                'disabled' => $isDisabled ? 'disabled' : false
                            ]);
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

    <?php if (!$isDisabled): ?>
        <button class="btn btn-primary" onclick="location.href='<?= $this->Url->build(['action' => 'add', '?' => ['date' => $date]]) ?>'">追加する</button>
    <?php else: ?>
        <button class="btn btn-secondary" disabled>追加不可（当日から1ヶ月後までは登録不可）</button>
    <?php endif; ?>
</div>
