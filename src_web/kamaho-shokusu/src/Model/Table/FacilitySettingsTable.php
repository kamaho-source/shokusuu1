<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Infrastructure\Table\TenantAwareTableTrait;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * 施設別設定テーブル。
 *
 * 1施設につき1レコードのみ存在する（facility_id に UNIQUE 制約）。
 * レコードが存在しない場合はデフォルト値を使用する。
 */
class FacilitySettingsTable extends Table
{
    use TenantAwareTableTrait;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('facility_settings');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                    'updated_at' => 'always',
                ],
            ],
        ]);

        $this->belongsTo('Facilities', [
            'foreignKey' => 'facility_id',
            'joinType'   => 'INNER',
        ]);

        $this->belongsTo('Tenants', [
            'foreignKey' => 'tenant_id',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('facility_id', 'create')
            ->notEmptyString('facility_id')
            ->requirePresence('tenant_id', 'create')
            ->notEmptyString('tenant_id')
            ->integer('reservation_changeable_days')
            ->greaterThanOrEqual('reservation_changeable_days', 0)
            ->boolean('enable_weekly_bulk')
            ->boolean('enable_monthly_bulk')
            ->boolean('lunch_bento_exclusive')
            ->boolean('approval_enabled')
            ->boolean('resident_self_edit_enabled')
            ->allowEmptyDate('fiscal_year_update_date')
            ->allowEmptyString('export_template_code')
            ->maxLength('export_template_code', 50)
            ->allowEmptyTime('reservation_deadline_time');

        return $validator;
    }
}
