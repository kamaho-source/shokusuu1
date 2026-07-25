# Playwright E2E（手動確認の自動化）

既定のベース URL: `http://localhost:8765`（`playwright.config.mjs` / `E2E_BASE_URL`）

## セットアップ

```bash
# リポジトリルート
npm install
npx playwright install chromium

# アプリ起動（別ターミナル）
cd src_web/kamaho-shokusu
bin/cake server -p 8765
```

## 認証

| 環境変数 | 説明 | 既定 |
|---|---|---|
| `E2E_USER` / `E2E_PASS` | 管理者または職員 | `e2e_admin` / `E2eTest#2026` |
| `E2E_BLOCK_LEADER_USER` / `E2E_BLOCK_LEADER_PASS` | #586 用（任意） | 未設定時スキップ |
| `E2E_BLOCK_LEADER_TARGET_USER_ID` / `E2E_BLOCK_LEADER_ROOM_ID` | #586 の対象 | 未設定時スキップ |
| `E2E_BASE_URL` | アプリ URL | `http://localhost:8765` |

## バグ調査スイート (#583〜#589)

```bash
# headless
npm run test:e2e:bugfix

# ブラウザ表示あり
npm run test:e2e:bugfix:headed
```

| テスト | 対応 Issue |
|---|---|
| getMealCounts JSON / 400 | #584 |
| processToggle 過去日拒否 | #585 |
| direct-register 成功形状・昼食弁当競合 | #583 |
| getAllRoomsMealCounts | #587 |
| formatLocalYmd / カレンダー JS | #588 |
| Contacts フォーム送信 | #589 |
| ブロック長 toggle（任意） | #586 |

#590（TOCTOU）・#591（PHPUnit）はブラウザ E2E の対象外です。
