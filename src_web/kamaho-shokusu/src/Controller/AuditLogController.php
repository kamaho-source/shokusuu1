<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 監査ログ閲覧コントローラー
 *
 * システム管理者（i_admin = 3）専用。
 * ログ一覧・絞り込み・CSVエクスポートを提供する。
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

        $categories      = AuditLogService::CATEGORY_LABELS;
        $actions         = AuditLogService::ACTION_LABELS;
        $failedLoginNames = AuditLogService::failedLoginActorNames($logs);

        $this->set(compact('logs', 'categories', 'actions', 'failedLoginNames'));
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
        fputcsv($output, ['ID', 'カテゴリ', '操作種別', '操作種別コード', '対象テーブル', '対象ID', '操作者ID', 'ログインID', '操作者名', 'IPアドレス', '結果', '失敗理由', '詳細', '操作日時']);
        $failedLoginNames = AuditLogService::failedLoginActorNames($logs);

        foreach ($logs as $log) {
            // 失敗理由は c_detail(JSON) の reason キーから抽出（失敗ログのみ）
            $reason = '';
            if ($log->i_result !== 1 && $log->c_detail) {
                $decoded = json_decode((string)$log->c_detail, true);
                if (is_array($decoded) && isset($decoded['reason']) && is_string($decoded['reason'])) {
                    $reason = $decoded['reason'];
                }
            }

            fputcsv($output, [
                $log->i_id_audit,
                AuditLogService::categoryLabel((string)$log->c_category),
                AuditLogService::actionLabel((string)$log->c_action),
                $log->c_action,
                $log->c_target_table,
                $log->c_target_id,
                $log->i_actor_user_id,
                $log->c_actor_login_id,
                AuditLogService::actorDisplayName($log, $failedLoginNames),
                $log->c_ip_address,
                $log->i_result === 1 ? '成功' : '失敗',
                $reason,
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
     */
    private function buildConditions(): array
    {
        $conditions = [];
        $q = $this->request->getQueryParams();

        if (!empty($q['category'])) {
            $conditions['c_category'] = $q['category'];
        }
        $action = is_string($q['action'] ?? null) ? $q['action'] : '';
        if ($action !== '') {
            // 選択肢（既知の種別）は完全一致。user_login が user_login_failed まで拾わないようにする
            $conditions[] = isset(AuditLogService::ACTION_LABELS[$action])
                ? ['c_action' => $action]
                : ['c_action LIKE' => '%' . $action . '%'];
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
