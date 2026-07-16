<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 監査ログサービス
 *
 * システム内の重要操作を t_audit_log に記録する。
 * 静的メソッド record() を使用することで、DI不要でどこからでも呼び出せる。
 * 書き込み失敗は例外を握りつぶしてメイン処理を妨げない。
 *
 * カテゴリ一覧:
 *   user        ユーザー管理操作
 *   reservation 予約操作
 *   actual_meal 実食入力
 *   approval    承認フロー
 *   master      マスタデータ管理
 *   system      システム操作
 *
 * アクション例:
 *   user_login / user_login_failed / user_logout
 *   user_create / user_update / user_delete / user_restore
 *   user_permission_change / user_password_change_admin / user_password_change_self
 *   user_room_assign / user_room_remove / user_bulk_import
 *   reservation_toggle / reservation_individual_save / reservation_group_save
 *   actual_meal_save / actual_meal_approval_request
 *   approval_block_leader / approval_admin / approval_rejected / approval_reflected
 *   room_create / room_update / room_delete
 *   meal_price_create / meal_price_update / meal_price_delete
 *   audit_export
 */
class AuditLogService
{
    /**
     * 監査ログを記録する。
     *
     * @param string      $category      操作カテゴリ
     * @param string      $action        操作種別
     * @param string      $actorName     操作者ユーザー名（表示名 c_user_name）
     * @param int         $actorId       操作者ユーザーID (0 = 不明)
     * @param string|null $targetTable   対象テーブル名
     * @param string|null $targetId      対象レコードID（複合キーはカンマ区切り等）
     * @param array|null  $detail        操作詳細（JSON化して保存）
     * @param string|null $ipAddress     操作元IPアドレス
     * @param int         $result        1=成功 0=失敗
     * @param string      $actorLoginId  操作者ログインID（c_login_account）
     */
    public static function record(
        string $category,
        string $action,
        string $actorName,
        int $actorId = 0,
        ?string $targetTable = null,
        ?string $targetId = null,
        ?array $detail = null,
        ?string $ipAddress = null,
        int $result = 1,
        string $actorLoginId = ''
    ): void {
        try {
            $table = TableRegistry::getTableLocator()->get('TAuditLog');
            $log   = $table->newEmptyEntity();

            $log->c_category        = $category;
            $log->c_action          = $action;
            $log->c_actor_user_name = $actorName;
            $log->c_actor_login_id  = $actorLoginId !== '' ? $actorLoginId : null;
            $log->i_actor_user_id   = $actorId > 0 ? $actorId : null;
            $log->c_target_table    = $targetTable;
            $log->c_target_id       = $targetId;
            $log->c_detail          = $detail !== null
                ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $log->c_ip_address      = $ipAddress;
            $log->i_result          = $result;
            $log->dt_create         = date('Y-m-d H:i:s');

            $table->save($log);
        } catch (\Throwable) {
            // 監査ログ失敗はメイン処理を妨げない
        }
    }

    /**
     * CSVエクスポート用にログ一覧を取得する。
     *
     * @param array $conditions find() 用 where 条件
     * @return \Cake\ORM\Query\SelectQuery
     */
    public static function buildQuery(array $conditions = []): \Cake\ORM\Query\SelectQuery
    {
        $table = TableRegistry::getTableLocator()->get('TAuditLog');
        return $table->find()
            ->where($conditions)
            ->order(['dt_create' => 'DESC']);
    }
}
