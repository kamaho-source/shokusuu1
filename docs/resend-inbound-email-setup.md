# お問い合わせ「メール返信」取り込み設定（Resend Inbound）

## 概要

お問い合わせへの返信メールに対して、利用者がメールソフトでそのまま「返信」すると、その内容が自動でお問い合わせスレッドに取り込まれる機能です（PR #713）。

Resend の **Inbound（受信メール）Webhook** を使って実現しています。この文書は、本番環境でこの機能を有効にするために**外部サービス側で必要な設定手順**をまとめたものです。コード側の実装は完了しており、ここでの作業のみで機能が有効になります。

| 項目 | 内容 |
|------|------|
| 対象PR | [#713](https://github.com/kamaho-source/shokusuu1/pull/713)（[#712](https://github.com/kamaho-source/shokusuu1/pull/712) の上に積まれている） |
| 受信方式 | Resend Inbound Webhook（`email.received`） |
| 受信エンドポイント | `POST /webhooks/resend/inbound`（アプリ側は実装済み） |
| 署名検証 | Svix HMAC-SHA256（`svix-id` / `svix-timestamp` / `svix-signature`） |
| この手順の実施者 | **ご本人**（本番ドメインのDNS・Resendダッシュボードの操作のため） |

---

## 事前に決めること：受信用サブドメイン

既存の送信用ドメイン（`kamaho-shokusu.jp`）に直接MXレコードを追加すると、会社の既存メール受信に影響するおそれがあります。**受信専用のサブドメインを新しく用意することを強く推奨します。**

例：`reply.kamaho-shokusu.jp`

このサブドメイン名は以降の手順で使うので、別の名前にする場合はこのドキュメント内の `reply.kamaho-shokusu.jp` を読み替えてください。

---

## 手順

### 1. Resendダッシュボードで受信ドメインを設定する

1. [Resendダッシュボード](https://resend.com/domains) にログイン
2. 「Emails」→「Receiving」タブを開く
3. 受信用アドレスを設定する
   - すぐ試したい場合：Resendが自動で用意する `<id>.resend.app` 形式のアドレスを使う（DNS設定不要・動作確認用）
   - 本番運用の場合：「Receiving address」メニューから独自ドメイン（`reply.kamaho-shokusu.jp`）を追加
4. 独自ドメインを追加した場合、画面に表示される **MXレコードの値**を、ドメインのDNS管理画面（お名前.com、Cloudflare等、現在DNSを管理しているサービス）に追加する
   - 具体的なMXレコードの値はResendの画面に表示されるものをそのまま使ってください（この文書作成時点では値が変動するため、ここには記載していません）
5. DNS反映後、Resendダッシュボード上で検証（Verify）が通ることを確認する

### 2. Webhookを登録する

1. Resendダッシュボードの「Webhooks」セクションを開く
2. 「Add Webhook」で新規作成
3. Endpoint URLに以下を設定する

   ```
   https://kamaho-shokusu.jp/kamaho-shokusu/webhooks/resend/inbound
   ```

   （本番のURLパス構成が異なる場合は実際のドメイン・パスに読み替え）

4. イベントは **`email.received`** のみを選択
5. 作成すると、Webhook固有の **Signing Secret**（`whsec_...` から始まる文字列）が発行される。この値を控えておく（次の手順で使用）

### 3. 本番環境変数を設定する

本番サーバーの環境変数（`app_local.php` または `.env`、現行の運用方法に合わせる）に以下を追加する。

| 変数名 | 値 | 必須/任意 |
|--------|-----|----------|
| `RESEND_WEBHOOK_SECRET` | 手順2で発行された Signing Secret（`whsec_...`） | **必須** |
| `App.inboundReplyDomain`（Configure値） | 手順1で設定した受信用ドメイン（例: `reply.kamaho-shokusu.jp`） | 手順1のドメインが `reply.kamaho-shokusu.jp` と異なる場合のみ設定 |

`App.inboundReplyDomain` を設定しない場合、コード側のデフォルト値 `reply.kamaho-shokusu.jp` が使われます（`src/Service/ContactService.php` の `INBOUND_REPLY_DOMAIN_DEFAULT`）。手順1で別の名前を使った場合は、`app_local.php` の `App` 設定に以下のように追加してください。

```php
'App' => [
    // ...既存の設定...
    'inboundReplyDomain' => 'reply.kamaho-shokusu.jp',
],
```

設定反映後、アプリケーションキャッシュをクリアする。

```bash
bin/cake cache clear_all
```

### 4. 動作確認する

1. 本番環境のお問い合わせ（`/Contacts`）からテスト問い合わせを送信する
2. 管理画面（`/Contacts/admin`）から返信を送る
3. 届いたメール（送信元: `support@kamaho-shokusu.jp`）のヘッダーに `Reply-To: reply+{トークン}@{受信ドメイン}` が設定されていることを確認する（メールソフトの「元のメッセージを表示」等で確認可能）
4. そのメールに「返信」して送信する
5. しばらくしてから `/Contacts/admin` の問い合わせ一覧を開き、「要対応」バッジが表示され、詳細画面に「ご本人からの返信」として内容が反映されていることを確認する

反映されない場合は「トラブルシューティング」を参照してください。

---

## トラブルシューティング

| 症状 | 確認すること |
|------|-------------|
| Webhookが届かない | Resendダッシュボードの「Webhooks」→対象Webhookの配信ログ（Delivery log）でエラーの有無を確認。404ならURLが間違っている |
| 401が返ってくる | `RESEND_WEBHOOK_SECRET` が、Resend側で発行された値と一致しているか確認。環境変数反映後に `bin/cake cache clear_all` を実行したか確認 |
| 返信が反映されない（200は返っている） | 返信メールの宛先が `reply+{トークン}@{受信ドメイン}` になっているか確認（メールソフトが勝手に別アドレスへ返信している可能性がある）。古い問い合わせ（本機能リリース前に作成されたもの）には `reply_token` が発行されていないため対象外 |
| 本文取得でエラーになる | `RESEND_API_KEY` が正しく設定されているか確認。サーバーログ（`logs/error.log`）に `Resend inbound email fetch failed` の記録があればそのステータスコードを確認 |

---

## 補足：このPRで追加されたコード側の仕組み（参考）

- `src/Controller/WebhooksController.php`：Webhook受信エンドポイント。署名検証後、該当するお問い合わせを特定して返信を保存する
- `src/Infrastructure/Email/ResendWebhookVerifier.php`：Svix署名検証（純粋関数、ネットワーク非依存）
- `src/Infrastructure/Email/ResendInboundClient.php`：Resend APIから受信メール本文を取得するクライアント
- `src/Domain/ValueObject/EmailQuoteStripper.php`：返信メールから引用された元メール部分を除去する
- `src/Service/ContactService.php`：`addUserReplyByToken()` がトークンから問い合わせを特定し、スレッドへ保存する

これらはテストコードで動作検証済みです（`vendor/bin/phpunit` 668件通過）。この文書の手順はResend側・DNS側の設定のみを対象としています。
