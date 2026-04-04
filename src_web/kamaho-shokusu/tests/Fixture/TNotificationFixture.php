<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TNotificationFixture extends TestFixture
{
    public string $table = 't_notification';

    public function init(): void
    {
        $this->records = [
            [
                'i_id_notification'   => 1,
                'i_id_user'           => 1,
                'c_notification_type' => 'approval_rejected',
                'c_title'             => '予約が差し戻されました',
                'c_message'           => 'テスト差し戻しメッセージ',
                'c_link'              => '/TReservationInfo',
                'i_is_read'           => 0,
                'dt_read'             => null,
                'dt_create'           => '2026-05-01 10:00:00',
            ],
            [
                'i_id_notification'   => 2,
                'i_id_user'           => 1,
                'c_notification_type' => 'approval_rejected',
                'c_title'             => '既読通知',
                'c_message'           => '既読済みのメッセージ',
                'c_link'              => '/TReservationInfo',
                'i_is_read'           => 1,
                'dt_read'             => '2026-05-02 09:00:00',
                'dt_create'           => '2026-05-01 11:00:00',
            ],
        ];
        parent::init();
    }
}
