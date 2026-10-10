<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\TContactReply;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class TContactRepliesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_contact_replies');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('TContacts', [
            'foreignKey' => 'contact_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('contact_id')
            ->notEmptyString('contact_id');

        $validator
            ->scalar('body')
            ->minLength('body', 1, '返信内容を入力してください。')
            ->maxLength('body', 5000, '返信内容は5000文字以内で入力してください。')
            ->requirePresence('body', 'create')
            ->notEmptyString('body', '返信内容は必須です。');

        $validator
            ->scalar('author_type')
            ->inList(
                'author_type',
                [TContactReply::AUTHOR_ADMIN, TContactReply::AUTHOR_USER],
                '不正な返信者種別です。'
            )
            ->allowEmptyString('author_type');

        $validator
            ->scalar('external_message_id')
            ->maxLength('external_message_id', 255)
            ->allowEmptyString('external_message_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // Webhook再送時に同じ受信メールを二重登録しないためのDBレベルの一意性制約。
        // null は対象外（admin返信や external_message_id 未設定のレコード同士は衝突させない）。
        $rules->add(
            $rules->isUnique(['external_message_id'], 'このメールは既に処理済みです。'),
            'uniqueExternalMessageId',
            ['errorField' => 'external_message_id']
        );

        return $rules;
    }
}
