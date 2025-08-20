<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', $title ?? 'ユーザー一括登録（ドラッグ＆ドロップ）');
$csrfToken = $this->request->getAttribute('csrfToken');
?>
<div class="row">
    <!-- 左側メニュー -->
    <aside class="col-md-3">
        <div class="list-group">
            <h4 class="list-group-item list-group-item-action active"><?= __('操作') ?></h4>
            <?= $this->Html->link(__('ユーザー情報一覧'), ['action' => 'index'], ['class' => 'list-group-item list-group-item-action']) ?>
        </div>
    </aside>

    <!-- 右側メイン -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0"><?= h($this->fetch('title')) ?></h4>
            </div>
            <div class="card-body">

                <!-- ここから UI 本体 -->
                <meta name="csrfToken" content="<?= h($csrfToken) ?>">
                <meta name="importJsonUrl" content="<?= h($this->Url->build(['controller'=>'MUserInfo','action'=>'importJson'])) ?>">
                <meta name="xlsxLocalSrc" content="<?= h($this->Url->assetUrl('js/xlsx.full.min.js')) ?>">
                <!--（残してOK）CDNからの静的読み込み。失敗時は下の ensureXLSX() がフォールバックで再読込します。 -->
                <script src="https://cdn.jsdelivr.net/npm/xlsx@0.20.2/dist/xlsx.full.min.js" defer></script>
                <?= $this->Html->script('user-import.js', ['defer' => true]) ?>

                <style>
                    :root { --border:#d0d7de; --muted:#666; --primary:#2563eb; --ok:#16a34a; --warn:#ea580c; --err:#dc2626; }
                    .dropzone{border:2px dashed #94a3b8;border-radius:12px;padding:28px;text-align:center;transition:.2s;background:#f8fafc}
                    .dropzone.dragover{background:#eff6ff;border-color:var(--primary)}
                    .muted{color:var(--muted);font-size:12px}
                    .row-inline{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
                    .btn-outline{padding:8px 14px;border-radius:8px;border:1px solid var(--border);background:#fff;cursor:pointer}
                    .btn-outline.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
                    .btn-outline:disabled{opacity:.6;cursor:not-allowed}
                    .progress-lite{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}
                    .bar{height:100%;width:0;background:linear-gradient(90deg, var(--primary), #60a5fa)}
                    .badge-lite{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#f8fafc}
                    .badge-lite.ok{border-color:#86efac;color:#166534;background:#f0fdf4}
                    .badge-lite.warn{border-color:#fdba74;color:#9a3412;background:#fff7ed}
                    .badge-lite.err{border-color:#fca5a5;color:#991b1b;background:#fef2f2}
                    .log{white-space:pre-wrap;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.6;max-height:220px;overflow:auto;border:1px solid #eee;border-radius:8px;padding:10px}
                    #previewWrap{overflow:auto; max-height:360px; border:1px solid #eee; border-radius:8px;}
                    table.preview{width:100%;border-collapse:collapse}
                    table.preview th, table.preview td{border-bottom:1px solid #f0f0f0;padding:8px 10px;font-size:13px}
                    table.preview th{background:#f9fafb;text-align:left;position:sticky;top:0;z-index:1}
                    .guide h5{margin:0 0 8px;font-weight:700}
                    .guide ol{margin:0 0 8px 18px;padding:0}
                    .guide li{margin:4px 0}
                    .kv{display:grid;grid-template-columns:130px 1fr;gap:6px 12px;margin-top:8px}
                    .kv div{font-size:13px}
                    .kv .k{color:#334155}
                    .kv .v{color:#111827}
                    details summary{cursor:pointer;font-weight:600}
                </style>

                <!-- 上段：ドラッグ＆ドロップと操作 -->
                <div class="mb-3">
                    <div id="drop" class="dropzone">
                        <div style="font-size:15px;margin-bottom:6px">ここに <strong>Excel/CSV</strong> をドラッグ＆ドロップ</div>
                        <div class="muted">対応: .xlsx .xls .csv（解析はブラウザ内で実行）</div>
                        <div class="row-inline" style="justify-content:center;margin-top:12px">
                            <button id="browseBtn" type="button" class="btn-outline">ファイルを選択</button>
                            <input id="fileInput" type="file" accept=".xlsx,.xls,.csv" style="display:none">
                            <button id="downloadTemplate" type="button" class="btn-outline">テンプレートをダウンロード</button>
                            <span class="muted">※ Excelが使えない環境では自動でCSVを配布します</span>
                        </div>
                    </div>

                    <div class="row-inline mt-2">
                        <div id="fileInfo" class="muted"></div>
                        <div class="badge-lite" id="countBadge" style="display:none"></div>
                        <span class="badge-lite" id="statusBadge" style="display:none"></span>
                        <button id="downloadErrors" type="button" class="btn-outline" disabled style="display:none">エラー出力</button>
                    </div>

                    <div class="mt-2">
                        <div class="progress-lite"><div class="bar" id="bar"></div></div>
                        <div class="row-inline mt-2">
                            <button id="sendBtn" type="button" class="btn-outline primary" disabled>サーバに送信（500件ずつ）</button>
                            <button id="clearBtn" type="button" class="btn-outline" disabled>リセット</button>
                            <span class="muted">※ 既存の <code>c_login_account</code> はスキップされます。</span>
                        </div>
                    </div>
                </div>

                <!-- 初心者向けガイド -->
                <div class="mb-3 guide">
                    <h5>取り込みガイド（はじめての方向け）</h5>
                    <ol>
                        <li><strong>テンプレートをダウンロード</strong>し、Excel で開きます（Excelが無ければCSVでもOK）。</li>
                        <li>各列を入力します（下表を参考）。<strong>空欄の行は無視</strong>されます。</li>
                        <li>保存して、このページに<strong>ドラッグ＆ドロップ</strong>します。</li>
                        <li>プレビューで <span class="badge-lite ok">OK</span> が多いことを確認し、<strong>サーバに送信</strong>を押します。</li>
                    </ol>

                    <div class="kv">
                        <div class="k"><code>login_id</code>（必須）</div>
                        <div class="v">ログインID（例：<code>u0001</code>）※ 同じIDが既にあるとスキップ</div>

                        <div class="k"><code>name</code>（必須）</div>
                        <div class="v">氏名（例：<code>山田 太郎</code>）</div>

                        <div class="k"><code>role</code>（必須）</div>
                        <div class="v">
                            次のどれかで入力：<br>
                            ・数字：<code>0</code>=職員 / <code>1</code>=児童 / <code>3</code>=その他<br>
                            ・日本語：<code>職員</code> / <code>児童</code> / <code>その他</code>（一部表記ゆれOK）<br>
                            ・英語：<code>staff</code> / <code>child</code> / <code>other</code>
                        </div>

                        <div class="k"><code>staff_id</code></div>
                        <div class="v"><strong>role=職員（または 0 / staff）なら必須</strong>。職員番号など（例：<code>S-001</code>）</div>

                        <div class="k"><code>password</code></div>
                        <div class="v">未入力なら自動生成（12桁）。入力した場合はその値で登録（サーバ側でハッシュ化）</div>
                    </div>

                    <details class="mt-2">
                        <summary>よくあるエラー</summary>
                        <ul style="margin:6px 0 0 18px">
                            <li><strong>role が不正</strong>：上の表にある値以外は不可です。</li>
                            <li><strong>職員なのに staff_id が空</strong>：<code>role=職員/0/staff</code> のときは必ず入れてください。</li>
                            <li><strong>重複する login_id</strong>：既に同じIDがあると取り込まれません。</li>
                        </ul>
                    </details>
                </div>

                <!-- プレビュー（カード内で高さ制限） -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-end">
                        <div style="font-weight:600">プレビュー（先頭 50 行）</div>
                        <div class="muted" id="headerNote">見出しは自動マッピングされます。</div>
                    </div>
                    <div id="previewWrap" class="mt-2">
                        <table class="preview" id="previewTable">
                            <thead><tr><th>Row</th><th>login_id</th><th>name</th><th>role</th><th>staff_id</th><th>password</th><th>age</th><th>age_group</th><th>gender</th><th>検査</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- ログ -->
                <div>
                    <div style="font-weight:600;margin-bottom:6px">ログ</div>
                    <div id="status" class="log"></div>
                </div>
                <!-- ここまで UI 本体 -->

            </div>
        </div>
    </div>
</div>

