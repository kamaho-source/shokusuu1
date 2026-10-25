<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\MMealPriceInfo $mMealPriceInfo
 */
$this->assign('title', __('食事単価情報表示'));
?>
<div class="row">
    <?= $this->element('side_actions', [
        'items' => [
            ['tone' => 'edit', 'label' => '食事単価を編集する', 'url' => ['action' => 'edit', $mMealPriceInfo->i_id], 'primary' => true],
            ['tone' => 'back', 'label' => '食事単価一覧へ戻る', 'url' => ['action' => 'index']],
            ['tone' => 'add', 'label' => '新しい食事単価を追加', 'url' => ['action' => 'add']],
            ['tone' => 'delete', 'label' => '削除する', 'url' => ['action' => 'delete', $mMealPriceInfo->i_id], 'confirmTarget' => $mMealPriceInfo->i_fiscal_year . '年の食事単価'],
        ],
    ]) ?>
    <div class="col-md-9">
        <!-- メインコンテンツ -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title"><?= h($mMealPriceInfo->i_id) ?> <?= __('詳細') ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tr>
                        <th><?= __('対象年度') ?></th>
                        <td><?= $mMealPriceInfo->i_fiscal_year === null ? '' : h($mMealPriceInfo->i_fiscal_year) ?></td>
                    </tr>
                    <tr>
                        <th><?= __('朝食単価') ?></th>
                        <td><?= $mMealPriceInfo->i_morning_price === null ? '' : $this->Number->format($mMealPriceInfo->i_morning_price) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('昼食単価') ?></th>
                        <td><?= $mMealPriceInfo->i_lunch_price === null ? '' : $this->Number->format($mMealPriceInfo->i_lunch_price) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('夕食単価') ?></th>
                        <td><?= $mMealPriceInfo->i_dinner_price === null ? '' : $this->Number->format($mMealPriceInfo->i_dinner_price) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('弁当単価') ?></th>
                        <td><?= $mMealPriceInfo->i_bento_price === null ? '' : $this->Number->format($mMealPriceInfo->i_bento_price) ?>円</td>
                    </tr>

                </table>
                </div>
            </div>
        </div>
    </div>
</div>
