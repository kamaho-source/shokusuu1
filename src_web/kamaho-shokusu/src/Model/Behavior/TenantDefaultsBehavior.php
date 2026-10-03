<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;

/**
 * tenant_id / facility_id を新規行に補完する。
 *
 * マルチテナント対応で追加された列で、本番・ステージングの DB では
 * NOT NULL かつ既定値なしになっている。アプリ側が値を入れないまま
 * INSERT すると MySQL が 1364 を返し、保存が丸ごと失敗する。
 *
 * 失敗の仕方が厄介で、画面には「エラーが発生しました」程度しか出ない。
 * 利用者は操作が通ったつもりで次へ進むため、承認済みのはずの食数が
 * 承認されていない、といった取り違えにつながる。
 *
 * 単一施設での運用が前提のため既定値は 1 とする。複数施設を扱うように
 * なったら、ログイン中の利用者の施設 ID を見るように差し替える。
 */
class TenantDefaultsBehavior extends Behavior
{
    /**
     * 補完する列と既定値。
     *
     * @var array<string, int>
     */
    private const DEFAULTS = ['tenant_id' => 1, 'facility_id' => 1];

    /**
     * 新規行に tenant_id / facility_id を補完する。
     *
     * 既に値が入っている行には触らない。列を持たないテーブルでも
     * 安全に付けられるよう、スキーマに無い列は飛ばす。
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event
     * @param \Cake\Datasource\EntityInterface $entity
     * @param \ArrayObject<string, mixed> $options
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $schema = $this->table()->getSchema();

        foreach (self::DEFAULTS as $column => $default) {
            if ($schema->getColumn($column) === null) {
                continue;
            }
            if ($entity->get($column) !== null) {
                continue;
            }
            $entity->set($column, $default);
        }
    }
}
