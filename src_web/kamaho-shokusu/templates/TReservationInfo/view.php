<div class="container">
    <h1>予約詳細 (<?= h($date) ?>)</h1>
    <table class="table">
        <thead class="thead-light">
        <tr>
            <th>部屋名</th>
            <th>予約タイプ</th>
            <th>食べる人数</th>
            <th>食べない人数</th>
            <th>合計人数</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (['朝', '昼', '夜'] as $type): ?>
            <?php if (!empty($groupedRoomInfos[$type])): ?>
                <tr>
                    <td colspan="5" class="table-primary"><?= h($type) ?></td>
                </tr>
                <?php foreach ($groupedRoomInfos[$type] as $roomInfo): ?>  <!-- 取得したデータをループで表示 -->
                    <tr>
                        <td><?= h($roomInfo['room_name']) ?></td> <!-- 部屋名 -->
                        <td><?= h($roomInfo['reservation_type']) ?></td> <!-- 予約タイプ -->
                        <td><?= h($roomInfo['taberu_ninzuu']) ?></td> <!-- 食べる人数 -->
                        <td><?= h($roomInfo['tabenai_ninzuu']) ?></td> <!-- 食べない人数 -->
                        <td><?= h($roomInfo['taberu_ninzuu'] + $roomInfo['tabenai_ninzuu']) ?></td> <!-- 合計人数 -->
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 追加するボタン -->
    <button class="btn btn-primary" onclick="location.href='<?= $this->Url->build(['action' => 'add', '?' => ['date' => $date]]) ?>'">
        追加する
    </button>
    <button class="btn btn-primary" onclick="location.href='<?= $this->Url->build(['action' => 'edit', '?' => ['date' => $date]]) ?>'">
        編集する
</div>
