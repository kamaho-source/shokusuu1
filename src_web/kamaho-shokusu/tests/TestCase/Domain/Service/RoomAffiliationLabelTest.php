<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\RoomAffiliationLabel;
use PHPUnit\Framework\TestCase;

/**
 * 利用者一覧の所属部屋の表示（旧 RoomServiceTest::buildAffiliationLabel を引き継ぐ）。
 */
class RoomAffiliationLabelTest extends TestCase
{
    public function testBuild(): void
    {
        $this->assertSame('未所属', RoomAffiliationLabel::build([], 3));
        $this->assertSame('部屋A, 部屋B', RoomAffiliationLabel::build(['部屋A', '部屋B'], 3));
        $this->assertSame('全部屋所属', RoomAffiliationLabel::build(['部屋A', '部屋B', '部屋C'], 3));
        $this->assertSame('部屋A, 部屋B', RoomAffiliationLabel::build(['部屋A', '部屋A', '部屋B'], 3), '同じ部屋が重なっても全部屋所属にしない');
        $this->assertSame('部屋A', RoomAffiliationLabel::build(['部屋A'], 0), '部屋が0なら全部屋所属にしない');
    }
}
