<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\UserQueryInterface;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessInput;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessUseCase;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleInput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleOutput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleUseCase;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Entity\MUserInfo;
use App\Service\ApiResponseService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 利用者の権限（管理者・システム管理者・ブロック長・レポート閲覧）の切替 API
 * （MUserInfoController から分けた。URL は /MUserInfo/update-admin-status などのまま。routes.php 参照）。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class UserRoleController extends AppController
{
    use DropsDisabledIdentityResponseTrait;

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setTemplatePath('MUserInfo');

        // JSON を返す AJAX エンドポイントのため、フォームトークンの検証から外す（CSRF はミドルウェアで確認済み）
        $this->FormProtection->setConfig('unlockedActions', [
            'updateAdminStatus',
            'updateUserLevel',
            'updateSystemAdminStatus',
            'updateReportAccess',
        ]);
    }

    public function updateAdminStatus(ChangeUserRoleUseCase $changeRole, UserQueryInterface $userQuery): Response
    {
        return $this->changeRole($changeRole, $userQuery, 'i_admin', 'ユーザーIDまたは管理者権限が指定されていません。', 'updateAdminStatus',
            'この操作は管理者のみ実行できます。', UserRole::ADMIN, false, '管理者権限');
    }

    public function updateSystemAdminStatus(ChangeUserRoleUseCase $changeRole, UserQueryInterface $userQuery): Response
    {
        return $this->changeRole($changeRole, $userQuery, 'i_system_admin', 'ユーザーIDまたはシステム管理者権限が指定されていません。', 'updateSystemAdminStatus',
            'この操作はシステム管理者のみ実行できます。', UserRole::SYSTEM_ADMIN, true, 'システム管理者権限');
    }

    public function updateUserLevel(ChangeUserRoleUseCase $changeRole, UserQueryInterface $userQuery): Response
    {
        // ブロック長の切替も、認可は管理者権限の切替と同じ
        return $this->changeRole($changeRole, $userQuery, 'i_admin', 'ユーザーIDまたは権限レベルが指定されていません。', 'updateAdminStatus',
            'この操作は管理者のみ実行できます。', UserRole::BLOCK_LEADER, false, 'ブロック長権限');
    }

    /**
     * 権限（管理者・ブロック長・システム管理者）の切替の共通処理。
     * 確かめる順番は、指定が無い（400）→ 対象が居ない（404）→ 認可（403）→ 値・競合（403・409）。
     *
     * @param string $valueKey      リクエストの値のキー
     * @param int    $offTargetRole この画面が切り替える権限（解除のときに、他の権限を消さないために使う）
     * @param bool   $isSystemAdmin システム管理者の画面か（送られる値は 1/0 で、1 なら 3 を付ける）
     */
    private function changeRole(
        ChangeUserRoleUseCase $changeRole,
        UserQueryInterface $userQuery,
        string $valueKey,
        string $missingMessage,
        string $policyAction,
        string $forbiddenMessage,
        int $offTargetRole,
        bool $isSystemAdmin,
        string $roleLabel,
    ): Response {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data   = $this->request->getData();
        $userId = $data['i_id_user'] ?? null;
        $value  = $data[$valueKey] ?? null;

        if (is_null($userId) || is_null($value)) {
            $this->Authorization->skipAuthorization();
            return $apiResponse->error($this->response, $missingMessage, 400);
        }
        if (!$userQuery->exists((int)$userId)) {
            $this->Authorization->skipAuthorization();
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            // 権限の切替の認可は操作者の権限だけで決まる（対象の中身は見ない）
            $this->Authorization->authorize(new MUserInfo(), $policyAction);
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, $forbiddenMessage, 403);
        }

        $newRole = $isSystemAdmin ? ((int)$value === 1 ? UserRole::SYSTEM_ADMIN : UserRole::GENERAL) : (int)$value;
        $result  = $changeRole->execute(new ChangeUserRoleInput(
            (int)$userId,
            $newRole,
            $offTargetRole,
            $this->isOverwriteConfirmed($data),
            $isSystemAdmin,
            $this->auditActor(),
        ));

        return match ($result->status) {
            ChangeUserRoleOutput::CHANGED        => $apiResponse->success($this->response, [], $roleLabel . 'が正常に更新されました。'),
            ChangeUserRoleOutput::CONFLICT       => $apiResponse->error($this->response, (string)$result->message, 409),
            ChangeUserRoleOutput::NOT_ASSIGNABLE => $apiResponse->error($this->response, 'この画面からは、その権限に変更できません。', 403),
            default                              => $apiResponse->error($this->response, $roleLabel . 'の更新に失敗しました。', 500),
        };
    }

    /**
     * 画面で「別の権限から変更しますか？」に同意して送られたリクエストか。
     *
     * @param array<string, mixed> $data
     */
    private function isOverwriteConfirmed(array $data): bool
    {
        return filter_var($data['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function updateReportAccess(ChangeReportAccessUseCase $changeReportAccess, UserQueryInterface $userQuery): Response
    {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data         = $this->request->getData();
        $userId       = $data['i_id_user'] ?? null;
        $reportAccess = $data['i_report_access'] ?? null;

        if (is_null($userId) || is_null($reportAccess)) {
            $this->Authorization->skipAuthorization();
            return $apiResponse->error($this->response, 'ユーザーIDまたはレポートアクセス権が指定されていません。', 400);
        }
        if (!$userQuery->exists((int)$userId)) {
            $this->Authorization->skipAuthorization();
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            $this->Authorization->authorize(new MUserInfo(), 'updateReportAccess');
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, 'この操作はシステム管理者のみ実行できます。', 403);
        }

        $result = $changeReportAccess->execute(new ChangeReportAccessInput((int)$userId, (int)$reportAccess === 1, $this->auditActor()));
        if ($result->saved) {
            return $apiResponse->success($this->response, [], 'レポートアクセス権を更新しました。');
        }

        return $apiResponse->error($this->response, 'レポートアクセス権の更新に失敗しました。', 500);
    }
}
