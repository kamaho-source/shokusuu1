<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TReservationInfo $tReservationInfo
 * @var array $rooms
 */
?>
<div class="row">
    <aside class="column">
        <div class="side-nav">
            <h4 class="heading"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('List T Reservation Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="tReservationInfo form content">
            <?= $this->Form->create($tReservationInfo) ?>
            <fieldset>
                <legend><?= __('Add T Reservation Info') ?></legend>
                <?php

                echo $this->Form->control('d_reservation_date', [
                    'type' => 'hidden', // ユーザーには見えないように隠しフィールド
                    'id' => 'd_reservation_date', // JavaScriptで操作できるようにIDを設定
                ]);


                echo $this->Form->control('i_id_room', [
                    'type'=>'select',
                    'label' => '部屋名',
                    'options' => $rooms,
                    'empty' => '-- 部屋を選択 --'
                ]);

                $reservationTypes = [
                    1 => '朝',
                    2 => '昼',
                    3 => '夜'
                ];

                // フォームで予約タイプを表示
                echo $this->Form->control('c_reservation_type', [
                    'label' => '予約タイプ',
                    'type' => 'select',
                    'options' => $reservationTypes,
                    'empty' => '-- 予約タイプを選択 --' // 空の選択肢を追加（オプション）
                ]);

                echo $this->Form->control('i_taberu_ninzuu');
                echo $this->Form->control('i_tabenai_ninzuu');


                ?>
            </fieldset>
            <?= $this->Form->button(__('Submit')) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
