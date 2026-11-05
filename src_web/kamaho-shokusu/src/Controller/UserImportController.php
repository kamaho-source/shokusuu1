<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\BulkImportUsers\BulkImportUsersInput;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersUseCase;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Entity\MUserInfo;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\GoneException;
use Cake\Http\Response;

/**
 * 利用者の一括登録（MUserInfoController から分けた。URL は /MUserInfo/import-json などのまま。routes.php 参照）。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class UserImportController extends AppController
{

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setTemplatePath('MUserInfo');

        // importJson は JSON ボディを受け取る AJAX エンドポイントのため、フォームトークンの検証から外す（CSRF はミドルウェアで確認済み）
        $this->FormProtection->setConfig('unlockedActions', ['importJson']);
    }

    public function importForm(): ?Response
    {
        try {
            $this->Authorization->authorize(new MUserInfo(), 'importForm');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能を利用する権限がありません。'));
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'index']);
        }

        $this->set('title', 'ユーザー一括登録');

        return null;
    }

    /**
     * @throws \Cake\Http\Exception\GoneException 常に（廃止済み）
     */
    public function import(): never
    {
        $this->request->allowMethod(['post']);
        throw new GoneException('このエンドポイントは廃止されました。importJson を利用してください。');
    }

    /**
     * クライアント（ブラウザ）でパース済みの JSON を受け取り登録します。
     *
     * @throws \Cake\Http\Exception\BadRequestException ボディが不正・records が無い・取り込みに失敗したとき
     */
    public function importJson(BulkImportUsersUseCase $bulkImportUsers): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');
        $this->Authorization->authorize(new MUserInfo(), 'importJson');

        $payload = $this->request->getData();
        if (empty($payload)) {
            $raw = (string)$this->request->getBody();
            if ($raw !== '') {
                try {
                    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestException('リクエストボディが不正な JSON です。');
                }
            }
            $payload = is_array($payload) ? $payload : [];
        }
        $records = is_array($payload) ? ($payload['records'] ?? null) : null;
        if (!is_array($records)) {
            throw new BadRequestException('records 配列が必要です。');
        }

        try {
            $results = $bulkImportUsers->execute(new BulkImportUsersInput(
                $records,
                $this->auditActor(),
                UserRole::isAdmin((int)($this->request->getAttribute('identity')?->get('i_admin') ?? 0)),
            ))->toArray();
        } catch (\Throwable $e) {
            $this->log('userBulkImport error: ' . $e->getMessage(), 'error');
            throw new BadRequestException('インポート処理中にエラーが発生しました。');
        }

        $this->set(['ok' => true, 'summary' => $results, '_serialize' => ['ok', 'summary']]);
    }
}
