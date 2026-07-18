<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Application\Tenant\TenantContextHolder;
use ArrayObject;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Event\EventInterface;
use Cake\ORM\Query;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MUserInfoTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_user_info');
        $this->setPrimaryKey('i_id_user');
        $this->hasMany('MUserGroup', [
            'foreignKey' => 'i_id_user',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->belongsTo('MRoomInfo', [
            'foreignKey' => 'i_id_room',
            'joinType' => 'INNER'
        ]);
        $this->hasMany('TIndividualReservationInfo', [
            'foreignKey' => 'i_id_user'
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
            ->allowEmptyString('i_id_user', 'create');

        // c_user_name（ユーザー名）のバリデーション
        $validator
            ->scalar('c_user_name')
            ->maxLength('c_user_name', 50)
            ->requirePresence('c_user_name', 'create')
            ->notEmptyString('c_user_name', 'ユーザー名を入力してください。')
            ->add('c_user_name', [
                'unique' => [
                    'rule' => ['validateUnique', ['scope' => null]],
                    'provider' => 'table',
                    'message' => 'このユーザー名は既に使用されています。'
                ]
            ]);

        // ログインID（c_login_account）のバリデーション
        $validator
            ->scalar('c_login_account')
            ->maxLength('c_login_account', 50)
            ->requirePresence('c_login_account', 'create')
            ->notEmptyString('c_login_account', 'ログインIDを入力してください。')
            ->add('c_login_account', [
                'unique' => [
                    'rule' => ['validateUnique', ['scope' => null]],
                    'provider' => 'table',
                    'message' => 'このログインIDは既に使用されています。'
                ]
            ]);

        // パスワード（c_login_passwd）のバリデーション
        $validator
            ->scalar('c_login_passwd')
            ->maxLength('c_login_passwd', 255)
            ->requirePresence('c_login_passwd', 'create')
            ->notEmptyString('c_login_passwd', 'パスワードを入力してください。');

        // ユーザー年齢（i_user_age）のバリデーション
        $validator
            ->integer('i_user_age')
            ->notEmptyString('i_user_age', '年齢を入力してください。')
            ->greaterThan('i_user_age', 0, '年齢は1以上で指定してください。')
            ->lessThanOrEqual('i_user_age', 80, '年齢は80以下で指定してください。');

        // ユーザーレベル（i_user_level）のバリデーション
        $validator
            ->integer('i_user_level')
            ->allowEmptyString('i_user_level', 'create')
            ->notEmptyString('i_user_level', 'ユーザーレベルを入力してください。');
        $validator
            ->integer('i_user_gender')
            ->allowEmptyString('i_user_gender', 'create');

        $validator
            ->integer('i_user_rank')
            ->allowEmptyString('i_user_rank', 'create')
            ->notEmptyString('i_user_rank', '役職を選択してください。');
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

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // ログインIDの一意性をテナント単位でチェックする
        $rules->add($rules->isUnique(
            ['c_login_account', 'tenant_id'],
            'このログインIDは既に使用されています。'
        ));

        return $rules;
    }

    /**
     * 認証用 Finder。テナント・有効ユーザーの条件を付与する。
     *
     * TenantContextHolder から現在のテナント ID を取得し、
     * テナントが解決されていない場合（localhost 等）は tenant_id 条件を省略する。
     * identify:true の Session Authenticator が毎リクエスト呼び出すため、
     * サブドメインをまたいだセッション持ち越しもここで防止できる。
     */
    public function findForAuthentication(SelectQuery $query): SelectQuery
    {
        $context = TenantContextHolder::get();
        $conditions = ['i_enable' => 0, 'i_del_flag' => 0];
        if ($context !== null) {
            $conditions['tenant_id'] = $context->tenantId();
        }

        return $query->where($conditions);
    }

    public function findAuth(Query $query, array $options)
    {
        return $query->select([
            'i_id_user',
            'c_login_account',
            'i_admin'
        ]);
    }
}
