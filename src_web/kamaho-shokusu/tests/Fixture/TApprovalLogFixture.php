<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TApprovalLogFixture extends TestFixture
{
    public string $table = 't_approval_log';

    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
