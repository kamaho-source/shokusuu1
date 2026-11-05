<?php
declare(strict_types=1);

namespace App\Test\TestCase\Support;

use Cake\ORM\TableRegistry;

/**
 * 監査ログ（t_audit_log）に、操作が1件残ったことを確かめる。
 */
trait AuditRowAssertions
{
    /**
     * 指定の操作の監査ログを1件だけ確かめて、その行を返す。
     *
     * @return array<string, mixed>
     */
    private function assertAudited(string $action, int $result, int $actorId): array
    {
        $rows = TableRegistry::getTableLocator()->get('TAuditLog')->find()
            ->where(['c_action' => $action])->orderBy(['i_id_audit' => 'ASC'])->disableHydration()->toArray();
        $this->assertCount(1, $rows, "監査ログ {$action} が1件残ること");
        $this->assertSame('reservation', $rows[0]['c_category']);
        $this->assertSame($result, (int)$rows[0]['i_result']);
        $this->assertSame($actorId, (int)$rows[0]['i_actor_user_id']);
        if ($result === 0) {
            $this->assertStringContainsString('reason', (string)$rows[0]['c_detail'], '失敗の理由が残ること');
        }

        return $rows[0];
    }
}
