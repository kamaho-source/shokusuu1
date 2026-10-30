<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ReservationHistoryService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 予約履歴コントローラー（読み取り専用）
 *
 * 職員・管理者が「いつ食べたか」を確認する画面。タブは
 *   meals   … 食べた日（実食）
 *   log     … 変更の経緯
 *   summary … 部屋集計
 * ビジネスロジックは ReservationHistoryService にある。
 */
class ReservationHistoryController extends AppController
{
    /** 変更の経緯に操作者の名前も出すか。false なら区分（本人／代理／管理者）のみ */
    private const SHOW_ACTOR_NAME = true;

    private const TABS = ['meals', 'log', 'summary'];

    private ReservationHistoryService $historyService;

    public function initialize(): void
    {
        parent::initialize();
        $this->historyService = new ReservationHistoryService();
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
            $this->Flash->error('この画面は職員・管理者のみ利用できます。');

            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $svc = $this->historyService;
        $actorId = (int)$this->Authentication->getIdentity()?->get('i_id_user');
        $actor = $svc->findUser($actorId);
        if ($actor === null) {
            $this->Flash->error('この画面は職員・管理者のみ利用できます。');

            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')));
        $yesterday = $today->modify('-1 day');
        $from = (string)($this->request->getQuery('from') ?: $yesterday->format('Y-m-01'));
        $to = (string)($this->request->getQuery('to') ?: $yesterday->format('Y-m-d'));
        $tab = (string)$this->request->getQuery('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'meals';

        $targets = $svc->selectableTargets($actor);
        // 既定の対象者: 見られる人のうち先頭の子ども。いなければ本人
        $targetId = (int)$this->request->getQuery('user');
        if ($targetId <= 0) {
            $targetId = $this->defaultTargetId($targets, $actorId);
        }

        $error = $svc->validatePeriod($from, $to, $today->format('Y-m-d'));
        $forbidden = null;
        $target = null;
        $meals = $logs = $summary = [];
        $rooms = $svc->summaryRooms($actorId);
        $roomId = (int)$this->request->getQuery('room') ?: (int)(array_key_first($rooms) ?? 0);

        if ($error === null && $tab === 'summary') {
            if (!isset($rooms[$roomId])) {
                $forbidden = 'この部屋の集計を見る権限がありません。';
            } else {
                $summary = $svc->roomSummary($roomId, $from, $to);
            }
        } elseif ($error === null) {
            $target = $svc->findUser($targetId);
            if ($target === null || !$svc->canView($actor, $target)) {
                $forbidden = 'この人の履歴を見る権限がありません。';
                $target = null;
            } elseif ($tab === 'log') {
                $logs = $svc->logRows($targetId, $from, $to, self::SHOW_ACTOR_NAME);
            } else {
                $meals = $svc->mealRows($targetId, $from, $to);
            }
        }
        if ($forbidden !== null) {
            $this->response = $this->response->withStatus(403);
        } elseif ($error !== null) {
            $this->response = $this->response->withStatus(400);
        }

        $mealLabels = ReservationHistoryService::MEAL_LABELS;
        $this->set(compact(
            'from', 'to', 'tab', 'targets', 'targetId', 'target', 'actorId', 'error', 'forbidden',
            'meals', 'logs', 'summary', 'rooms', 'roomId', 'mealLabels'
        ));

        return null;
    }

    /**
     * @param array<int, string> $targets 選べる対象者（id => 名前）
     */
    private function defaultTargetId(array $targets, int $actorId): int
    {
        foreach (array_keys($targets) as $id) {
            if ($id !== $actorId) {
                return (int)$id;
            }
        }

        return $actorId;
    }
}
