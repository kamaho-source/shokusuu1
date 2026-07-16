<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MLpImage Table
 *
 * LP（ランディングページ）掲載画像マスタ（m_lp_image）。
 * 管理画面からアップロードした画像のメタ情報を保持し、
 * LP側は i_display=1 の画像をセクションごとに表示する。
 */
class MLpImageTable extends Table
{
    /** LP上の掲載セクション（値 => 管理画面での表示ラベル） */
    public const SECTIONS = [
        'hero'    => 'ヒーロー（メイン画像）',
        'gallery' => '導入イメージ（ギャラリー）',
    ];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_lp_image');
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
            ->maxLength('c_title', 100)
            ->requirePresence('c_title', 'create')
            ->notEmptyString('c_title', 'タイトルを入力してください。');

        $validator
            ->scalar('c_section')
            ->inList('c_section', array_keys(self::SECTIONS), '掲載セクションが不正です。');

        $validator
            ->scalar('c_file_path')
            ->maxLength('c_file_path', 255)
            ->requirePresence('c_file_path', 'create')
            ->notEmptyString('c_file_path');

        $validator
            ->integer('i_display')
            ->inList('i_display', [0, 1]);

        $validator
            ->integer('i_sort');

        return $validator;
    }

    /**
     * LPに表示する画像（i_display=1）をセクション・表示順で取得するファインダー。
     */
    public function findVisible(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['i_display' => 1])
            ->orderByAsc('i_sort')
            ->orderByAsc('i_id');
    }
}
