<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable;
use App\Infrastructure\Repository\CakeReservationRepository;
use Cake\Database\Exception\QueryException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use PDOException;

/**
 * 予約リポジトリの、保存時の DB の失敗の扱いのテスト。
 */
class CakeReservationRepositoryTest extends TestCase
{
    /**
     * 保存で、指定した errorInfo の DB エラーが起きるリポジトリ。
     *
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    private function repositoryWhoseInsertFails(array $errorInfo): CakeReservationRepository
    {
        $pdo = new PDOException('db error');
        $pdo->errorInfo = $errorInfo;
        $table = $this->getMockBuilder(TIndividualReservationInfoTable::class)
            ->setConstructorArgs([['alias' => 'TIndividualReservationInfo', 'connection' => ConnectionManager::get('test')]])
            ->onlyMethods(['saveManyOrFail'])
            ->getMock();
        $table->method('saveManyOrFail')
            ->willThrowException(new QueryException('INSERT INTO t_individual_reservation_info ...', $pdo));

        return new CakeReservationRepository($table);
    }

    /** @return list<MealReservation> */
    private static function rows(): array
    {
        return [new MealReservation(9101, '2026-12-01', 910, 1, 1, null)];
    }

    /**
     * 同時に同じ予約が送られて主キーが重なったら、SQL を含まない競合（409）にする。
     */
    public function testAddMany_duplicateKey_becomesConflictWithoutSql(): void
    {
        foreach ([
            'MySQL'  => ['23000', 1062, "Duplicate entry '9101-2026-12-01-1-910' for key 'PRIMARY'"],
            'SQLite' => ['23000', 19, 'UNIQUE constraint failed: t_individual_reservation_info.i_id_user'],
        ] as $driver => $errorInfo) {
            try {
                $this->repositoryWhoseInsertFails($errorInfo)->addMany(self::rows(), 'tester', false);
                $this->fail($driver . ': 例外が出ていない');
            } catch (ConflictException $e) {
                $this->assertSame(409, $e->getStatusCode(), $driver);
                $this->assertSame('同じ予約がすでに登録されています。画面を再読み込みしてください。', $e->getMessage(), $driver);
            }
        }
    }

    /**
     * 重複以外の制約違反（NOT NULL など）は競合にせず、そのまま投げる（呼び出し側で内部エラーになる）。
     */
    public function testAddMany_otherConstraintViolation_isRethrown(): void
    {
        $this->expectException(QueryException::class);
        $this->repositoryWhoseInsertFails(['23000', 1048, "Column 'i_id_room' cannot be null"])->addMany(self::rows(), 'tester', false);
    }
}
