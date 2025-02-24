<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\Cake\Datasource\EntityInterface> $mMealPriceInfo
 */
?>
<style>
    /* 列ごとの背景色を定義 */
    .header-id { background-color: #f8d7da; } /* 赤系 */
    .header-fiscal-year { background-color: #d1ecf1; } /* 青系 */
    .header-morning-price { background-color: #d4edda; } /* 緑系 */
    .header-lunch-price { background-color: #fff3cd; } /* 黄系 */
    .header-dinner-price { background-color: #f0e68c; } /* ゴールド */
    .header-bento-price { background-color: #f8d7da; } /* 赤系 */
    table td, table th {
        white-space: nowrap; /* テキストを折り返さない */
    }
</style>

<div class="mMealPriceInfo index content">
    <?= $this->Html->link(
        '新規食事単価情報',
        ['action' => 'add'],
        ['class' => 'btn btn-primary float-end']
    ) ?>
    <h3 class="my-3">食事単価情報</h3>

    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead class="table-light">
            <tr>
                <th class="header-id" style="width: 5%"><?= $this->Paginator->sort('i_id', 'ID') ?></th>
                <th class="header-fiscal-year" style="width: 15%"><?= $this->Paginator->sort('i_fiscal_year', '対象年度') ?></th>
                <th class="header-morning-price" style="width: 15%"><?= $this->Paginator->sort('i_morning_price', '朝食単価') ?></th>
                <th class="header-lunch-price" style="width: 15%"><?= $this->Paginator->sort('i_lunch_price', '昼食単価') ?></th>
                <th class="header-dinner-price" style="width: 15%"><?= $this->Paginator->sort('i_dinner_price', '夕食単価') ?></th>
                <th class="header-bento-price" style="width: 15%"><?= $this->Paginator->sort('i_bento_price', '弁当単価') ?></th>
                <th class="actions text-center" style="width: 20%">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mMealPriceInfo as $mMealPrice): ?>
                <tr>
                    <td class="header-id"><?= $this->Number->format($mMealPrice->i_id) ?></td>
                    <td class="header-fiscal-year"><?= $mMealPrice->i_fiscal_year === null ? '' : h($mMealPrice->i_fiscal_year) ?></td> <!-- カンマなし -->
                    <td class="header-morning-price"><?= $mMealPrice->i_morning_price === null ? '' : $this->Number->format($mMealPrice->i_morning_price) ?></td>
                    <td class="header-lunch-price"><?= $mMealPrice->i_lunch_price === null ? '' : $this->Number->format($mMealPrice->i_lunch_price) ?></td>
                    <td class="header-dinner-price"><?= $mMealPrice->i_dinner_price === null ? '' : $this->Number->format($mMealPrice->i_dinner_price) ?></td>
                    <td class="header-bento-price"><?= $mMealPrice->i_bento_price === null ? '' : $this->Number->format($mMealPrice->i_bento_price) ?></td>
                    <td class="actions text-center">
                        <?= $this->Html->link('詳細', ['action' => 'view', $mMealPrice->i_id], ['class' => 'btn btn-info btn-sm']) ?>
                        <?= $this->Html->link('編集', ['action' => 'edit', $mMealPrice->i_id], ['class' => 'btn btn-warning btn-sm']) ?>
                        <?= $this->Form->postLink('削除', ['action' => 'delete', $mMealPrice->i_id], [
                            'confirm' => '本当に削除してもよろしいですか？ # ' . $mMealPrice->i_id,
                            'class' => 'btn btn-danger btn-sm'
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginator">
        <ul class="pagination justify-content-center">
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
