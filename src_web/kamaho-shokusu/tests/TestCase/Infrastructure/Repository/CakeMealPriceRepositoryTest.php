<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Repository;

use App\Infrastructure\Repository\CakeMealPriceRepository;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 単価マスタの保存先（CakeMealPriceRepository）。
 */
class CakeMealPriceRepositoryTest extends TestCase
{
    protected array $fixtures = ['app.MMealPriceInfo'];

    /** 以前の登録で同じ年が2行あるときは、ID の小さい方を使う（毎回同じ単価で控除表を出す） */
    public function testFindByYear_prefersSmallestIdWhenDuplicated(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_meal_price_info', ['i_id' => 9, 'i_fiscal_year' => 2030, 'i_morning_price' => 9]);
        $db->insert('m_meal_price_info', ['i_id' => 5, 'i_fiscal_year' => 2030, 'i_morning_price' => 5]);
        $repo = new CakeMealPriceRepository();

        $this->assertSame(5, $repo->findByYear(2030)?->morning);
        $this->assertNull($repo->findByYear(2031));
        $this->assertTrue($repo->yearExists(2030));
        $this->assertTrue($repo->yearExists(2030, 5), 'ID 5 以外にも 2030 年がある');
        $this->assertFalse($repo->yearExists(2024, 1), 'ID 1 だけが 2024 年');
    }
}
