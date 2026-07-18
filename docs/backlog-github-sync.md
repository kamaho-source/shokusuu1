# Backlog ↔ GitHub Issue 双方向連携

## 概要

BacklogとGitHub Issueを双方向に自動同期する仕組みです。

| 方向 | トリガー | 内容 |
|------|----------|------|
| Backlog → GitHub | 15分ごとのスケジュール | Backlog課題の作成・更新・完了をGitHub Issueへ反映 |
| GitHub → Backlog | Issue イベント | GitHub Issueの作成・編集・クローズ・再オープンをBacklog課題へ反映 |
| GitHub → Backlog | Issue Comment イベント | GitHub IssueへのコメントをBacklogコメントへ反映 |

---

## ファイル構成

```
.github/
├── scripts/
│   ├── backlog_client.py              # Backlog API共通クライアント
│   ├── sync_utils.py                  # GitHub API共通ユーティリティ
│   ├── backlog_to_github.py           # Backlog → GitHub 同期スクリプト
│   ├── github_to_backlog.py           # GitHub → Backlog 同期スクリプト
│   └── github_comment_to_backlog.py   # GitHub コメント → Backlog 同期スクリプト
└── workflows/
    ├── backlog-to-github-issue.yml         # Backlog → GitHub ワークフロー
    ├── github-issue-to-backlog.yml         # GitHub → Backlog ワークフロー
    └── github-issue-comment-to-backlog.yml # コメント同期ワークフロー
```

---

## 必要な GitHub Secrets

| Secret名 | 値 | 登録方法 |
|----------|----|----------|
| `BACKLOG_API_KEY` | BacklogのAPIキー | **手動登録が必要** |
| `BACKLOG_SPACE_ID` | `kamaho` | 自動登録済み |
| `BACKLOG_PROJECT_KEY` | `SHOKUSU` | 自動登録済み |
| `BACKLOG_DOMAIN` | `backlog.com` | 自動登録済み |

### BACKLOG_API_KEY の登録方法

1. Backlogにログインし、[個人設定] → [API] からAPIキーを発行
2. 以下のコマンドで登録:

```bash
gh secret set BACKLOG_API_KEY --body "取得したAPIキー" --repo kamaho-source/shokusuu1
```

または GitHub リポジトリの Settings → Secrets and variables → Actions から手動登録。

---

## 動作仕様

### Backlog → GitHub Issue 同期 (`backlog-to-github-issue.yml`)

- 15分ごとに実行（手動実行も可能）
- 指定分数前（デフォルト: 20分）から更新されたBacklog課題を取得
- **重複防止**: タイトルの `[SHOKUSU-XXX]` プレフィックスとbody内のHTMLコメントマーカーで既存Issueを検索
- **無限ループ防止**: `GitHub Issue: https://github.com/` が説明に含まれる課題はGitHub起源と判断し、新規作成をスキップ（ステータス変更のみ反映）
- Backlogの「完了」ステータス → GitHub Issueのcloseに反映

### GitHub Issue → Backlog課題 同期 (`github-issue-to-backlog.yml`)

- `opened`: 新規Backlog課題を作成。GitHub IssueのタイトルとbodyをBacklog課題に反映。GitHub Issue側にBacklog課題キーを追記
- `edited`: Backlog課題のタイトル・説明を更新。差分がある場合のみBacklogにコメントを追加
- `closed`: Backlog課題のステータスを「完了」に変更
- `reopened`: Backlog課題のステータスを「未対応」に変更
- **無限ループ防止**: `<!-- backlog-synced -->` マーカーが含まれるIssueはBacklog起源として新規作成をスキップ

### GitHub コメント → Backlogコメント同期 (`github-issue-comment-to-backlog.yml`)

- Issue Commentの`created`・`edited`イベントで発火
- PRのコメントは除外（`if: ${{ !github.event.issue.pull_request }}`）
- **重複防止**: `<!-- github-comment-id:XXX -->` マーカーで既存コメントを確認

---

## マーカー仕様

| マーカー | 場所 | 用途 |
|----------|------|------|
| `<!-- backlog-synced -->` | GitHub Issue body | Backlog起源のIssueであることを示す |
| `<!-- backlog-key:SHOKUSU-XXX -->` | GitHub Issue body | 対応するBacklog課題キーを保持 |
| `<!-- github-sync:start -->` / `<!-- github-sync:end -->` | Backlog課題説明 | GitHub同期範囲を区切る |
| `<!-- github-comment-id:XXX -->` | Backlogコメント | 同期済みGitHubコメントIDを保持（重複防止） |

---

## 手動実行

`backlog-to-github-issue` ワークフローは手動実行が可能です。

```bash
gh workflow run backlog-to-github-issue.yml \
  --repo kamaho-source/shokusuu1 \
  -f minutes_back=60
```

`minutes_back` に大きな値を指定することで、過去の課題も一括同期できます。

---

## トラブルシューティング

### 認証エラー（HTTP 401）

```
[ERROR] Backlog APIの認証に失敗しました。
```

確認項目:
- `BACKLOG_API_KEY` Secret が正しく登録されているか
- Secretの値に前後の空白や改行が含まれていないか
- `BACKLOG_SPACE_ID` が `kamaho` になっているか
- `BACKLOG_DOMAIN` が `backlog.com` になっているか

### 同期が止まっている場合

GitHub Actions のログを確認:

```bash
gh run list --workflow=backlog-to-github-issue.yml --repo kamaho-source/shokusuu1
gh run view <RUN_ID> --log --repo kamaho-source/shokusuu1
```
