<div class="container">
    <h1>部屋詳細</h1>
    <h2>部屋ID: <?= h($roomId) ?></h2>
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

    <h3>食べる人のリスト:</h3>
    <?php if (!empty($userNames)): ?>
        <ul>
            <?php foreach ($userNames as $userName): ?>
                <li><?= h($userName) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>この食事タイプで食べる人はいません。</p>
    <?php endif; ?>


    <!-- 戻るボタン -->
    <div>
        <?= $this->Html->link(__('戻る'), ['action' => 'view', '?' => ['date' => $date]], ['class' => 'btn btn-secondary']) ?>
    </div>
</div>
