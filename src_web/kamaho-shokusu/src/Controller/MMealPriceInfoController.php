<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\MealPriceQueryInterface;
use App\Application\UseCase\CreateMealPrice\CreateMealPriceUseCase;
use App\Application\UseCase\DeleteMealPrice\DeleteMealPriceUseCase;
use App\Application\UseCase\ExportMealSummary\ExportMealSummaryUseCase;
use App\Application\UseCase\UpdateMealPrice\UpdateMealPriceUseCase;
use App\Domain\Entity\MealPrice;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Infrastructure\Persistence\Entity\MMealPriceInfo;
use App\Service\ApiResponseService;
use App\Service\MealSummaryExportService;
use Authorization\Exception\ForbiddenException;
use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\Date;

/**
 * 単価マスタ（管理者）と控除表の出力。登録・更新・削除・出力は UseCase、一覧は MealPriceQueryInterface。
 */
class MMealPriceInfoController extends AppController
{
    private const FIELDS = ['year' => 'i_fiscal_year', 'morning' => 'i_morning_price', 'lunch' => 'i_lunch_price', 'dinner' => 'i_dinner_price', 'bento' => 'i_bento_price'];

    /**
     * 権限が無ければ Response（$redirect へ戻す）。
     */
    private function deny(string $action, string $message, array $redirect): ?Response
    {
        try {
            $this->Authorization->authorize(new MMealPriceInfo(), $action);

            return null;
        } catch (ForbiddenException) {
            $this->Flash->error(__($message));

            return $this->redirect($redirect);
        }
    }

    /**
     * 送信された単価（'year' などのキーで、空は空文字）。送られなかった項目は入れない（更新では今の値のまま）。
     *
     * @return array<string, string>
     */
    private function submittedValues(): array
    {
        $values = [];
        foreach (self::FIELDS as $key => $field) {
            if ($this->request->getData($field) !== null) {
                $values[$key] = trim($this->dataString($field));
            }
        }

        return $values;
    }

    /**
     * 入力フォームの中身（型・初期値）。保存できなかったときは送信された値を出し直す。
     *
     * @param array<string, string>|null $submitted
     * @return array{schema: array<string, array{type: string}>, defaults: array<string, int|string|null>}
     */
    private static function formContext(?MealPrice $price, ?array $submitted = null): array
    {
        $values = $submitted ?? ['year' => $price?->year, 'morning' => $price?->morning, 'lunch' => $price?->lunch, 'dinner' => $price?->dinner, 'bento' => $price?->bento];
        $schema = $defaults = [];
        foreach (self::FIELDS as $key => $field) {
            $schema[$field] = ['type' => 'integer'];
            $defaults[$field] = $values[$key] ?? null;
        }

        return ['schema' => $schema, 'defaults' => $defaults];
    }

    public function index(MealPriceQueryInterface $priceQuery): ?Response
    {
        if ($denied = $this->deny('index', 'あなたは閲覧権限がありません。', ['controller' => 'MUserInfo', 'action' => 'login'])) {
            return $denied;
        }

        $this->set('mMealPriceInfo', $priceQuery->all());

        return null;
    }

    public function view(MealPriceQueryInterface $priceQuery, $id = null): ?Response
    {
        $price = $priceQuery->find((int)$id) ?? throw new NotFoundException('食事料金情報が見つかりません。');
        if ($denied = $this->deny('view', 'あなたは閲覧権限がありません。', ['action' => 'index'])) {
            return $denied;
        }

        $this->set('mMealPriceInfo', $price);

        return null;
    }

    public function add(CreateMealPriceUseCase $createPrice): ?Response
    {
        $this->request->allowMethod(['get', 'post']);
        if ($denied = $this->deny('add', 'あなたは追加権限がありません。', ['action' => 'index'])) {
            return $denied;
        }

        $form = self::formContext(null);
        if ($this->request->is('post')) {
            $values = $this->submittedValues();
            try {
                $createPrice->execute($values, $this->auditActor());
                $this->Flash->success(__('食事料金情報が正常に保存されました。'));

                return $this->redirect(['action' => 'index']);
            } catch (InvalidInputException $e) {
                $this->Flash->error(__($e->getMessage()));
                $form = self::formContext(null, $values);
            }
        }
        $this->set('mealPriceForm', $form);

        return null;
    }

