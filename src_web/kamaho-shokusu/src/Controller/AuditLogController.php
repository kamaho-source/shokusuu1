<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use App\Service\AuditLogService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 監査ログ閲覧コントローラー
 *
 * - システム管理者（i_admin = 3）: 全テナントのログを閲覧可能
 * - テナント管理者（i_admin = 4）: 自テナントのログのみ（クエリ層で強制）
 */
class AuditLogController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * 監査ログ一覧・検索
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能はシステム管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $table      = $this->fetchTable('TAuditLog');
        $conditions = $this->buildConditions();

        $query = $table->find()
            ->where($conditions)
            ->orderBy(['dt_create' => 'DESC']);

        $logs = $this->paginate($query, ['limit' => 100, 'maxLimit' => 500]);

        $categories = ['user', 'reservation', 'actual_meal', 'approval', 'master', 'system'];

        $this->set(compact('logs', 'categories'));
        return null;
    }

    /**
     * CSVエクスポート（最大10,000件）
     */
    public function export(): Response
    {
        try {
            $this->Authorization->authorize($this, 'export');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能はシステム管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $identity = $this->request->getAttribute('identity');
        AuditLogService::record(
            'system',
            'audit_export',
            $identity?->get('c_user_name') ?? 'system_admin',
            $identity ? (int)$identity->get('i_id_user') : 0,
            't_audit_log',
            null,
            ['filters' => $this->request->getQueryParams()],
            $this->getClientIp(),
            1,
            (string)($identity?->get('c_login_account') ?? '')
        );

        $conditions = $this->buildConditions();
        $table      = $this->fetchTable('TAuditLog');
        $logs       = $table->find()
            ->where($conditions)
            ->orderBy(['dt_create' => 'DESC'])
            ->limit(10000)
            ->all();

        $filename = 'audit_log_' . date('YmdHis') . '.csv';

        $output = fopen('php://temp', 'w+');
        // BOM付きUTF-8（Excelで文字化けしない）
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['ID', 'カテゴリ', '操作種別', '対象テーブル', '対象ID', '操作者ID', 'ログインID', '操作者名', 'IPアドレス', '結果', '詳細', '操作日時']);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log->i_id_audit,
                $log->c_category,
                $log->c_action,
                $log->c_target_table,
                $log->c_target_id,
                $log->i_actor_user_id,
                $log->c_actor_login_id,
                $log->c_actor_user_name,
                $log->c_ip_address,
                $log->i_result === 1 ? '成功' : '失敗',
                $log->c_detail,
                $log->dt_create,
            ]);
        }

        rewind($output);
        $body = (string)stream_get_contents($output);
        fclose($output);

        $this->autoRender = false;

        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withStringBody($body);
    }

    /**
     * クエリパラメータから絞り込み条件を構築する。
     *
     * テナント管理者の場合は自テナントのログのみに強制スコープする。
     * システム管理者は全テナントを横断して閲覧できる。
     */
    private function buildConditions(): array
    {
        $conditions = [];
        $q = $this->request->getQueryParams();

        $identity = $this->request->getAttribute('identity');
        if ($identity !== null && !UserRole::isSystemAdmin((int)$identity->get('i_admin'))) {
            $tenantId = $identity->get('tenant_id');
            if ($tenantId !== null) {
                $conditions['TAuditLog.tenant_id'] = (int)$tenantId;
            }
        }

        if (!empty($q['category'])) {
            $conditions['c_category'] = $q['category'];
        }
        if (!empty($q['action'])) {
            $conditions['c_action LIKE'] = '%' . $q['action'] . '%';
        }
        if (!empty($q['actor'])) {
            $conditions['OR'] = [
                'c_actor_user_name LIKE' => '%' . $q['actor'] . '%',
                'c_actor_login_id LIKE'  => '%' . $q['actor'] . '%',
            ];
        }
        if (!empty($q['target_id'])) {
            $conditions['c_target_id LIKE'] = '%' . $q['target_id'] . '%';
        }
        if (isset($q['result']) && $q['result'] !== '') {
            $conditions['i_result'] = (int)$q['result'];
        }
        if (!empty($q['date_from'])) {
            $conditions['dt_create >='] = $q['date_from'] . ' 00:00:00';
        }
        if (!empty($q['date_to'])) {
            $conditions['dt_create <='] = $q['date_to'] . ' 23:59:59';
        }

        return $conditions;
    }
}
