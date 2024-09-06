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

                    <!-- 朝食 -->
                    <div class="form-group">
                        <label><?= __('朝食') ?></label>
                        <input type="hidden" name="data[<?= $date->format('Y-m-d') ?>][morning][reservation_type]" value="1">
                        <label><?= __('食べる人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][morning][taberu]" class="form-control">
                        <label><?= __('食べない人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][morning][tabenai]" class="form-control">
                    </div>

                    <!-- 昼食 -->
                    <div class="form-group">
                        <label><?= __('昼食') ?></label>
                        <input type="hidden" name="data[<?= $date->format('Y-m-d') ?>][noon][reservation_type]" value="2">
                        <label><?= __('食べる人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][noon][taberu]" class="form-control">
                        <label><?= __('食べない人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][noon][tabenai]" class="form-control">
                    </div>

                    <!-- 夕食 -->
                    <div class="form-group">
                        <label><?= __('夕食') ?></label>
                        <input type="hidden" name="data[<?= $date->format('Y-m-d') ?>][night][reservation_type]" value="3">
                        <label><?= __('食べる人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][night][taberu]" class="form-control">
                        <label><?= __('食べない人数') ?></label>
                        <input type="number" name="data[<?= $date->format('Y-m-d') ?>][night][tabenai]" class="form-control">
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
        document.getElementById('copy_to_all').addEventListener('change', function () {
            if (this.checked) {
                var mondayMorningTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][morning][taberu]"]').value;
                var mondayMorningTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][morning][tabenai]"]').value;
                var mondayNoonTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][noon][taberu]"]').value;
                var mondayNoonTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][noon][tabenai]"]').value;
                var mondayNightTaberu = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][night][taberu]"]').value;
                var mondayNightTabenai = document.querySelector('input[name="data[<?= $dates[0]->format('Y-m-d') ?>][night][tabenai]"]').value;

                <?php foreach (array_slice($dates, 1) as $date): ?>
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][morning][taberu]"]').value = mondayMorningTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][morning][tabenai]"]').value = mondayMorningTabenai;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][noon][taberu]"]').value = mondayNoonTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][noon][tabenai]"]').value = mondayNoonTabenai;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][night][taberu]"]').value = mondayNightTaberu;
                document.querySelector('input[name="data[<?= $date->format('Y-m-d') ?>][night][tabenai]"]').value = mondayNightTabenai;
                <?php endforeach; ?>
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        // フォームの送信イベントをキャプチャ
        document.querySelector('form').addEventListener('submit', function (event) {
            var valid = true; // フォームが有効かどうかを追跡するフラグ
            var alertMessage = ''; // アラートメッセージの初期化

            // 全ての朝、昼、夜の入力フィールドをチェック
            <?php foreach ($dates as $index => $date): ?>
            var morningTaberu = document.getElementById('morning_taberu_<?= $index ?>').value;
            var morningTabenai = document.getElementById('morning_tabenai_<?= $index ?>').value;
            var noonTaberu = document.getElementById('noon_taberu_<?= $index ?>').value;
            var noonTabenai = document.getElementById('noon_tabenai_<?= $index ?>').value;
            var nightTaberu = document.getElementById('night_taberu_<?= $index ?>').value;
            var nightTabenai = document.getElementById('night_tabenai_<?= $index ?>').value;

            // 未入力チェック
            if (!morningTaberu || !morningTabenai || !noonTaberu || !noonTabenai || !nightTaberu || !nightTabenai) {
                alertMessage += '<?= $date->format('Y-m-d') ?> の入力に不足があります。\n';
                valid = false; // 無効フラグをセット
            }
            <?php endforeach; ?>

            // 未入力がある場合はアラートを表示してフォーム送信をキャンセル
            if (!valid) {
                alert(alertMessage);
                event.preventDefault(); // フォーム送信をキャンセル
            }
        });
    });

</script>
