<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MNoticeTable extends Table
{
    public const IMPORTANCE_NORMAL = 0;
    public const IMPORTANCE_HIGH   = 1;

    public const TYPE_NORMAL       = 0;
    public const TYPE_RELEASE_NOTE = 1;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_notice');
        $this->setPrimaryKey('i_id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'dt_create' => 'new',
                    'dt_update' => 'always',
                ],
            ],
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('c_title')
            ->maxLength('c_title', 200)
            ->requirePresence('c_title', 'create')
            ->notEmptyString('c_title', 'タイトルを入力してください。');

        $validator
            ->scalar('c_body')
            ->allowEmptyString('c_body');

        $validator
            ->date('d_start')
            ->allowEmptyDate('d_start');

        $validator
            ->date('d_end')
            ->allowEmptyDate('d_end');

        $validator
            ->integer('i_importance')
            ->inList('i_importance', [self::IMPORTANCE_NORMAL, self::IMPORTANCE_HIGH]);

        $validator
            ->integer('i_type')
            ->inList('i_type', [self::TYPE_NORMAL, self::TYPE_RELEASE_NOTE]);

        return $validator;
    }

    /**
     * 現在掲示中（有効期間内）のお知らせを取得するスコープ。
     * d_start が null または今日以前、かつ d_end が null または今日以降のレコードを対象とする。
     */
    public function findActive(Query $query, array $options): Query
    {
        $today = date('Y-m-d');

        return $query
            ->where([
                'OR' => [
                    ['d_start IS' => null, 'd_end IS' => null],
                    ['d_start <=' => $today, 'd_end IS' => null],
                    ['d_start IS' => null, 'd_end >=' => $today],
                    ['d_start <=' => $today, 'd_end >=' => $today],
                ],
            ])
            ->orderByDesc('i_importance')
            ->orderByDesc('dt_create');
    }
}
