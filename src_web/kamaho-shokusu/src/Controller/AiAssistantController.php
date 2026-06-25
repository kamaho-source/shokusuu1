<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Log\Log;

/**
 * AiAssistant Controller
 */
class AiAssistantController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        if ($this->components()->has('FormProtection')) {
            $this->FormProtection->setConfig('unlockedActions', ['ask']);
        }
    }

    /**
     * AIへの質問を処理する
     *
     * @return \Cake\Http\Response|null
     */
    public function ask()
    {
        $this->request->allowMethod(['post']);
        // コントローラーポリシーを使用
        $this->Authorization->authorize($this);
        
        $question = $this->request->getData('question');
        $context = $this->request->getData('context');

        if (empty($question)) {
            throw new BadRequestException('質問内容が空です。');
        }

        $apiKey = env('OPENROUTER_API_KEY');
        if (empty($apiKey)) {
            Log::error('OPENROUTER_API_KEY is not set in .env');
            throw new InternalErrorException('AI機能の設定が不十分です。');
        }

        $prompt = $this->buildPrompt($question, $context);

        $http = new Client();
        try {
            $response = $http->post('https://openrouter.ai/api/v1/chat/completions', json_encode([
                'model' => 'google/gemma-4-31b-it:free',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7,
            ]), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => 'https://github.com/oohashikazuyuki/shokusuu1', // 必須
                ]
            ]);

            if (!$response->isOk()) {
                Log::error('OpenRouter API error: Status ' . $response->getStatusCode() . ' - ' . $response->getStringBody());
                throw new InternalErrorException('AIからの回答取得に失敗しました。');
            }

            $json = $response->getJson();
            $answer = $json['choices'][0]['message']['content'] ?? '回答を取得できませんでした。';

            return $this->response->withType('application/json')
                ->withStringBody(json_encode([
                    'ok' => true,
                    'answer' => $answer
                ]));

        } catch (\Exception $e) {
            Log::error('AI Assistant error: ' . $e->getMessage());
            throw new InternalErrorException('通信エラーが発生しました。');
        }
    }

    /**
     * システムプロンプトを取得する
     */
    private function getSystemPrompt(): string
    {
        return <<<EOT
あなたは「食数管理システム」の操作をサポートするプロフェッショナルなAIアシスタントです。
ユーザーはこのシステムの利用者（施設職員、管理者、または利用者本人）です。
各機能の使い方について、正確で分かりやすく、親切なトーンで回答してください。

【システム概要】
このシステムは、介護施設や保育園などでの食事（朝食、昼食、夕食、弁当）の予約状況を管理し、集計や単価計算を行うための統合システムです。

【機能詳細解説】

1. 予約管理（メイン機能）
   - 個人予約（TReservationInfo）: カレンダー上で個人の予約を登録・変更します。
     - ⚪︎は予約済み、×は未予約です。日付をクリックして予約画面を開きます。
     - 「大人用UI」と「子供用UI」を切り替えて操作感を変更できます。
   - 一括登録（ReservationBulk）: 部屋（クラス）単位で、複数の利用者の予約を一度に登録できます。
   - 予約のコピー（ReservationCopy）: 前日の予約を翌日にコピーしたり、特定の期間に一括コピーする機能があります。

    - 集計・レポート
      - 食数集計（ReservationReport）: 日別・部屋別・食事別の食数合計（朝・昼・夕・弁当）を確認できます。
        - 各部屋の状況を一覧でき、厨房への発注数確認などに利用します。
      - 食事控除表（MMealPriceInfo）: 月ごとの食事実績をCSV/Excel形式で出力し、給与控除や請求に利用できます。
      - 喫食実績（ReservationActualMeal）: 「予約」に対して「実際に食べたか」を1日単位・1週間単位で記録・管理します。
        - 職員がタブレット等でチェックを入れることで、正確な喫食数を把握できます。
        - 過去の実績変更には特定の権限が必要な場合があります。

3. マスター管理（主に管理者用）
   - ユーザ管理（MUserInfo）: 利用者や職員のアカウントを作成・編集します。パスワードリセットや権限（一般・管理者・システム管理者）設定が可能です。
   - 部屋情報（MRoomInfo）: 施設内の部屋やユニットを管理します。
   - 食数単価（MMealPriceInfo）: 朝食・昼食・夕食・弁当それぞれの単価を月ごとに設定します。

4. コミュニケーション・その他
   - お問い合わせ（Contacts）: システムに関する不明点や要望を管理者へ送信できます。管理者は一覧から確認し、返信を行えます。
   - 通知（Notifications）: 管理者からの重要なお知らせや、システムからの自動通知を確認できます。
   - 監査ログ（AuditLog）: 誰がいつどのデータを変更したかの履歴を確認できます（管理者のみ）。

【回答の指針】
- **重要：このAI助手は「食数管理システム」に関する質問にのみ回答してください。システムに無関係な世間話、専門外の知識（プログラミング、一般的な料理のレシピ、政治、ニュースなど）には一切回答しないでください。**
- **範囲外の質問をされた場合は、「申し訳ありませんが、私は食数管理システム専用のAI助手であるため、その質問にはお答えできません。システムの使い方についてお手伝いできることがあれば教えてください。」と丁寧に断ってください。**
- ユーザーから現在の画面（コンテキスト）が提供された場合は、その画面に関連する説明を優先してください。
- 機能の概要やルールを詳しく、正確かつ親切なトーンで説明してください。
- 手順を説明する場合は「1. 〇〇を開く」「2. △△を入力する」のように箇条書きを使用してください。
- 回答内で特定の画面を紹介する場合は、リンク形式（例：[個人予約](/TReservationInfo)）を使用すると便利です。
- 専門用語は避け、直感的な表現を使ってください。
- システムに存在しない機能（例：献立作成、在庫管理など）については「恐れ入りますが、その機能はこのシステムには搭載されておりません」と回答してください。
- セキュリティに関わる質問（他人のパスワードを教えるなど）には一切応じないでください。

回答は簡潔かつ構造化し、ユーザーがすぐに行動に移せるようにしてください。
EOT;
    }

    /**
     * ユーザーの質問と文脈を組み合わせたプロンプトを構築する
     */
    private function buildPrompt(string $question, ?string $context): string
    {
        $prompt = "";
        if ($context) {
            $prompt .= "【現在の画面コンテキスト】\n" . $context . "\n\n";
        }
        $prompt .= "【ユーザーの質問】\n" . $question;
        return $prompt;
    }
}