<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\TReservationInfo $tReservationInfo
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
                    echo $this->Form->control('i_taberu_ninzuu');
                    echo $this->Form->control('i_tabenai_ninzuu');
                    echo $this->Form->control('dt_create', ['empty' => true]);
                    echo $this->Form->control('c_create_user');
                    echo $this->Form->control('dt_update', ['empty' => true]);
                    echo $this->Form->control('c_update_user');
                ?>
            </fieldset>
            <?= $this->Form->button(__('Submit')) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
