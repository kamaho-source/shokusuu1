<?php
/**
 * @var \App\View\AppView $this
 * @var string[] $errors
 * @var array $data
 */
$this->assign('title', '無料トライアル申し込み');
$errors ??= [];
$data   ??= [];
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-7">

        <!-- ヒーロー -->
        <div class="text-center mb-5">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-info shadow mb-3"
                 style="width:64px;height:64px;">
                <i class="bi bi-building-add text-white fs-3"></i>
            </div>
            <h1 class="h3 fw-bold">30日間無料トライアル</h1>
            <p class="text-muted">クレジットカード不要。申し込みから30分以内でご利用開始いただけます。</p>
            <div class="d-flex justify-content-center gap-4 mt-3 flex-wrap">
                <span class="text-muted small"><i class="bi bi-check-circle-fill text-info me-1"></i>30日間無料</span>
                <span class="text-muted small"><i class="bi bi-check-circle-fill text-info me-1"></i>カード不要</span>
                <span class="text-muted small"><i class="bi bi-check-circle-fill text-info me-1"></i>いつでも解約</span>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>入力内容をご確認ください</div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li class="small"><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?= $this->Form->create(null, [
            'url'  => ['action' => 'register'],
            'type' => 'post',
        ]) ?>

        <!-- 法人・施設情報 -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <span class="d-flex align-items-center justify-content-center rounded bg-info bg-opacity-10 text-info"
                      style="width:28px;height:28px;font-size:0.9rem;">
                    <i class="bi bi-building"></i>
                </span>
                <span class="fw-semibold small">法人・施設情報</span>
                <span class="badge bg-info bg-opacity-10 text-info ms-auto" style="font-size:0.7rem;">Step 1</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">法人名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('name', [
                            'class'       => 'form-control',
                            'placeholder' => '例: 社会福祉法人〇〇会',
                            'value'       => $data['name'] ?? '',
                            'required'    => true,
                        ]) ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">施設名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('facility_name', [
                            'class'       => 'form-control',
                            'placeholder' => '例: 〇〇児童養護施設',
                            'value'       => $data['facility_name'] ?? '',
                            'required'    => true,
                        ]) ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">
                            テナントコード <span class="text-danger">*</span>
                        </label>
                        <?= $this->Form->text('tenant_code', [
                            'class'       => 'form-control',
                            'placeholder' => '例: marukai-home',
                            'value'       => $data['tenant_code'] ?? '',
                            'required'    => true,
                            'pattern'     => '[a-z0-9\-]{3,30}',
                        ]) ?>
                        <div class="form-text">
                            半角英小文字・数字・ハイフンのみ、3〜30文字。
                            ログインURLのサブドメイン部分になります（例: <code>marukai-home</code>.shokusu.jp）。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 担当者情報 -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <span class="d-flex align-items-center justify-content-center rounded bg-info bg-opacity-10 text-info"
                      style="width:28px;height:28px;font-size:0.9rem;">
                    <i class="bi bi-person-badge"></i>
                </span>
                <span class="fw-semibold small">担当者情報</span>
                <span class="badge bg-info bg-opacity-10 text-info ms-auto" style="font-size:0.7rem;">Step 2</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">担当者名 <span class="text-danger">*</span></label>
                        <?= $this->Form->text('contact_name', [
                            'class'    => 'form-control',
                            'value'    => $data['contact_name'] ?? '',
                            'required' => true,
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">メールアドレス <span class="text-danger">*</span></label>
                        <?= $this->Form->email('contact_email', [
                            'class'    => 'form-control',
                            'value'    => $data['contact_email'] ?? '',
                            'required' => true,
                        ]) ?>
                        <div class="form-text">ウェルカムメールをお送りします。</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 管理者アカウント -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <span class="d-flex align-items-center justify-content-center rounded bg-info bg-opacity-10 text-info"
                      style="width:28px;height:28px;font-size:0.9rem;">
                    <i class="bi bi-key"></i>
                </span>
                <span class="fw-semibold small">管理者ログインアカウント</span>
                <span class="badge bg-info bg-opacity-10 text-info ms-auto" style="font-size:0.7rem;">Step 3</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">ログインID <span class="text-danger">*</span></label>
                        <?= $this->Form->text('login_account', [
                            'class'        => 'form-control',
                            'placeholder'  => '例: admin',
                            'value'        => $data['login_account'] ?? '',
                            'required'     => true,
                            'autocomplete' => 'off',
                        ]) ?>
                        <div class="form-text">登録した施設のサブドメインでのみ有効です。</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">パスワード <span class="text-danger">*</span></label>
                        <?= $this->Form->password('login_password', [
                            'class'        => 'form-control',
                            'placeholder'  => '8文字以上',
                            'required'     => true,
                            'autocomplete' => 'new-password',
                            'minlength'    => 8,
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">パスワード（確認） <span class="text-danger">*</span></label>
                        <?= $this->Form->password('login_password_confirm', [
                            'class'        => 'form-control',
                            'placeholder'  => '同じパスワードを入力',
                            'required'     => true,
                            'autocomplete' => 'new-password',
                            'minlength'    => 8,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 利用規約 -->
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="agree" required>
            <label class="form-check-label small" for="agree">
                <a href="#" class="text-info">利用規約</a>および<a href="#" class="text-info">プライバシーポリシー</a>に同意します
            </label>
        </div>

        <div class="d-grid mb-4">
            <button type="submit" class="btn btn-info btn-lg text-white shadow-sm">
                <i class="bi bi-building-add me-2"></i>
                30日間無料トライアルを開始する
            </button>
        </div>

        <p class="text-center text-muted small">
            すでにアカウントをお持ちの方は
            <?= $this->Html->link('ログイン', ['controller' => 'MUserInfo', 'action' => 'login'], ['class' => 'text-info']) ?>
        </p>

        <?= $this->Form->end() ?>
    </div>
</div>
