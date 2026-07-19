<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\FacilitySetting\GetFacilitySettingInput;
use App\Application\UseCase\FacilitySetting\GetFacilitySettingUseCase;
use App\Application\UseCase\FacilitySetting\SaveFacilitySettingInput;
use App\Application\UseCase\FacilitySetting\SaveFacilitySettingUseCase;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\ORM\Exception\PersistenceFailedException;

/**
 * 施設別設定コントローラー
 *
 * 管理者が自施設の業務ルール（予約変更可能日数・承認設定など）を参照・変更する。
 */
final class FacilitySettingsController extends AppController
{
    public function __construct(
        private readonly GetFacilitySettingUseCase $getUseCase,
        private readonly SaveFacilitySettingUseCase $saveUseCase,
        ?ServerRequest $request = null,
        ?string $name = null,
    ) {
        parent::__construct($request, $name);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        // テンプレートは生HTML inputを使用しているためFormProtectionのフィールドハッシュ検証を除外する。
        // CSRF保護はミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', ['edit']);
    }

    public function index(): Response
    {
        return $this->redirect(['action' => 'edit']);
    }

    /**
     * 施設設定の表示・保存（GET: 表示、POST: 保存）
     */
    public function edit(): ?Response
    {
        $user       = $this->request->getAttribute('identity');
        $facilityId = $user ? (int)$user->facility_id : 0;
        $tenantId   = $user ? (int)$user->tenant_id   : 0;

        if ($facilityId === 0 || $tenantId === 0) {
            $this->Flash->error('施設情報が設定されていないため施設設定を変更できません。システム管理者にお問い合わせください。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        // 認可チェック
        $settingsTable = $this->fetchTable('FacilitySettings');
        $entity = $settingsTable->find()
            ->where(['facility_id' => $facilityId, 'tenant_id' => $tenantId])
            ->first()
            ?? $settingsTable->newEmptyEntity();
        $entity->set('tenant_id', $tenantId, ['guard' => false]);

        try {
            $this->Authorization->authorize($entity, 'edit');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能は管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $output = $this->getUseCase->execute(new GetFacilitySettingInput($facilityId, $tenantId));

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            try {
                $this->saveUseCase->execute(new SaveFacilitySettingInput(
                    facilityId:               $facilityId,
                    tenantId:                 $tenantId,
                    reservationChangeableDays: max(0, (int)($data['reservation_changeable_days'] ?? 7)),
                    enableWeeklyBulk:         !empty($data['enable_weekly_bulk']),
                    enableMonthlyBulk:        !empty($data['enable_monthly_bulk']),
                    lunchBentoExclusive:      !empty($data['lunch_bento_exclusive']),
                    approvalEnabled:          !empty($data['approval_enabled']),
                    residentSelfEditEnabled:  !empty($data['resident_self_edit_enabled']),
                    fiscalYearUpdateDate:     $this->buildFiscalYearUpdateDate($data),
                    exportTemplateCode:       ($data['export_template_code'] ?? '') !== '' ? $data['export_template_code'] : null,
                    reservationDeadlineTime:  ($data['reservation_deadline_time'] ?? '') !== '' ? $data['reservation_deadline_time'] : null,
                ));
                $this->Flash->success('施設設定を保存しました。');
                return $this->redirect(['action' => 'edit']);
            } catch (PersistenceFailedException $e) {
                $this->Flash->error('保存に失敗しました。入力内容を確認してください。');
            } catch (\InvalidArgumentException $e) {
                $this->Flash->error($e->getMessage());
            }
        }

        $this->set('setting', $output);
        return null;
    }

    /**
     * 月・日セレクトの値を `2000-MM-DD` 形式に変換する。
     * 月か日のいずれかが未選択の場合は null を返す。
     *
     * @param array<string, mixed> $data
     */
    private function buildFiscalYearUpdateDate(array $data): ?string
    {
        $month = (int)($data['fiscal_year_update_month'] ?? 0);
        $day   = (int)($data['fiscal_year_update_day']   ?? 0);

        if ($month === 0 || $day === 0) {
            return null;
        }

        return sprintf('2000-%02d-%02d', $month, $day);
    }
}
