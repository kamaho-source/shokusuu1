<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $mMealPriceInfo
 */
$this->assign('title', __('食事単価情報の編集'));
$this->Html->script('realtime-validation.js', ['block' => true]);
?>
<div class="container">
    <div class="row my-4">
        <!-- サイドナビ（アクションメニュー） -->
        <aside class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= __('Actions') ?></h5>
                    <?= $this->Form->postLink(
                        '削除',
                        ['action' => 'delete', $mMealPriceInfo->i_id],
                        [
                            'confirm' => __('本当に削除してもよろしいですか？ # {0}', $mMealPriceInfo->i_id),
                            'class' => 'btn btn-danger w-100 mb-2'
                        ]
                    ) ?>
                    <?= $this->Html->link(
                        '一覧に戻る',
                        ['action' => 'index'],
                        ['class' => 'btn btn-secondary w-100']
                    ) ?>
                </div>
            </div>
        </aside>

        <!-- 編集フォーム -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?= __('食事単価情報の編集') ?></h5>

                    <?= $this->Form->create($mMealPriceInfo, ['id' => 'meal-price-form']) ?>
                    <fieldset>
                        <legend class="mb-4"><?= __('必要な情報を変更してください') ?></legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <?= $this->Form->control('i_fiscal_year', [
                                    'label' => '年度',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 2025',
                                    'data-validate' => 'required year',
                                ]) ?>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_morning_price', [
                                    'label' => '朝食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 500',
                                    'data-validate' => 'required non-negative',
                                    'data-msg-non-negative' => '0 以上の整数（円）を入力してください。',
                                ]) ?>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_lunch_price', [
                                    'label' => '昼食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 800',
                                    'data-validate' => 'required non-negative',
                                    'data-msg-non-negative' => '0 以上の整数（円）を入力してください。',
                                ]) ?>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_dinner_price', [
                                    'label' => '夕食単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 1000',
                                    'data-validate' => 'required non-negative',
                                    'data-msg-non-negative' => '0 以上の整数（円）を入力してください。',
                                ]) ?>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-6">
                                <?= $this->Form->control('i_bento_price', [
                                    'label' => '弁当単価（円）',
                                    'class' => 'form-control',
                                    'placeholder' => '例: 700',
                                    'data-validate' => 'required non-negative',
                                    'data-msg-non-negative' => '0 以上の整数（円）を入力してください。',
                                ]) ?>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </fieldset>
                    <div class="text-end mt-4">
                        <?= $this->Form->button('保存', ['class' => 'btn btn-primary', 'id' => 'submit-btn']) ?>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initRealtimeValidation('meal-price-form', 'submit-btn');
});
</script>
