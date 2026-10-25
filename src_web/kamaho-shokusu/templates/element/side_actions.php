<?php
declare(strict_types=1);

/**
 * 操作パネル（PC: 画面左の追従カード / スマートフォン: 画面下の操作バー＋「その他」シート）。
 *
 * 画面側はリンクの一覧を渡すだけにする。権限で出さないリンクは items に入れない。
 *
 * @var \App\View\AppView $this
 * @var list<array{tone: string, label: string, url: array<mixed>|string, primary?: bool, confirmTarget?: string}> $items
 * @var bool $hidden モーダル表示など、パネル自体を出さないとき true
 * @throws \InvalidArgumentException 未定義の tone、必須キーの欠落、primary の複数指定
 */
$items = $items ?? [];
$hidden = $hidden ?? false;
if ($hidden || $items === []) {
    return;
}

/** tone ごとのアイコン。色は CSS（.sa-t-{tone}）で持つ。 */
$tones = [
    'view' => 'bi-eye',
    'edit' => 'bi-pencil',
    'add' => 'bi-plus-lg',
    'back' => 'bi-arrow-left',
    'delete' => 'bi-trash',
];

$primary = null;
$back = null;
$others = [];
$deletes = [];
foreach ($items as $item) {
    foreach (['tone', 'label', 'url'] as $key) {
        if (!isset($item[$key])) {
            throw new \InvalidArgumentException("side_actions: items に {$key} がありません。");
        }
    }
    if (!is_string($item['tone']) || !isset($tones[$item['tone']])) {
        throw new \InvalidArgumentException('side_actions: 未定義の tone です。');
    }
    if (!is_string($item['label'])) {
        throw new \InvalidArgumentException('side_actions: label は文字列で指定してください。');
    }
    if (!empty($item['primary'])) {
        if ($item['tone'] === 'delete') {
            throw new \InvalidArgumentException('side_actions: delete を primary にはできません（確認なしの GET リンクになるため）。');
        }
        if ($primary !== null) {
            throw new \InvalidArgumentException('side_actions: primary は1画面に1つまでです。');
        }
        $primary = $item;
        continue;
    }
    if ($item['tone'] === 'delete') {
        $deletes[] = $item;
        continue;
    }
    if ($item['tone'] === 'back' && $back === null) {
        $back = $item;
        continue;
    }
    $others[] = $item;
}

$this->Html->css('side_actions', ['block' => true]);
$this->Html->script('side_actions.js', ['block' => true]);

$icon = static fn(string $tone): string => '<span class="sa-tile sa-t-' . $tone . '"><i class="bi ' . $tones[$tone] . '" aria-hidden="true"></i></span>';

$link = function (array $item, string $class) use ($icon): string {
    return $this->Html->link(
        $icon($item['tone']) . '<span class="sa-label">' . h($item['label']) . '</span>',
        $item['url'],
        ['class' => $class, 'escape' => false]
    );
};

$deleteForm = function (array $item, string $class) use ($icon): string {
    $target = $item['confirmTarget'] ?? '';
    $message = $target === '' ? '削除してもよろしいですか？' : '「' . $target . '」を削除してもよろしいですか？';

    return $this->Form->create(null, ['url' => $item['url'], 'type' => 'post', 'class' => 'sa-delete-form'])
        . '<button type="button" class="' . $class . '" data-sa-confirm="' . h($message) . '">'
        . $icon('delete') . '<span class="sa-label">' . h($item['label']) . '</span></button>'
        . $this->Form->end();
};

// PC のパネルは、渡された順に並べる（primary は強調）。削除は区切り線の下。
$panelItems = [];
foreach ($items as $item) {
    if ($item['tone'] !== 'delete') {
        $panelItems[] = $item;
    }
}

// スマートフォンの操作バーの中央（主な操作。無ければ、ラベル付きの「戻る」）
$barMain = '';
if ($primary !== null) {
    $barMain = $this->Html->link(
        '<i class="bi ' . $tones[$primary['tone']] . '" aria-hidden="true"></i><span>' . h($primary['label']) . '</span>',
        $primary['url'],
        ['class' => 'sa-bar-wide sa-bar-wide--primary', 'escape' => false]
    );
}
if ($primary === null && $back !== null) {
    $barMain = $link($back, 'sa-bar-wide sa-bar-wide--back');
}

// スマートフォンの「その他」シートに入るもの（バーに出す primary と back 以外）。
$sheetHasItems = $others !== [] || $deletes !== [];
?>
<aside class="col-md-3 d-none d-md-block sa-aside" aria-label="操作">
    <div class="sa-card">
        <div class="sa-title">操作</div>
        <?php foreach ($panelItems as $item) : ?>
            <?= $link($item, 'sa-item' . (!empty($item['primary']) ? ' sa-item--primary' : '')) ?>
        <?php endforeach; ?>
        <?php if ($deletes !== []) : ?>
            <div class="sa-sep"></div>
            <?php foreach ($deletes as $item) : ?>
                <?= $deleteForm($item, 'sa-item sa-item--delete') ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</aside>

<nav class="sa-bar d-md-none" aria-label="操作">
    <script>document.body.classList.add('has-sa-bar');</script>
    <?php if ($back !== null && $primary !== null) : ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>',
            $back['url'],
            ['class' => 'sa-bar-back', 'escape' => false, 'aria-label' => $back['label'], 'title' => $back['label']]
        ) ?>
    <?php endif; ?>
    <?= $barMain ?>
    <?php if ($sheetHasItems) : ?>
        <?php $moreAlone = $back === null && $primary === null; ?>
        <button type="button" class="sa-bar-more<?= $moreAlone ? ' sa-bar-more--wide' : '' ?>" data-bs-toggle="offcanvas" data-bs-target="#sa-sheet"
                aria-controls="sa-sheet" aria-label="その他の操作">
            <i class="bi bi-three-dots" aria-hidden="true"></i><?= $moreAlone ? '<span>操作</span>' : '' ?>
        </button>
    <?php endif; ?>
</nav>

<?php if ($sheetHasItems) : ?>
<div class="offcanvas offcanvas-bottom sa-sheet d-md-none" tabindex="-1" id="sa-sheet" aria-labelledby="sa-sheet-title">
    <div class="offcanvas-header">
        <div class="sa-sheet-title" id="sa-sheet-title">その他の操作</div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="閉じる"></button>
    </div>
    <div class="offcanvas-body">
        <?php foreach ($others as $item) : ?>
            <?= $link($item, 'sa-item') ?>
        <?php endforeach; ?>
        <?php if ($deletes !== []) : ?>
            <div class="sa-sep"></div>
            <?php foreach ($deletes as $item) : ?>
                <?= $deleteForm($item, 'sa-item sa-item--delete') ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
