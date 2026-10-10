<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class TContactsTable extends Table
{
    public const CATEGORIES = [
        'ご意見・ご要望'   => 'ご意見・ご要望',
        '不具合報告'       => '不具合報告',
        '使い方の質問'     => '使い方の質問',
        'その他'           => 'その他',
    ];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_contacts');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->hasMany('TContactReplies', [
            'foreignKey' => 'contact_id',
            'dependent'  => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('category')
            ->inList('category', array_keys(self::CATEGORIES), 'カテゴリを選択してください。')
            ->requirePresence('category', 'create')
            ->notEmptyString('category', 'カテゴリは必須です。');

        $validator
            ->scalar('name')
            ->maxLength('name', 100, 'お名前は100文字以内で入力してください。')
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'お名前は必須です。');

        $validator
            ->email('email', false, '有効なメールアドレスを入力してください。')
            ->maxLength('email', 255)
            ->requirePresence('email', 'create')
            ->notEmptyString('email', 'メールアドレスは必須です。');

        $validator
            ->scalar('body')
            ->minLength('body', 10, '内容は10文字以上で入力してください。')
            ->maxLength('body', 2000, '内容は2000文字以内で入力してください。')
            ->requirePresence('body', 'create')
            ->notEmptyString('body', '内容は必須です。');

        $validator
            ->integer('user_id')
            ->allowEmptyString('user_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // reply_token は random_bytes(16) によるランダム生成のため衝突確率は実質ゼロだが、
        // DBのユニーク制約と同じ保証をアプリ層にも持たせ、万一の衝突時にDB例外ではなく
        // 通常のバリデーションエラーとして扱えるようにする。
        $rules->add(
            $rules->isUnique(['reply_token'], '予期しないエラーが発生しました。再度お試しください。'),
            'uniqueReplyToken',
            ['errorField' => 'reply_token']
        );

        return $rules;
    }
}
