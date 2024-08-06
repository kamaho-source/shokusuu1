<table class="table">
    <thead class="thead-light">
    <tr>
        <th>部屋名</th>
        <th>予約状況</th>
        <th>食べる人数</th>
        <th>食べない人数</th>
        <th>合計人数</th>

    </tr>
    </thead>
    <tbody>
    <?php foreach ($roomInfos as $roomInfo): ?>  <!-- Loop over the data -->
        <?php $rowClass = ($roomInfo->c_reservation_type === SOME_VALUE)? 'table-danger' : 'table-success'; ?> <!-- Replace SOME_VALUE with the value that means 'occupied' -->
        <tr class="<?= $rowClass ?>">
            <td><?= $roomInfo->i_id_room ?></td>
            <td><?= $roomInfo->c_reservation_type ?></td>
            <td><?= $roomInfo->i_taberu_ninzuu ?></td>
            <td><?= $roomInfo->i_tabenai_ninzuu ?></td>
            <td><?= $roomInfo->i_taberu_ninzuu + $roomInfo->i_tabenai_ninzuu ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
