<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class MRoomTransferScheduleFixture extends TestFixture
{
    public string $table = 'm_room_transfer_schedule';

    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
