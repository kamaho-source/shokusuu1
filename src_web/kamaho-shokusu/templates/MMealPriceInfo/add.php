<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $mMealPriceInfo
 */
?>
<div class="container">
    <div class="row my-4">
        <!-- サイドナビ（戻るボタン） -->
        <aside class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= __('Actions') ?></h5>
                    <?= $this->Html->link(
                        '食事単価一覧に戻る',
                        ['action' => 'index'],
                        ['class' => 'btn btn-secondary w-100']
                    ) ?>
                </div>
            </div>
        </aside>

        <div class="col-md-9">
            <!-- フォームコンテナ -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= __('新しい食事単価情報を追加') ?></h5>

                    <!-- フォーム開始 -->
                    <?= $this->Form->create($mMealPriceInfo) ?>
                    <fieldset>
                        <legend class="mb-4"><?= __('必要な情報を入力してください') ?></legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <?= $this->Form->control('i_fiscal_year', [
                                    'label' => '年度',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 2023'
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_morning_price', [
                                    'label' => '朝食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 500'
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_lunch_price', [
                                    'label' => '昼食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 800'
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_dinner_price', [
                                    'label' => '夕食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 1000'
                                ]) ?>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_bento_price', [
                                    'label' => '弁当単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 700'
                                ]) ?>
                            </div>
                        </div>
                    </fieldset>
                    <div class="text-end mt-4">
                        <?= $this->Form->button('登録', ['class' => 'btn btn-primary']) ?>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div>
