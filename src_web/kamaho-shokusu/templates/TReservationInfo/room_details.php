<div class="container">
    <h1>部屋詳細</h1>
    <h2>部屋名: <?= h($room->c_room_name) ?></h2> <!-- 部屋名 -->
    <p>日付: <?= h($date) ?></p>
    <p>食事タイプ:
        <?php
        switch ($mealType) {
            case 1:
                echo '朝';
                break;
            case 2:
                echo '昼';
                break;
            case 3:
                echo '夜';
                break;
            default:
                echo '不明';
                break;
        }
        ?>
    </p>

    <!-- 食べる人のリスト -->
    <h3>食べる人のリスト:</h3>
    <?php if (!empty($eatUsers)): ?>
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>利用者名</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($eatUsers as $userName): ?>
                <tr>
                    <td><?= h($userName) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>この食事タイプで食べる人はいません。</p>
    <?php endif; ?>

    <!-- 食べない人のリスト -->
    <h3>食べない人のリスト:</h3>
    <?php if (!empty($noEatUsers)): ?>

        <table class="table table-bordered">
            <thead>
            <tr>
                <th>利用者名</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($noEatUsers as $userName): ?>
                <tr>
                    <td><?= h($userName) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>この食事タイプで食べない人はいません。</p>
    <?php endif; ?>

    <!-- 戻るボタン -->
    <div class="mt-3">
        <?= $this->Html->link(__('戻る'), ['action' => 'view', '?' => ['date' => $date]], ['class' => 'btn btn-secondary']) ?>
    </div>
</div>
