<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Domain\Entity\MealPrice> $mMealPriceInfo
 */

$this->assign('title', __('食事単価情報一覧'));
echo $this->Html->css('pages/m_meal_price_info_index.css');
?>

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
                <th class="header-id" style="width: 5%">ID</th>
                <th class="header-fiscal-year" style="width: 15%">対象年度</th>
                <th class="header-morning-price" style="width: 15%">朝食単価</th>
                <th class="header-lunch-price" style="width: 15%">昼食単価</th>
                <th class="header-dinner-price" style="width: 15%">夕食単価</th>
                <th class="header-bento-price" style="width: 15%">弁当単価</th>
                <th class="actions text-center" style="width: 20%">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mMealPriceInfo as $mMealPrice): ?>
                <tr>
                    <td class="header-id"><?= $this->Number->format($mMealPrice->id) ?></td>
                    <td class="header-fiscal-year"><?= $mMealPrice->year === null ? '' : h($mMealPrice->year) ?></td> <!-- カンマなし -->
                    <td class="header-morning-price"><?= $mMealPrice->morning === null ? '' : $this->Number->format($mMealPrice->morning) ?></td>
                    <td class="header-lunch-price"><?= $mMealPrice->lunch === null ? '' : $this->Number->format($mMealPrice->lunch) ?></td>
                    <td class="header-dinner-price"><?= $mMealPrice->dinner === null ? '' : $this->Number->format($mMealPrice->dinner) ?></td>
                    <td class="header-bento-price"><?= $mMealPrice->bento === null ? '' : $this->Number->format($mMealPrice->bento) ?></td>
                    <td class="actions text-center">
                        <?= $this->Html->link('詳細', ['action' => 'view', $mMealPrice->id], ['class' => 'btn btn-info btn-sm']) ?>
                        <?= $this->Html->link('編集', ['action' => 'edit', $mMealPrice->id], ['class' => 'btn btn-warning btn-sm']) ?>
                        <?= $this->Form->postLink('削除', ['action' => 'delete', $mMealPrice->id], [
                            'confirm' => '本当に削除してもよろしいですか？ # ' . $mMealPrice->id,
                            'class' => 'btn btn-danger btn-sm'
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginator">
<!--        <ul class="pagination justify-content-center">-->
<!--            --><?php //= $this->Paginator->first('<< 最初', ['class' => 'page-item']) ?>
<!--            --><?php //= $this->Paginator->prev('< 前', ['class' => 'page-item']) ?>
<!--            --><?php //= $this->Paginator->numbers(['class' => 'page-item']) ?>
<!--            --><?php //= $this->Paginator->next('次 >', ['class' => 'page-item']) ?>
<!--            --><?php //= $this->Paginator->last('最後 >>', ['class' => 'page-item']) ?>
<!--        </ul>-->
        <p class="text-muted text-center">
            <?= h(sprintf('全%d件', count($mMealPriceInfo))) ?>
        </p>
    </div>
</div>
