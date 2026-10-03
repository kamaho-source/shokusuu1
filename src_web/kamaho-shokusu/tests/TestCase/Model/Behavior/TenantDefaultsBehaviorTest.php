<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Behavior;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * tenant_id / facility_id の補完が漏れていないことを検証する。
 *
 * これらの列は本番・ステージングの DB では NOT NULL かつ既定値なし。
 * 埋めずに INSERT すると MySQL が 1364 を返して保存が丸ごと失敗するが、
 * 画面には汎用メッセージしか出ないため気づきにくい。実際に承認処理が
 * この状態で動かなくなっていた。
 *
 * テーブルを追加したときの付け漏れをここで止める。
 */
class TenantDefaultsBehaviorTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TApprovalLog',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.MRoomTransferSchedule',
    ];

    /**
     * 該当列を持つテーブルすべてにビヘイビアが付いていること。
     *
     * 列を追加しただけでビヘイビアを付け忘れた場合、本番では保存が
     * 失敗するのにテストは通ってしまう。それをここで防ぐ。
     *
     * @return void
     */
    public function testEveryTableWithTenantColumnsHasBehavior(): void
    {
        $locator = TableRegistry::getTableLocator();
        $missing = [];
        $checked = 0;

        foreach (ConnectionManager::get('test')->getSchemaCollection()->listTables() as $tableName) {
            $alias     = str_replace('_', '', ucwords($tableName, '_'));
            $className = 'App\\Model\\Table\\' . $alias . 'Table';
            if (!class_exists($className)) {
                // Table クラスが無いテーブルは、そもそもアプリから保存されない
                continue;
            }

            $table = $locator->get($alias);
            $columns = $table->getSchema()->columns();
            if (!in_array('tenant_id', $columns, true) && !in_array('facility_id', $columns, true)) {
                continue;
            }

            $checked++;
            if (!$table->hasBehavior('TenantDefaults')) {
                $missing[] = $tableName;
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'tenant_id / facility_id を持つテーブルが1つも見つからない。'
                . 'tests/schema.php の定義が本番とずれていないか確認してください。'
        );

        $this->assertSame(
            [],
            $missing,
            "tenant_id / facility_id を持つのに TenantDefaults が付いていないテーブル: "
                . implode(', ', $missing)
                . "\n→ 該当 Table クラスの initialize() に \$this->addBehavior('TenantDefaults'); を追加してください。"
        );
    }

    /**
     * 新規行に既定値が入ること。
     *
     * @return void
     */
    public function testFillsDefaultsOnCreate(): void
    {
        $table  = TableRegistry::getTableLocator()->get('TApprovalLog');
        $entity = $table->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-01-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 2,
            'i_approver_id'      => 1,
            'dt_create'          => '2026-01-01 00:00:00',
        ], ['validate' => false]);

        $table->saveOrFail($entity, ['checkRules' => false]);

        $this->assertSame(1, (int)$entity->get('tenant_id'), 'tenant_id が補完されていない');
        $this->assertSame(1, (int)$entity->get('facility_id'), 'facility_id が補完されていない');
    }

    /**
     * 明示的に渡した値は上書きしないこと。
     *
     * 将来複数施設を扱うようになったときに、呼び出し側の指定を
     * 既定値で潰してはいけない。
     *
     * @return void
     */
    public function testDoesNotOverwriteExplicitValues(): void
    {
        $table  = TableRegistry::getTableLocator()->get('TApprovalLog');
        $entity = $table->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-01-02',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 2,
            'i_approver_id'      => 1,
            'dt_create'          => '2026-01-01 00:00:00',
        ], ['validate' => false]);
        $entity->set('tenant_id', 7);
        $entity->set('facility_id', 9);

        $table->saveOrFail($entity, ['checkRules' => false]);

        $this->assertSame(7, (int)$entity->get('tenant_id'), '渡した tenant_id が潰されている');
        $this->assertSame(9, (int)$entity->get('facility_id'), '渡した facility_id が潰されている');
    }
}
