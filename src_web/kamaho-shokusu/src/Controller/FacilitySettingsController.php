<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\FacilitySetting\GetFacilitySettingInput;
use App\Application\UseCase\FacilitySetting\GetFacilitySettingOutput;
use App\Application\UseCase\FacilitySetting\GetFacilitySettingUseCase;
use App\Application\UseCase\FacilitySetting\SaveFacilitySettingInput;
use App\Application\UseCase\FacilitySetting\SaveFacilitySettingUseCase;
use App\Service\AuditLogService;
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

        $before = $this->getUseCase->execute(new GetFacilitySettingInput($facilityId, $tenantId));

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $input = new SaveFacilitySettingInput(
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
            );
            try {
                $this->saveUseCase->execute($input);

                AuditLogService::record(
                    category:     'master',
                    action:       'facility_setting_update',
                    actorName:    (string)($user?->get('c_user_name') ?? ''),
                    actorId:      (int)($user?->get('i_id_user') ?? 0),
                    targetTable:  'facility_settings',
                    targetId:     (string)$facilityId,
                    detail:       $this->buildAuditDetail($before, $input),
                    ipAddress:    $this->getClientIp(),
                    result:       1,
                    actorLoginId: (string)($user?->get('c_login_account') ?? ''),
                );

                $this->Flash->success('施設設定を保存しました。');
                return $this->redirect(['action' => 'edit']);
            } catch (PersistenceFailedException) {
                $this->Flash->error('保存に失敗しました。入力内容を確認してください。');
            } catch (\InvalidArgumentException $e) {
                $this->Flash->error($e->getMessage());
            }
        }

        $this->set('setting', $before);
        return null;
    }

    /**
     * 施設設定の変更履歴一覧
     */
    public function history(): ?Response
    {
        $user       = $this->request->getAttribute('identity');
        $facilityId = $user ? (int)$user->facility_id : 0;
        $tenantId   = $user ? (int)$user->tenant_id   : 0;

        if ($facilityId === 0 || $tenantId === 0) {
            $this->Flash->error('施設情報が設定されていません。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $settingsTable = $this->fetchTable('FacilitySettings');
        $entity = $settingsTable->newEmptyEntity();
        $entity->set('tenant_id', $tenantId, ['guard' => false]);

        try {
            $this->Authorization->authorize($entity, 'view');
        } catch (ForbiddenException) {
            $this->Flash->error('この機能は管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $logTable = $this->fetchTable('TAuditLog');
        $query = $logTable->find()
            ->where([
                'c_action'      => 'facility_setting_update',
                'c_target_table' => 'facility_settings',
                'c_target_id'   => (string)$facilityId,
            ])
            ->order(['dt_create' => 'DESC']);

        $logs = $this->paginate($query, ['limit' => 30, 'maxLimit' => 100]);

        $this->set(compact('logs'));
        return null;
    }

    /**
     * 変更前後の差分を監査ログ用 detail 配列に変換する。
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

        return sprintf('%02d-%02d', $month, $day);
    }

    /** @return array<string, mixed> */
    private function buildAuditDetail(GetFacilitySettingOutput $before, SaveFacilitySettingInput $after): array
    {
        $labels = [
            'reservation_changeable_days' => '予約変更可能日数',
            'enable_weekly_bulk'          => '週間一括予約',
            'enable_monthly_bulk'         => '月間一括予約',
            'lunch_bento_exclusive'       => '昼食・弁当排他',
            'approval_enabled'            => '承認フロー',
            'resident_self_edit_enabled'  => '入居者自己編集',
            'fiscal_year_update_date'     => '年度更新日',
            'export_template_code'        => 'エクスポートテンプレート',
            'reservation_deadline_time'   => '予約締切時刻',
        ];

        $beforeArr = [
            'reservation_changeable_days' => $before->reservationChangeableDays,
            'enable_weekly_bulk'          => $before->enableWeeklyBulk,
            'enable_monthly_bulk'         => $before->enableMonthlyBulk,
            'lunch_bento_exclusive'       => $before->lunchBentoExclusive,
            'approval_enabled'            => $before->approvalEnabled,
            'resident_self_edit_enabled'  => $before->residentSelfEditEnabled,
            'fiscal_year_update_date'     => $before->fiscalYearUpdateDate,
            'export_template_code'        => $before->exportTemplateCode,
            'reservation_deadline_time'   => $before->reservationDeadlineTime,
        ];
        $afterArr = [
            'reservation_changeable_days' => $after->reservationChangeableDays,
            'enable_weekly_bulk'          => $after->enableWeeklyBulk,
            'enable_monthly_bulk'         => $after->enableMonthlyBulk,
            'lunch_bento_exclusive'       => $after->lunchBentoExclusive,
            'approval_enabled'            => $after->approvalEnabled,
            'resident_self_edit_enabled'  => $after->residentSelfEditEnabled,
            'fiscal_year_update_date'     => $after->fiscalYearUpdateDate,
            'export_template_code'        => $after->exportTemplateCode,
            'reservation_deadline_time'   => $after->reservationDeadlineTime,
        ];

        $changes = [];
        foreach ($beforeArr as $key => $oldVal) {
            $newVal = $afterArr[$key];
            if ($oldVal !== $newVal) {
                $changes[] = [
                    'field'  => $labels[$key] ?? $key,
                    'before' => $oldVal,
                    'after'  => $newVal,
                ];
            }
        }

        return ['changes' => $changes];
    }
}
