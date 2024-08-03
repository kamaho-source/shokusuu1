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
            <?= $this->Html->link(__('Edit T Reservation Info'), ['action' => 'edit', $tReservationInfo->d_reservation_date], ['class' => 'side-nav-item']) ?>
            <?= $this->Form->postLink(__('Delete T Reservation Info'), ['action' => 'delete', $tReservationInfo->d_reservation_date], ['confirm' => __('Are you sure you want to delete # {0}?', $tReservationInfo->d_reservation_date), 'class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('List T Reservation Info'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
            <?= $this->Html->link(__('New T Reservation Info'), ['action' => 'add'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column column-80">
        <div class="tReservationInfo view content">
            <h3><?= h($tReservationInfo->Array) ?></h3>
            <table>
                <tr>
                    <th><?= __('C Create User') ?></th>
                    <td><?= h($tReservationInfo->c_create_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Update User') ?></th>
                    <td><?= h($tReservationInfo->c_update_user) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Id Room') ?></th>
                    <td><?= $this->Number->format($tReservationInfo->i_id_room) ?></td>
                </tr>
                <tr>
                    <th><?= __('C Reservation Type') ?></th>
                    <td><?= $this->Number->format($tReservationInfo->c_reservation_type) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Taberu Ninzuu') ?></th>
                    <td><?= $tReservationInfo->i_taberu_ninzuu === null ? '' : $this->Number->format($tReservationInfo->i_taberu_ninzuu) ?></td>
                </tr>
                <tr>
                    <th><?= __('I Tabenai Ninzuu') ?></th>
                    <td><?= $tReservationInfo->i_tabenai_ninzuu === null ? '' : $this->Number->format($tReservationInfo->i_tabenai_ninzuu) ?></td>
                </tr>
                <tr>
                    <th><?= __('D Reservation Date') ?></th>
                    <td><?= h($tReservationInfo->d_reservation_date) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Create') ?></th>
                    <td><?= h($tReservationInfo->dt_create) ?></td>
                </tr>
                <tr>
                    <th><?= __('Dt Update') ?></th>
                    <td><?= h($tReservationInfo->dt_update) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
