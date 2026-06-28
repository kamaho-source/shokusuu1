<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * m_room_transfer_schedule テーブル用フィクスチャ。
 *
 * テスト用に空レコードでテーブルを準備する。
 */
class MRoomTransferScheduleFixture extends TestFixture
{
    public string $table = 'm_room_transfer_schedule';

    /** @throws \Exception */
    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
