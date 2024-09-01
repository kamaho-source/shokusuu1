<?php
/**
 * @var \App\View\AppView $this
 * @var \DateTime[] $dates
 * @var array $rooms
 */
?>
<div class="row">
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('食数予約一覧に戻る'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h3><?= __('週の一括予約') ?></h3>
            </div>
            <div class="card-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'bulkAddSubmit']]) ?>
                <div class="form-group">
                    <?= $this->Form->control('i_id_room', [
                        'type' => 'select',
                        'label' => '部屋名',
                        'options' => $rooms,
                        'empty' => '-- 部屋を選択 --',
                        'class' => 'form-control'
                    ]) ?>
                </div>
                <div class="form-group">
                    <label>月曜日の入力を火曜日〜金曜日にコピーする</label>
                    <?= $this->Form->checkbox('copy_to_all', ['id' => 'copy_to_all']) ?>
                </div>

                <?php foreach ($dates as $index => $date): ?>
                    <h5><?= $date->format('Y-m-d') ?> (<?= $date->format('l') ?>)</h5>
                    <div class="form-group">
                        <label><?= __('朝食べる人数') ?></label>
                        <input type="number" id="morning_taberu_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][morning][taberu]" class="form-control">
                        <label><?= __('朝食べない人数') ?></label>
                        <input type="number" id="morning_tabenai_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][morning][tabenai]" class="form-control">
                    </div>
                    <div class="form-group">
                        <label><?= __('昼食べる人数') ?></label>
                        <input type="number" id="noon_taberu_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][noon][taberu]" class="form-control">
                        <label><?= __('昼食べない人数') ?></label>
                        <input type="number" id="noon_tabenai_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][noon][tabenai]" class="form-control">
                    </div>
                    <div class="form-group">
                        <label><?= __('夕食べる人数') ?></label>
                        <input type="number" id="night_taberu_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][night][taberu]" class="form-control">
                        <label><?= __('夕食べない人数') ?></label>
                        <input type="number" id="night_tabenai_<?= $index ?>" name="data[<?= $date->format('Y-m-d') ?>][night][tabenai]" class="form-control">
                    </div>
                <?php endforeach; ?>

                <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript to handle copying values -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // チェックボックスのイベントリスナー
        document.getElementById('copy_to_all').addEventListener('change', function () {
            if (this.checked) {
                // 月曜日の値を取得
                var mondayMorningTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][morning][taberu]"]').value;
                var mondayMorningTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][morning][tabenai]"]').value;
                var mondayNoonTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][noon][taberu]"]').value;
                var mondayNoonTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][noon][tabenai]"]').value;
                var mondayNightTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][night][taberu]"]').value;
                var mondayNightTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][night][tabenai]"]').value;

                // 他の曜日にコピー
                <?php foreach (array_slice($dates, 1) as $date): ?>
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][morning][taberu]"]').value = mondayMorningTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][morning][tabenai]"]').value = mondayMorningTabenai;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][noon][taberu]"]').value = mondayNoonTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][noon][tabenai]"]').value = mondayNoonTabenai;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][night][taberu]"]').value = mondayNightTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][night][tabenai]"]').value = mondayNightTabenai;
                <?php endforeach; ?>
            } else {
                // チェックを外した場合、コピーされた値をクリアするか、何もしないか選択できます
                // ここでは何もしない
            }
        });
    });
</script>

