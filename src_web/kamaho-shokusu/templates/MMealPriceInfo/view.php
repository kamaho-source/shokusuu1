<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Domain\Entity\MealPrice $mMealPriceInfo
 */
$this->assign('title', __('食事単価情報表示'));
?>
<div class="row">
    <aside class="col-md-3">
        <!-- サイドバー -->
        <div class="list-group">
            <h4 class="mb-3"><?= __('操作') ?></h4>
            <?= $this->Html->link(__('編集'), ['action' => 'edit', $mMealPriceInfo->id], ['class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Form->postLink(__('削除'), ['action' => 'delete', $mMealPriceInfo->id], ['confirm' => __('本当に # {0} を削除しますか？', $mMealPriceInfo->id), 'class' => 'list-group-item list-group-item-action text-danger']) ?>
            <?= $this->Html->link(__('一覧へ戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
            <?= $this->Html->link(__('新しい食事単価情報を追加'), ['action' => 'add'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <!-- メインコンテンツ -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title"><?= h($mMealPriceInfo->id) ?> <?= __('詳細') ?></h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tr>
                        <th><?= __('対象年度') ?></th>
                        <td><?= $mMealPriceInfo->year === null ? '' : h($mMealPriceInfo->year) ?></td>
                    </tr>
                    <tr>
                        <th><?= __('朝食単価') ?></th>
                        <td><?= $mMealPriceInfo->morning === null ? '' : $this->Number->format($mMealPriceInfo->morning) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('昼食単価') ?></th>
                        <td><?= $mMealPriceInfo->lunch === null ? '' : $this->Number->format($mMealPriceInfo->lunch) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('夕食単価') ?></th>
                        <td><?= $mMealPriceInfo->dinner === null ? '' : $this->Number->format($mMealPriceInfo->dinner) ?>円</td>
                    </tr>
                    <tr>
                        <th><?= __('弁当単価') ?></th>
                        <td><?= $mMealPriceInfo->bento === null ? '' : $this->Number->format($mMealPriceInfo->bento) ?>円</td>
                    </tr>

                </table>
                </div>
            </div>
        </div>
    </div>
</div>
