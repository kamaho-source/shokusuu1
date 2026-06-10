<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class MNoticeFixture extends TestFixture
{
    public string $table = 'm_notice';

    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
