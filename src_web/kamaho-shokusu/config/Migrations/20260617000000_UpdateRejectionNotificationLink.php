<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class UpdateRejectionNotificationLink extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE t_notification
             SET c_link = '/TReservationInfo/my-actual-meal'
             WHERE c_notification_type = 'approval_rejected'
               AND c_link = '/TReservationInfo'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE t_notification
             SET c_link = '/TReservationInfo'
             WHERE c_notification_type = 'approval_rejected'
               AND c_link = '/TReservationInfo/my-actual-meal'"
        );
    }
}
