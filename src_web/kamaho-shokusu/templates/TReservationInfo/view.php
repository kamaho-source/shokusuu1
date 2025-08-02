<?php
/**
 * 予約一覧（部屋ごと）のビュー
 *
 * @var string $date            表示対象日（YYYY-mm-dd）
 * @var array  $mealDataArray   予約データを食事区分ごとにまとめた多次元配列
 */

use Cake\I18n\FrozenDate;                         // ★ 追加

$this->assign('title', h($date) . ' の食数予約一覧');

/* ──────────────────────────────────────────────
 * ログインユーザー情報
 * ────────────────────────────────────────────── */
$user = $this->request->getAttribute('identity');

/**
 * 所属部屋 ID を取得する
 *
 * CakePHP の Identity には
 *   1) 直接プロパティ（i_id_room）
 *   2) 関連エンティティ m_user_info 内の i_id_room
 * が混在している場合があるため、両方を考慮する。
 */

// ① 直接プロパティ
if (!isset($userRoomId)) {
    $userRoomId = null;
    if ($user !== null) {
        /* 1) Identity 直下の i_id_room */
        $userRoomId = $user->get('i_id_room');

        /* 2) 関連エンティティ m_user_info 内 */
        if ($userRoomId === null && $user->get('m_user_info')) {
            $userRoomId = $user->get('m_user_info')->get('i_id_room');
        }

        if ($userRoomId !== null) {
            $userRoomId = (int)$userRoomId;
        }
    }
}

if (!isset($isAdmin)) {
    $isAdmin = $user ? ((int)$user->get('i_admin') === 1) : false;
}

/* ──────────────────────────────────────────────
 * 当日を含め 14 日以内か判定
 * ────────────────────────────────────────────── */
$within14Days = FrozenDate::today()
        ->diff(new FrozenDate($date))
        ->days <= 14;                                  // ★ 追加
?>


