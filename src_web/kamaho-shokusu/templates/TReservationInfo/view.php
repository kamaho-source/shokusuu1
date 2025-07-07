<?php
/**
 * 予約一覧（部屋ごと）のビュー
 *
 * @var string $date            表示対象日（YYYY-mm-dd）
 * @var array  $mealDataArray   予約データを食事区分ごとにまとめた多次元配列
 */

$this->assign('title', h($date) . ' の食数予約一覧');

/* ──────────────────────────────────────────────
 * ログインユーザー情報
 * ────────────────────────────────────────────── */
$user       = $this->request->getAttribute('identity');
$userRoomId = $user ? $user->get('i_id_room') : null;             // 所属部屋 ID
$isAdmin    = $user ? ((int)$user->get('i_admin') === 1) : false; // 管理者フラグ
?>

<div class="container">
    <h1>予約一覧</h1>
    <h3>日付: <?= h($date) ?></h3>

    <?php
    /* ─────────────────────────────
     * 当日から 30 日先まで編集禁止
     * ───────────────────────────── */
    $currentDate   = new \DateTime();
    $oneMonthLater = (clone $currentDate)->modify('+30 days');
    $selectedDate  = new \DateTime($date);
    $isDisabled    = ($selectedDate < $oneMonthLater);
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
                    <tr>
                        <td><?= h($data['room_name']) ?></td>
                        <td><?= h($data['taberu_ninzuu']) ?></td>
                        <td><?= h($data['tabenai_ninzuu']) ?></td>
                        <td>
                            <?php if ($isAdmin || $data['room_id'] === $userRoomId): /* 自分の部屋 または 管理者 */ ?>
                                <?php
                                /* 詳細 */
                                $urlDetails = "/TReservationInfo/roomDetails/{$data['room_id']}/{$date}/{$mealType}";
                                echo $this->Html->link(
                                    '詳細',
                                    $urlDetails,
                                    ['class' => 'btn btn-primary btn-sm']
                                );
                                ?>

                                <?php
                                /* 編集（30 日以内は無効化） */
                                $urlEdit = "/TReservationInfo/edit/{$data['room_id']}/{$date}/{$mealType}";
                                echo $this->Html->link(
                                    '編集',
                                    $urlEdit,
                                    [
                                        'class'    => 'btn btn-primary btn-sm' . ($isDisabled ? ' disabled' : ''),
                                        'disabled' => $isDisabled ? 'disabled' : false
                                    ]
                                );
                                ?>
                            <?php else: /* 権限なし */ ?>
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
        <button class="btn btn-secondary" disabled>追加不可（当日から1ヶ月後までは登録不可）</button>
    <?php endif; ?>
</div>