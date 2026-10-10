<?php
/**
 * @var \App\View\AppView $this
 * @var mixed $categories
 * @var mixed $defaultEmail
 * @var mixed $defaultName
 * @var object $entity
 * @var array<\App\Model\Entity\TContact> $myContacts
 */
$this->assign('title', 'フィードバック・お問い合わせ');
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">

        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold">
                    <i class="bi bi-envelope me-2 text-primary"></i>フィードバック・お問い合わせ
                </h5>
                <p class="text-muted small mb-0 mt-1">
                    ご意見・不具合のご報告・ご質問などをお送りください。
                </p>
            </div>

            <div class="card-body py-4">
                <?= $this->Form->create(null, ['novalidate' => true]) ?>

                <!-- カテゴリ -->
                <div class="mb-3">
                    <label for="category" class="form-label fw-semibold">
                        カテゴリ <span class="text-danger">*</span>
                    </label>
                    <select name="category" id="category" class="form-select<?= isset($entity) && $entity->getError('category') ? ' is-invalid' : '' ?>" required>
                        <option value="">選択してください</option>
                        <?php foreach ($categories as $val => $label): ?>
                            <option value="<?= h($val) ?>"
                                <?= (isset($entity) && $entity->category === $val) ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($entity) && $entity->getError('category')): ?>
                        <div class="invalid-feedback"><?= h(implode(' ', (array)$entity->getError('category'))) ?></div>
                    <?php endif; ?>
                </div>

                <!-- 氏名 -->
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">
                        お名前 <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="name" id="name"
                        class="form-control<?= isset($entity) && $entity->getError('name') ? ' is-invalid' : '' ?>"
                        value="<?= h(isset($entity) ? $entity->name : $defaultName) ?>"
                        maxlength="100" required>
                    <?php if (isset($entity) && $entity->getError('name')): ?>
                        <div class="invalid-feedback"><?= h(implode(' ', (array)$entity->getError('name'))) ?></div>
                    <?php endif; ?>
                </div>

                <!-- メール -->
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">
                        メールアドレス <span class="text-danger">*</span>
                    </label>
                    <input type="email" name="email" id="email"
                        class="form-control<?= isset($entity) && $entity->getError('email') ? ' is-invalid' : '' ?>"
                        value="<?= h(isset($entity) ? $entity->email : $defaultEmail) ?>"
                        maxlength="255" required>
                    <div class="form-text">返信が必要な場合にご連絡します。</div>
                    <?php if (isset($entity) && $entity->getError('email')): ?>
                        <div class="invalid-feedback"><?= h(implode(' ', (array)$entity->getError('email'))) ?></div>
                    <?php endif; ?>
                </div>

                <!-- 内容 -->
                <div class="mb-4">
                    <label for="body" class="form-label fw-semibold">
                        内容 <span class="text-danger">*</span>
                    </label>
                    <textarea name="body" id="body" rows="6"
                        class="form-control<?= isset($entity) && $entity->getError('body') ? ' is-invalid' : '' ?>"
                        maxlength="2000" required
                        placeholder="具体的な内容をご記入ください（10文字以上）"><?= h(isset($entity) ? $entity->body : '') ?></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <span id="body-count" class="form-text">0 / 2000文字</span>
                    </div>
                    <?php if (isset($entity) && $entity->getError('body')): ?>
                        <div class="invalid-feedback"><?= h(implode(' ', (array)$entity->getError('body'))) ?></div>
                    <?php endif; ?>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">送信する</button>
                </div>

                <?= $this->Form->end() ?>
            </div>
        </div>

    </div>
</div>

<?php if (!empty($myContacts)): ?>
<div class="row justify-content-center mt-4">
    <div class="col-12 col-md-8 col-lg-6">
        <h6 class="fw-semibold mb-3 text-muted">
            <i class="bi bi-clock-history me-1"></i>これまでのお問い合わせ
        </h6>

        <?php foreach ($myContacts as $myContact): ?>
            <div class="card shadow-sm mb-3" id="contact-<?= h($myContact->id) ?>">
                <div class="card-header bg-light d-flex align-items-center justify-content-between">
                    <span>
                        <span class="badge bg-secondary me-2"><?= h($myContact->category) ?></span>
                        <span class="text-muted small"><?= h($myContact->created->format('Y-m-d H:i')) ?></span>
                    </span>
                </div>
                <div class="card-body">
                    <div style="white-space: pre-wrap;"><?= h($myContact->body) ?></div>

                    <?php if (!empty($myContact->t_contact_replies)): ?>
                        <div class="mt-3 d-flex flex-column gap-2">
                            <?php foreach ($myContact->t_contact_replies as $reply): ?>
                                <?php $isAdminReply = $reply->author_type !== 'user'; ?>
                                <div class="d-flex <?= $isAdminReply ? 'justify-content-start' : 'justify-content-end' ?>">
                                    <div class="p-2 px-3 rounded-3 <?= $isAdminReply ? 'bg-primary bg-opacity-10' : 'bg-light border' ?>" style="max-width: 85%;">
                                        <div class="text-muted small mb-1">
                                            <?= $isAdminReply ? '<i class="bi bi-headset me-1"></i>担当者' : '<i class="bi bi-person me-1"></i>ご自身' ?>
                                            ・<?= h($reply->sent_at->format('Y-m-d H:i')) ?>
                                        </div>
                                        <div style="white-space: pre-wrap;"><?= h($reply->body) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?= $this->Form->create(null, [
                        'url'   => ['controller' => 'Contacts', 'action' => 'reply', $myContact->id],
                        'class' => 'mt-3',
                    ]) ?>
                    <div class="input-group">
                        <?= $this->Form->textarea('reply_body', [
                            'class'       => 'form-control',
                            'rows'        => 2,
                            'placeholder' => '追加で返信する...',
                            'required'    => true,
                        ]) ?>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var textarea = document.getElementById('body');
    var counter  = document.getElementById('body-count');
    if (!textarea || !counter) return;

    function update() {
        var len = textarea.value.length;
        counter.textContent = len + ' / 2000文字';
        counter.classList.toggle('text-danger', len > 2000);
    }

    textarea.addEventListener('input', update);
    update();
})();
</script>