<div class="container">
    <h1>予約一覧</h1>
    <h3>日付: <?= h($date) ?></h3>

    <?php
    /* ─────────────────────────────
     * 当日から 14 日先まで編集禁止
     * ───────────────────────────── */
    /* ─────────────────────────────
     * 当日から 14 日先まで編集禁止
     * 直前修正ボタンは当日 18:00 まで有効
     * ───────────────────────────── */

    // 現在日時（JST）
    $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));

    // 本日 00:00
    $todayStart = $now->setTime(0, 0, 0);

    // 14 日後 00:00
    $twoWeeksLater = (clone $todayStart)->modify('+14 days');

    // 表示対象日
    $selectedDateTime = new \DateTimeImmutable($date, new \DateTimeZone('Asia/Tokyo'));

    // ── 通常「編集」ボタン ─────────────────────
    //   * 14 日先までは不可
    $isDisabled = ($selectedDateTime <= $twoWeeksLater);  // ★ 修正: 「<=」で当日含め 14 日先を禁止

    // ── 直前「修正」ボタン ────────────────────
    //   * 過去日   : 表示しない
    //   * 当日     : 18:00 以降は無効
    //   * 未来日   : 14 日先までは有効
    $showLastMinuteBtn = (
        $selectedDateTime >= $todayStart &&
        $selectedDateTime <= $twoWeeksLater
    );

    if ($selectedDateTime < $todayStart) {
        // 過去日は常に無効
        $lastMinuteDisabled = true;
    } elseif ($selectedDateTime->format('Y-m-d') === $todayStart->format('Y-m-d')) {
        // 当日は 18:00 を過ぎたら無効
        $cutoff = (clone $todayStart)->setTime(18, 0, 0);
        $lastMinuteDisabled = ($now >= $cutoff);
    } else {
        // 未来日（14 日以内）は有効
        $lastMinuteDisabled = false;
    }
    ?>


    <?php foreach (['朝' => 1, '昼' => 2, '夜' => 3, '弁当' => 4] as $mealLabel => $mealType): ?>
        <h2><?= h($mealLabel) ?>の予約</h2>

        <?php if (!empty($mealDataArray[$mealLabel])): ?>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>部屋名</th>
                    <th>食べる人数</th>
                    <th>食べない人数</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mealDataArray[$mealLabel] as $data): ?>
                    <?php
                    /* ──────────────────────────────
                     * 食べる / 食べない人数を計算
                     *   直近 14 日以内 : i_change_flag
                     *   15 日以降     : eat_flag
                     * ビュー側で必ず再計算し、
                     * フラグに応じた正しい値を表示する。
                     * ────────────────────────────── */
                    $eatCount    = 0;
                    $notEatCount = 0;

                    if (isset($data['reservations']) && is_array($data['reservations'])) {
                        // 予約の配列がある場合はフラグを見て集計
                        foreach ($data['reservations'] as $r) {
                            $eatFlag = $within14Days
                                ? ($r['i_change_flag'] ?? 1)
                                : ($r['eat_flag']     ?? 1);

                            if ((int)$eatFlag === 1) {
                                $eatCount++;
                            } else {
                                $notEatCount++;
                            }
                        }
                    } else {
                        // 予約の配列が無い場合はコントローラから渡された集計値を使用
                        // ただし 14 日以内は i_change_flag ベースの値が必要なので注意
                        if ($within14Days) {
                            // フォールバックとして taberu_ninzuu などを使うが
                            // ここに来るケースはほぼ無い想定
                            $eatCount    = (int)($data['taberu_ninzuu']  ?? 0);
                            $notEatCount = (int)($data['tabenai_ninzuu'] ?? 0);
                        } else {
                            $eatCount    = (int)($data['taberu_ninzuu']  ?? 0);
                            $notEatCount = (int)($data['tabenai_ninzuu'] ?? 0);
                        }
                    }
                    ?>
                    <tr>
                        <td><?= h($data['room_name']) ?></td>
                        <td><?= h($eatCount) ?></td>
                        <td><?= h($notEatCount) ?></td>
                        <td>
                            <?php if ($isAdmin || (int)$data['room_id'] === $userRoomId): ?>
                                <!-- 通常修正ボタン -->
                                <?php if (!$isDisabled): ?>
                                    <?= $this->Html->link(
                                        '編集',
                                        "/TReservationInfo/edit/{$data['room_id']}/{$date}/{$mealType}",
                                        ['class' => 'btn btn-primary btn-sm']
                                    ) ?>
                                <?php endif; ?>

                                <!-- 直前修正ボタン -->
                                <?php if ($showLastMinuteBtn): ?>
                                    <?php
                                    // ボタン共通オプション
                                    $linkOptions = [
                                        'class'    => 'btn btn-warning btn-sm' . ($lastMinuteDisabled ? ' disabled' : ''),
                                        'disabled' => $lastMinuteDisabled ? 'disabled' : false,
                                    ];

                                    // 有効時だけ confirm を追加
                                    if ($showLastMinuteBtn && !$lastMinuteDisabled) {
                                        $linkOptions['confirm'] =
                                            'すでに食材は発注しています。'
                                            . '食材が無駄になってしまうので極力食べないことがないようにしましょう。'
                                            . '続行しますか？';
                                    }
                                    ?>
                                    <?= $this->Html->link(
                                        '直前修正',
                                        ['controller' => 'TReservationInfo',
                                            'action'     => 'changeEdit',
                                            $data['room_id'], $date, $mealType],
                                        $linkOptions
                                    ) ?>
                                <?php endif; ?>

                                <!-- 詳細ボタン -->
                                <?= $this->Html->link(
                                    '詳細',
                                    "/TReservationInfo/roomDetails/{$data['room_id']}/{$date}/{$mealType}",
                                    ['class' => 'btn btn-secondary btn-sm']
                                ) ?>
                            <?php else: ?>
                                <span class="text-muted">閲覧 / 変更不可</span>
                            <?php endif; ?>
                        </td>


                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>予約データがありません。</p>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!$isDisabled): ?>
        <button class="btn btn-primary"
                onclick="location.href='<?= $this->Url->build(['action' => 'add', '?' => ['date' => $date]]) ?>'">
            追加する
        </button>
    <?php else: ?>
        <button class="btn btn-secondary" disabled>追加不可（当日から14日先までは登録不可）</button>
    <?php endif; ?>
</div>