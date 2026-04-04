<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TApprovalLogFixture extends TestFixture
{
    public string $table = 't_approval_log';

    public function init(): void
    {
        $this->records = [
            [
                'i_id_approval'      => 1,
                'i_id_user'          => 1,
                'd_reservation_date' => '2026-05-01',
                'i_id_room'          => 1,
                'i_reservation_type' => 1,
                'i_approval_status'  => 1,
                'i_approver_id'      => 2,
                'c_reject_reason'    => null,
                'dt_create'          => '2026-05-01 10:00:00',
            ],
        ];
        parent::init();
    }
}
