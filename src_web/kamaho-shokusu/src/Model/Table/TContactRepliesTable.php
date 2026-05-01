<?php
declare(strict_types=1);

namespace App\Model\Table;

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

        return $validator;
    }
}
