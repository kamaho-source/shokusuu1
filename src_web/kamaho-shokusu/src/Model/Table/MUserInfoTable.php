<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Event\EventInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MUserInfoTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_user_info');
        $this->setPrimaryKey('i_id_user');

        $this->belongsTo('MUserGroup', [
            'foreignKey' => 'i_id_user',
            'joinType' => 'INNER'
        ]);
        $this->belongsTo('MRoomInfo', [
            'foreignKey' => 'i_id_room',
            'joinType' => 'INNER'
        ]);
    }

    public function beforeSave(EventInterface $event, $entity, ArrayObject $options)
    {
        if (!empty($entity->c_login_passwd) && $entity->isDirty('c_login_passwd')) {
            $hasher = new DefaultPasswordHasher();
            $entity->c_login_passwd = $hasher->hash($entity->c_login_passwd);
        }
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_id_user')
            ->allowEmpty('i_id_user', 'create');

        $validator
            ->scalar('c_user_name')
            ->maxLength('c_user_name', 50)
            ->requirePresence('c_user_name', 'create')
            ->notEmptyString('c_user_name', 'ユーザー名を入力してください。');

        // 追加: パスワードのバリデーション
        $validator
            ->scalar('c_login_passwd')
            ->maxLength('c_login_passwd', 255)
            ->requirePresence('c_login_passwd', 'create')
            ->notEmptyString('c_login_passwd', 'パスワードを入力してください。');

        // 追加: ユーザー年齢のバリデーション
        $validator
            ->integer('i_user_age')
            ->allowEmptyString('i_user_age', 'create')
            ->notEmptyString('i_user_age', '年齢を入力してください。')
            ->range('i_user_age', [0, 80], '年齢は0から120の範囲で指定してください。');

        // 追加: ユーザーレベルのバリデーション
        $validator
            ->integer('i_user_level')
            ->allowEmptyString('i_user_level', 'create')
            ->notEmptyString('i_user_level', 'ユーザーレベルを入力して下さい。');

        $validator
            ->dateTime('dt_create')
            ->allowEmptyDateTime('dt_create');

        $validator
            ->scalar('c_create_user')
            ->maxLength('c_create_user', 50)
            ->allowEmptyString('c_create_user');

        $validator
            ->dateTime('dt_update')
            ->allowEmptyDateTime('dt_update');

        $validator
            ->scalar('c_update_user')
            ->maxLength('c_update_user', 50)
            ->allowEmptyString('c_update_user');

        return $validator;
    }
}
