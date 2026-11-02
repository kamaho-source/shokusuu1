<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\ReservationHistoryQueryInterface;
use App\Domain\Service\DatePeriodRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Query\CakeReservationHistoryQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * 予約履歴コントローラー（読み取り専用）
 *
 * 予約（過去日・未来日）を確認する画面。タブは左から
 *   meals   … 予約状況（実食機能はオフのため「予約済み」かどうか）
 *   summary … 部屋集計
 *   log     … 変更の経緯
 * 読み取りは ReservationHistoryQueryInterface、期間の決まりは DatePeriodRule にある。
 */
class ReservationHistoryController extends AppController
{
    /** 変更の経緯に操作者の名前も出すか。false なら区分（本人／代理／管理者）のみ */
    private const SHOW_ACTOR_NAME = true;

    private const TABS = ['meals', 'summary', 'log'];

    private ReservationHistoryQueryInterface $history;

    public function initialize(): void
    {
        parent::initialize();
        $this->history = new CakeReservationHistoryQuery(new CakeRoomQuery(), new ClockFactory('Asia/Tokyo'));
    }

    /**
     * GET /ReservationHistory
     *
     * クエリ: from, to（Y-m-d）, user（対象者ID）, tab, room（部屋集計の部屋ID）
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この画面を利用する権限がありません。');

            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $svc = $this->history;
        $actorId = (int)$this->Authentication->getIdentity()?->get('i_id_user');
        $actor = $svc->findViewer($actorId);
        if ($actor === null) {
            $this->Flash->error('この画面を利用する権限がありません。');

            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        // 初期の期間は今月1日〜今月末日（過去日も未来日も見られる）
        $today = DateTime::now('Asia/Tokyo');
        $from = $this->queryString('from') ?: $today->format('Y-m-01');
        $to = $this->queryString('to') ?: $today->format('Y-m-t');
        $tab = $this->queryString('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'meals';

        $targets = $svc->selectableTargets($actor);
        // 既定の対象者はログインしている本人
        $targetId = (int)$this->queryString('user') ?: $actorId;

        $error = DatePeriodRule::error($from, $to);
        $forbidden = null;
        $target = null;
        $meals = $logs = $summary = [];
        $logStart = null;
        // 部屋の情報（部屋集計）は子どもには見せない
        $canViewSummary = UserRole::canViewRoomSummary($actor->admin, $actor->level);
        $rooms = $canViewSummary ? $svc->summaryRooms($actorId) : [];
        $roomId = (int)$this->queryString('room') ?: (int)(array_key_first($rooms) ?? 0);

        if ($tab === 'summary' && !$canViewSummary) {
            $forbidden = '部屋集計は職員・管理者だけが見られます。';
        } elseif ($error === null && $tab === 'summary') {
            if (!isset($rooms[$roomId])) {
                $forbidden = 'この部屋の集計を見る権限がありません。';
            } else {
                $summary = $svc->roomSummary($roomId, $from, $to);
            }
        } elseif ($error === null) {
            $target = $svc->findViewer($targetId);
            if ($target === null || !isset($targets[$targetId])) {
                $forbidden = 'この人の履歴を見る権限がありません。';
                $target = null;
            } elseif ($tab === 'log') {
                $logs = $svc->changeLog($targetId, $from, $to, self::SHOW_ACTOR_NAME);
                $logStart = $svc->logStartDate();
            } else {
                $meals = $svc->bookedMeals($targetId, $from, $to);
            }
        }
        if ($forbidden !== null) {
            $this->response = $this->response->withStatus(403);
        } elseif ($error !== null) {
            $this->response = $this->response->withStatus(400);
        }

        $mealLabels = MealType::labels(MealType::LABEL_SHORT_NIGHT);
        // 予約状況・部屋集計は、予約の無い日も含めて期間の全日付を並べる（初期は今月1日〜今月末日の1か月分）
        $dates = $error === null ? DatePeriodRule::dates($from, $to) : [];
        $this->set(compact(
            'from', 'to', 'tab', 'targets', 'targetId', 'target', 'actorId', 'error', 'forbidden',
            'meals', 'logs', 'logStart', 'summary', 'rooms', 'roomId', 'mealLabels', 'canViewSummary', 'dates'
        ));

        return null;
    }
}
