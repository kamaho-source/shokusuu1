<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\TReservationInfo> $tReservationInfo
 */
?>
<div class="tReservationInfo index content">
    <?= $this->Html->link(__('New T Reservation Info'), ['action' => 'add'], ['class' => 'button float-right']) ?>
    <h3><?= __('T Reservation Info') ?></h3>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('d_reservation_date') ?></th>
                    <th><?= $this->Paginator->sort('i_id_room') ?></th>
                    <th><?= $this->Paginator->sort('c_reservation_type') ?></th>
                    <th><?= $this->Paginator->sort('i_taberu_ninzuu') ?></th>
                    <th><?= $this->Paginator->sort('i_tabenai_ninzuu') ?></th>
                    <th><?= $this->Paginator->sort('dt_create') ?></th>
                    <th><?= $this->Paginator->sort('c_create_user') ?></th>
                    <th><?= $this->Paginator->sort('dt_update') ?></th>
                    <th><?= $this->Paginator->sort('c_update_user') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tReservationInfo as $tReservationInfo): ?>
                <tr>
                    <td><?= h($tReservationInfo->d_reservation_date) ?></td>
                    <td><?= $this->Number->format($tReservationInfo->i_id_room) ?></td>
                    <td><?= $this->Number->format($tReservationInfo->c_reservation_type) ?></td>
                    <td><?= $tReservationInfo->i_taberu_ninzuu === null ? '' : $this->Number->format($tReservationInfo->i_taberu_ninzuu) ?></td>
                    <td><?= $tReservationInfo->i_tabenai_ninzuu === null ? '' : $this->Number->format($tReservationInfo->i_tabenai_ninzuu) ?></td>
                    <td><?= h($tReservationInfo->dt_create) ?></td>
                    <td><?= h($tReservationInfo->c_create_user) ?></td>
                    <td><?= h($tReservationInfo->dt_update) ?></td>
                    <td><?= h($tReservationInfo->c_update_user) ?></td>
                    <td class="actions">
                        <?= $this->Html->link(__('View'), ['action' => 'view', $tReservationInfo->d_reservation_date]) ?>
                        <?= $this->Html->link(__('Edit'), ['action' => 'edit', $tReservationInfo->d_reservation_date]) ?>
                        <?= $this->Form->postLink(__('Delete'), ['action' => 'delete', $tReservationInfo->d_reservation_date], ['confirm' => __('Are you sure you want to delete # {0}?', $tReservationInfo->d_reservation_date)]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
