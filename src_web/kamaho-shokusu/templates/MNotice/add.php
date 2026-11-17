<?php
/**
 * お知らせ新規作成
 *
 * @var \App\View\AppView $this
 * @var bool $isSysAdmin
 */

$this->assign('title', 'お知らせ作成');
$this->Html->script('realtime-validation.js', ['block' => true]);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="content" style="max-width: 640px;">
    <h3 class="mb-4">
        <i class="bi bi-megaphone"></i> お知らせ作成
    </h3>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'method' => 'post', 'id' => 'notice-form']) ?>

    <?php if ($isSysAdmin): ?>
    <div class="card mb-4" id="maint-box">
        <div class="card-body">
            <div class="fw-bold mb-2"><i class="bi bi-tools"></i> メンテナンス予告（システム管理者）</div>
            <small class="text-muted d-block mb-2">日時を入れて「予告文を挿入」を押すと、タイトル・本文・掲示期間・重要度に加え、「モーダルで表示する」にも自動でチェックを入れます。内容は登録前に編集できます。</small>
            <div class="row g-2 mb-2">
                <div class="col-sm-6">
                    <label for="maint_from" class="form-label small mb-0">開始日時</label>
                    <input type="datetime-local" id="maint_from" class="form-control" placeholder="2026-11-20 22:00">
                </div>
                <div class="col-sm-6">
                    <label for="maint_to" class="form-label small mb-0">終了予定日時</label>
                    <input type="datetime-local" id="maint_to" class="form-control" placeholder="2026-11-20 23:30">
                </div>
            </div>
            <small id="maint_hint" class="text-muted d-none mb-2">このブラウザは日時の選択に対応していないため、「2026-11-20 22:00」の形式で入力してください。</small>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="maint_partial">
                <label class="form-check-label small" for="maint_partial">一部の機能だけ停止する（チェックしない場合は、システム全体の停止として書きます）</label>
            </div>
            <div id="maint_features" class="d-none mb-2"><div class="d-flex flex-wrap gap-3">
                <?php foreach (['予約', '予約履歴', '部屋情報・部屋異動予約', 'ユーザー管理', '食数単価・食事控除表', 'お問い合わせ', 'メール通知', 'AI集計', 'ログイン'] as $i => $feature): ?>
                <div class="form-check">
                    <input class="form-check-input maint-feature" type="checkbox" id="maint_f<?= $i ?>" value="<?= h($feature) ?>">
                    <label class="form-check-label small" for="maint_f<?= $i ?>"><?= h($feature) ?></label>
                </div>
                <?php endforeach; ?>
            </div></div>
            <input type="text" id="maint_note" class="form-control mb-2" maxlength="100" placeholder="備考（任意） 例：サーバー更新のため">
            <button type="button" id="maint-fill" class="btn btn-outline-warning btn-sm">予告文を挿入</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label for="c_title" class="form-label fw-bold">タイトル <span class="text-danger">*</span></label>
        <?= $this->Form->text('c_title', [
            'id'             => 'c_title',
            'class'          => 'form-control',
            'maxlength'      => 200,
            'placeholder'    => '例：6月30日が予約締切日です',
            'data-validate'  => 'required maxlength:200',
        ]) ?>
        <div class="invalid-feedback"></div>
    </div>

    <div class="mb-3">
        <label for="c_body" class="form-label fw-bold">本文</label>
        <small class="text-muted d-block mb-1">省略可。複数行の入力が可能です。</small>
        <?= $this->Form->textarea('c_body', [
            'id'    => 'c_body',
            'class' => 'form-control',
            'rows'  => 4,
        ]) ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <label for="d_start" class="form-label fw-bold">掲示開始日</label>
            <small class="text-muted d-block mb-1">空欄の場合は即時掲示</small>
            <?= $this->Form->date('d_start', [
                'id'             => 'd_start',
                'class'          => 'form-control',
                'data-validate'  => 'date-range',
                'data-range-end' => 'd_end',
            ]) ?>
            <div class="invalid-feedback"></div>
        </div>
        <div class="col-sm-6">
            <label for="d_end" class="form-label fw-bold">掲示終了日</label>
            <small class="text-muted d-block mb-1">空欄の場合は無期限</small>
            <?= $this->Form->date('d_end', [
                'id'    => 'd_end',
                'class' => 'form-control',
            ]) ?>
            <div class="invalid-feedback"></div>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">重要度</label>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_normal" value="0" checked>
                <label class="form-check-label" for="imp_normal">通常</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_importance" id="imp_high" value="1">
                <label class="form-check-label" for="imp_high">重要</label>
            </div>
        </div>
    </div>

    <?php if ($isSysAdmin): ?>
    <div class="mb-4">
        <label class="form-label fw-bold">種別</label>
        <small class="text-muted d-block mb-1">リリースノートはダッシュボードで 🚀 アイコン付きで表示されます。</small>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_type" id="type_normal" value="0" checked>
                <label class="form-check-label" for="type_normal">通常お知らせ</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="i_type" id="type_release" value="1">
                <label class="form-check-label" for="type_release">🚀 リリースノート</label>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isSysAdmin): ?>
    <div class="mb-4">
        <label class="form-label fw-bold">表示方法</label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="i_modal" id="i_modal" value="1">
            <label class="form-check-label" for="i_modal">モーダルで表示する</label>
        </div>
        <small class="text-muted d-block">ログイン後のどの画面でも、開いたときに表示します。「確認した」を押すまで毎回出ます。</small>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <?= $this->Form->submit('登録する', ['class' => 'btn btn-primary', 'id' => 'submit-btn', 'disabled' => true]) ?>
        <?= $this->Html->link('キャンセル', ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initRealtimeValidation('notice-form', 'submit-btn');

    const fillBtn = document.getElementById('maint-fill');
    if (!fillBtn) return;
    const $ = (id) => document.getElementById(id);
    const set = (id, v) => { $(id).value = v; $(id).dispatchEvent(new Event('input', { bubbles: true })); };
    const W = ['日', '月', '火', '水', '木', '金', '土'];
    const md = (d) => `${d.getMonth() + 1}月${d.getDate()}日(${W[d.getDay()]})`;
    const p2 = (n) => ('0' + n).slice(-2);
    const hm = (d) => `${d.getHours()}:${p2(d.getMinutes())}`;
    const ymd = (d) => `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;

    // datetime-local 非対応ブラウザ（デスクトップ Safari 14.1 未満・Firefox 93 未満など）では
    // 入力欄が文字入力になる。「2026-11-20 22:00」「2026/11/20 22:00」「2026-11-20T22:00」を受け付ける。
    // 文字列を new Date() に渡すと古いブラウザで解釈が揺れるため、数値に分解して作る。
    const parseDateTime = (s) => {
        const m = /^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[T\s]+(\d{1,2}):(\d{2})$/.exec(s.trim());
        if (!m) return null;
        const [y, mo, d, h, mi] = m.slice(1).map(Number);
        const dt = new Date(y, mo - 1, d, h, mi);
        // 13月・32日・25時などは Date が繰り上げるので、元の値と一致するか確かめる
        const ok = dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d && dt.getHours() === h && dt.getMinutes() === mi;
        return ok ? dt : null;
    };
    $('maint_partial').addEventListener('change', () => $('maint_features').classList.toggle('d-none', !$('maint_partial').checked));
    if ($('maint_from').type !== 'datetime-local') $('maint_hint').classList.remove('d-none');

    fillBtn.addEventListener('click', () => {
        if (!$('maint_from').value || !$('maint_to').value) { alert('開始日時と終了予定日時を入力してください。'); return; }
        const from = parseDateTime($('maint_from').value);
        const to = parseDateTime($('maint_to').value);
        if (!from || !to) { alert('日時は「2026-11-20 22:00」の形式で入力してください。'); return; }
        if (to <= from) { alert('終了予定日時は開始日時より後にしてください。'); return; }
        if (to <= new Date()) { alert('終了予定日時が過去です。未来の日時を入力してください。'); return; }

        // 「一部の機能だけ停止する」のときは、選んだ機能名を本文に入れる。チェックしなければシステム全体の停止
        const names = $('maint_partial').checked
            ? Array.prototype.filter.call(document.querySelectorAll('.maint-feature'), (c) => c.checked).map((c) => c.value)
            : [];
        if ($('maint_partial').checked && !names.length) { alert('停止する機能を1つ以上選んでください。'); return; }

        const sameDay = ymd(from) === ymd(to);
        const range = sameDay ? `${md(from)} ${hm(from)}〜${hm(to)}` : `${md(from)} ${hm(from)}〜${md(to)} ${hm(to)}`;
        const note = $('maint_note').value.trim();

        set('c_title', `【メンテナンス予告】${range}${names.length ? '（一部機能）' : ''}`);
        set('c_body', `${range}の間、システムメンテナンスを実施します。\n` +
            (note ? `${note}\n` : '') +
            (names.length ? `作業中は、${names.join('・')}を停止します。ほかの機能は通常どおりご利用いただけます。` : '作業中はシステムをご利用いただけません。') +
            'ご不便をおかけしますが、ご理解のほどよろしくお願いいたします。');
        // d_start の検証は d_end を参照するため、終了日を先に入れる
        set('d_end', ymd(to));
        set('d_start', ymd(new Date()));
        $('imp_high').checked = true;
        const t = $('type_normal'); if (t) t.checked = true;
        const modalBox = $('i_modal'); if (modalBox) modalBox.checked = true;
    });
});
</script>
