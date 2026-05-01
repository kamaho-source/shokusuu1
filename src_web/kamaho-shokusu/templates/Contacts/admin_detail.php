<?php
/** @var \App\View\AppView $this */
/** @var \App\Model\Entity\TContact $contact */
$this->assign('title', 'お問い合わせ詳細');
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <a href="<?= $this->Url->build(['action' => 'adminIndex']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>一覧へ戻る
    </a>
    <h5 class="fw-semibold mb-0">
        <i class="bi bi-envelope-open me-2 text-primary"></i>お問い合わせ詳細
    </h5>
</div>

<!-- 問い合わせ内容 -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <span class="badge bg-secondary me-2"><?= h($contact->category) ?></span>
        <span class="text-muted small"><?= h($contact->created->format('Y-m-d H:i')) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-2">お名前</dt>
            <dd class="col-sm-10"><?= h($contact->name) ?></dd>
            <dt class="col-sm-2">メール</dt>
            <dd class="col-sm-10">
                <a href="mailto:<?= h($contact->email) ?>"><?= h($contact->email) ?></a>
            </dd>
            <dt class="col-sm-2">内容</dt>
            <dd class="col-sm-10">
                <div style="white-space: pre-wrap;"><?= h($contact->body) ?></div>
            </dd>
        </dl>
    </div>
</div>

<!-- 返信履歴 -->
<?php if (!empty($contact->t_contact_replies)): ?>
    <h6 class="fw-semibold mb-2">返信履歴</h6>
    <?php foreach ($contact->t_contact_replies as $reply): ?>
        <div class="card mb-2 border-start border-primary border-3">
            <div class="card-body py-2">
                <div class="text-muted small mb-1"><?= h($reply->sent_at->format('Y-m-d H:i')) ?> 送信</div>
                <div style="white-space: pre-wrap;"><?= h($reply->body) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- 返信フォーム -->
<div class="card mt-4">
    <div class="card-header bg-light fw-semibold">
        <i class="bi bi-reply me-1"></i>返信を送る
    </div>
    <div class="card-body">
        <?= $this->Form->create(null, ['url' => ['action' => 'adminDetail', $contact->id]]) ?>
        <div class="mb-3">
            <label class="form-label">返信内容 <span class="text-danger">*</span></label>
            <?= $this->Form->textarea('reply_body', [
                'class' => 'form-control',
                'rows'  => 8,
                'placeholder' => '返信内容を入力してください...',
                'required' => true,
            ]) ?>
            <div class="form-text">
                送信先：<?= h($contact->email) ?>（<?= h($contact->name) ?> 様）
            </div>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i>返信を送信する
        </button>
        <?= $this->Form->end() ?>
    </div>
</div>
