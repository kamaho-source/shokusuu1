<?php
/**
 * @var \App\View\AppView $this
 * @var array $errors
 * @var array $data
 */
$this->assign('title', 'トライアル追加');
$errors ??= [];
$data   ??= [];
?>
<!-- ── パンくず ── -->
<nav aria-label="breadcrumb" class="mb-1">
    <ol class="breadcrumb breadcrumb-sm">
        <li class="breadcrumb-item"><a href="<?= $this->Url->build('/') ?>" class="text-decoration-none text-muted">管理</a></li>
        <li class="breadcrumb-item"><a href="<?= $this->Url->build(['action' => 'index']) ?>" class="text-decoration-none text-muted">トライアルユーザー管理</a></li>
        <li class="breadcrumb-item active text-muted">トライアルを追加</li>
    </ol>
</nav>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="d-flex align-items-center gap-2 mb-4">
            <div class="d-flex align-items-center justify-content-center rounded-circle bg-info shadow-sm text-white"
                 style="width:44px;height:44px;flex-shrink:0;">
                <i class="bi bi-building-add fs-5"></i>
            </div>
            <div>
                <h1 class="h5 fw-bold mb-0">トライアルを追加</h1>
                <p class="text-muted small mb-0">新しいテナントをトライアル（30日）で登録します。</p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?= $this->Form->create(null, [
            'url'   => ['action' => 'add'],
            'type'  => 'post',
            'class' => 'needs-validation',
        ]) ?>

        <!-- 法人・テナント情報 -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <i class="bi bi-building text-info"></i>
                <span class="fw-semibold small">法人・テナント情報</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label small fw-semibold">法人名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('name', [
                            'class'       => 'form-control form-control-sm',
                            'placeholder' => '例: 社会福祉法人〇〇会',
                            'value'       => $data['name'] ?? '',
                            'required'    => true,
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold">テナントコード <span class="text-danger">*</span></label>
                        <?= $this->Form->text('tenant_code', [
                            'class'       => 'form-control form-control-sm',
                            'placeholder' => '例: marukai-home',
                            'value'       => $data['tenant_code'] ?? '',
                            'required'    => true,
                            'pattern'     => '[a-z0-9\-]{3,30}',
                        ]) ?>
                        <div class="form-text">半角英小文字・数字・ハイフン（3〜30文字）。サブドメインに使用します。</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">施設名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('facility_name', [
                            'class'       => 'form-control form-control-sm',
                            'placeholder' => '例: 〇〇児童養護施設',
                            'value'       => $data['facility_name'] ?? '',
                            'required'    => true,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 担当者・請求情報 -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <i class="bi bi-person-badge text-info"></i>
                <span class="fw-semibold small">担当者・請求先情報</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">担当者名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('contact_name', [
                            'class'    => 'form-control form-control-sm',
                            'value'    => $data['contact_name'] ?? '',
                            'required' => true,
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">メールアドレス <span class="text-danger">*</span></label>
                        <?= $this->Form->email('contact_email', [
                            'class'    => 'form-control form-control-sm',
                            'value'    => $data['contact_email'] ?? '',
                            'required' => true,
                        ]) ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">請求先住所</label>
                        <?= $this->Form->textarea('billing_address', [
                            'class'       => 'form-control form-control-sm',
                            'rows'        => 2,
                            'placeholder' => '〒000-0000 〇〇県〇〇市…',
                            'value'       => $data['billing_address'] ?? '',
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 管理者アカウント -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <i class="bi bi-key text-info"></i>
                <span class="fw-semibold small">初期管理者アカウント</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">ログインID <span class="text-danger">*</span></label>
                        <?= $this->Form->text('login_account', [
                            'class'        => 'form-control form-control-sm',
                            'placeholder'  => 'admin',
                            'value'        => $data['login_account'] ?? '',
                            'required'     => true,
                            'autocomplete' => 'off',
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">パスワード <span class="text-danger">*</span></label>
                        <?= $this->Form->password('login_password', [
                            'class'        => 'form-control form-control-sm',
                            'placeholder'  => '8文字以上',
                            'required'     => true,
                            'autocomplete' => 'new-password',
                            'minlength'    => 8,
                        ]) ?>
                    </div>
                </div>
                <div class="alert alert-info border-0 mt-3 mb-0 py-2 px-3 small">
                    <i class="bi bi-info-circle me-1"></i>
                    このアカウントは <strong>テナント管理者（i_admin=4）</strong> として作成されます。
                    登録後は <code><?= $this->Url->build('/') ?>{tenant_code}.{ドメイン}/</code> からログインできます。
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
            <a href="<?= $this->Url->build(['action' => 'index']) ?>" class="btn btn-outline-secondary btn-sm px-4">
                キャンセル
            </a>
            <button type="submit" class="btn btn-info btn-sm text-white px-4">
                <i class="bi bi-check-lg me-1"></i>登録する
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