    public function edit(UpdateMealPriceUseCase $updatePrice, MealPriceQueryInterface $priceQuery, $id = null): ?Response
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $price = $priceQuery->find((int)$id) ?? throw new NotFoundException('食事料金情報が見つかりません。');
        if ($denied = $this->deny('edit', 'あなたは編集権限がありません。', ['action' => 'index'])) {
            return $denied;
        }

        $form = self::formContext($price);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $values = $this->submittedValues();
            try {
                $updatePrice->execute((int)$price->id, $values, $this->auditActor());
                $this->Flash->success(__('食事料金情報が正常に更新されました。'));

                return $this->redirect(['action' => 'index']);
            } catch (InvalidInputException $e) {
                $this->Flash->error(__($e->getMessage()));
                $form = self::formContext($price, $values);
            } catch (DomainNotFoundException $e) {
                throw new NotFoundException($e->getMessage(), null, $e);
            }
        }
        $this->set('mMealPriceInfo', $price);
        $this->set('mealPriceForm', $form);

        return null;
    }

    public function delete(DeleteMealPriceUseCase $deletePrice, MealPriceQueryInterface $priceQuery, $id = null): Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $price = $priceQuery->find((int)$id) ?? throw new NotFoundException('食事料金情報が見つかりません。');
        if ($denied = $this->deny('delete', 'あなたは削除権限がありません。', ['action' => 'index'])) {
            return $denied;
        }

        try {
            $deleted = $deletePrice->execute((int)$price->id, $this->auditActor());
        } catch (DomainNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), null, $e);
        }
        if ($deleted) {
            $this->Flash->success(__('食事料金情報が正常に削除されました。'));
        } else {
            $this->Flash->error(__('食事料金情報を削除できませんでした。もう一度お試しください。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function getMealSummary(MealPriceQueryInterface $priceQuery): ?Response
    {
        $this->Authorization->authorize(new MMealPriceInfo(), 'index');

        // 年度と月をリクエストから取得 (デフォルト値は今年と現在の月)
        $Year  = $this->queryString('year') ?: Date::today()->format('Y');
        $month = $this->queryString('month') ?: Date::today()->format('n');
        $yearList  = $priceQuery->years();
        $monthList = range(1, 12);

        $this->set(compact('yearList', 'monthList', 'Year', 'month'));

        return null;
    }

    public function exportMealSummary(ExportMealSummaryUseCase $exportSummary): Response
    {
        // 全職員の氏名・職員ID・食数・控除額が返るため、管理者のみ許可する。
        $this->Authorization->authorize(new MMealPriceInfo(), 'exportMealSummary');
        $this->autoRender = false;
        $apiResponse = new ApiResponseService();

        // 空のときだけ今年・今月にする（"0" を指定なしと同じにしない）
        $year  = (int)($this->queryString('year') !== '' ? $this->queryString('year') : Date::today()->format('Y'));
        $month = (int)($this->queryString('month') !== '' ? $this->queryString('month') : Date::today()->format('n'));
        try {
            $rows = $exportSummary->execute($year, $month, $this->auditActor());
        } catch (InvalidInputException $e) {
            return $apiResponse->error($this->response, $e->getMessage(), $e->getStatusCode());
        }

        return $apiResponse->success($this->response, ['rows' => $rows]);
    }

    public function exportMealSummaryPreview(): Response
    {
        // 承認機能オフのときは「未承認プレビュー」は概念として存在しないため無効化する。
        if (!Configure::read('Features.approval')) {
            throw new NotFoundException('未承認プレビューは現在無効です。');
        }

        $this->Authorization->authorize(new MMealPriceInfo(), 'add');

        $this->autoRender = false;
        $apiResponse = new ApiResponseService();

        $year  = (int)($this->queryString('year') ?: Date::today()->format('Y'));
        $month = (int)($this->queryString('month') ?: Date::today()->format('n'));

        $result = (new MealSummaryExportService())->aggregatePreview($year, $month);

        return $apiResponse->success($this->response, $result);
    }
}
