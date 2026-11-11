# Crew rules（shokusuu1 食数管理システム）

このファイルは、コントローラーとレーン（作業スレッド）の約束事です。持つのは「設定値」と「どこを見ればよいかの
ポインタ」だけで、やり方そのものは gstack（`~/.claude/skills/gstack/docs/captain.md`、ship・review・pregate の
各ドキュメント）とこのリポジトリの `CLAUDE.md`・`AGENTS.md` にあります。ルールを足すときは末尾に足し、
番号は振り直しません。オーナーの言葉に基づくルールには、日付と言葉を残します。

## 設定値

| パラメータ | 値 |
|---|---|
| `REPO` | `kamaho-source/shokusuu1` |
| `BASE` | `develop`（トレイン実行時は `--base develop`。`develop → release → main` の昇格はトレインの外） |
| `REQUIRED_CHECKS` | `phpunit`, `branch-check`（`develop`・`main` の保護設定と同じ） |
| `FULL_GATE_CHECK` | なし（gates 形式。積み重ねた状態ごとに `phpunit.yml` の `phpunit` ジョブを検証する） |
| `TRAIN_MAX`, `FILL_HOURS` | `3`, `1`（緊急は `--urgent` で1本だけ） |
| `WAVE_TRIGGER` | `6` |
| `MERGE_METHOD` | `merge`（履歴が `Merge pull request #…` で揃っているため。混ぜない） |
| `STAMP_FILES` | なし（`VERSION`・`CHANGELOG.md` は無い。版の刻印はしない） |
| `KNOWN_INFRA_FLAKES` | なし |
| `SPEND` | なし（有料の評価は使わない） |
| `CONTROLLER` | オーナーのこの Claude Code セッション（ask と READY はここに送る） |
| `GUARD_CHECKS` | `phpstan`（`vendor/bin/phpstan analyse`）、`jest`、`var_dump`/`die` を残さない、認証情報をハードコードしない（`CLAUDE.md` §10） |
| `REMOTE_PREGATE` | なし |
| `REPO_CONVENTIONS` | `CLAUDE.md` §2〜§10 と `docs/coding-conventions.md`（クリーンアーキテクチャ・`else` 禁止・`mixed` 禁止・PHPDoc） |
| `REVIEW_SCOPE` | `in-diff` |
| `CONTRACT_DATE` | `2026-11-12` |

## 決定の記録（オーナーの言葉）

| ルール | 日付 | 言葉 | 範囲 |
|---|---|---|---|
| トレイン導入 | 2026-11-12 | 「トレインまで導入」「do it」 | `develop` 向けにトレインを導入する。最大3本・1時間待ち（A1）、マージ方式は `merge`（B1）、承認レビューは今までどおり管理者権限で通す（C1） |
| 本番リリース | 2026-11-11 | 「本番にリリースして」 | `release → main`（#1008）のマージ。**この1回限り**の合図。次回以降は都度の合図を取る |

> 「5 spend」「16 flaky reruns」「21 approvals」「22 /ship before READY」「23 blocking floor」
> 「24 plan before build」の包括的な承認（テンプレートの表）は、オーナーの言葉がまだ無いため記録していない。
> 言葉を受け取ったら、日付とともにこの表に足す。

## ルール

1. **セッション開始。** gstack の導入確認をしてから始める。ホーム: `docs/capy-install.md`。
2. **検証の規律。** 直す前に原因を突き止め、直している間は絞ったテスト、最後に凍結したツリーで全テストを1回。
   ホーム: `captain.md`（Lane discipline）と `REPO_CONVENTIONS`。
3. **外部の差分は信用しない。** 正しい修正は、作者のクレジットつきで取り込む。ホーム: `captain.md`（Contributions from outside the crew）。
4. **ファイルの持ち主。** 自分の持ち分だけを編集する。持ち分は digest の In progress に載る。ホーム: `captain.md`（Lane discipline）。
5. **費用。** `SPEND` はなし。有料の実行が要るときは、必ず `CONTROLLER` 経由で新たに聞く。
6. **レーンの仕事は、刻印なしの READY で終わる。** `gstack-ship-queue ready --pr <n> --no-screen` のブロックを、
   40桁の head SHA つきで送る。マージ・自動マージの有効化は絶対にしない。刻印（stamp）はこのリポジトリでは行わない
   （`STAMP_FILES` なし）。ホーム: `captain.md`（The READY contract）、`captain/templates/READY.md`。
7. **報告。** コントローラーのドライブの `reports/<スレッド名>.md` に、Outcome（`Unverified:` 行つき）・PR・Needs・Cost・Lesson・Approvals を書く。
8. **経路は1本。** すべて `CONTROLLER` 経由。レーン同士で直接やり取りしない。
9. **実ブラウザ・オーナーのログイン状態・ローカル作業** が要るときは、`CONTROLLER` に頼む。
10. **進め続ける。** 戻るのは、新しい範囲・他のレーンとの衝突・アクセス不足・オーナーの判断が要るときだけ。
11. **有料作業の前に `GUARD_CHECKS` を通す。**
12. **オーナーへの相談は平易な言葉で。** 選択肢ごとに、何か・なぜ大事か・答えで何が起きるか・費用を書く。
13. **小さいマシンでの pregate** は、`REMOTE_PREGATE` がないので該当なし。
14. **READY の PR に `BASE` をマージしない。** トレインが実コードの衝突を報告したときの、コントローラーの指示がある場合だけ。
15. **版の刻印・CHANGELOG** は、このリポジトリでは該当なし（`VERSION`・`CHANGELOG.md` が無い。番号は振り直さない）。
16. **flaky の再実行。** 同じツリーで1回だけ。`handoffs/flaky-ledger.md` に記録し、READY に書く。2回落ちたら本物。
17. **新しいファイルは `REPO_CONVENTIONS` に従う。** どの層に属するかを先に明示する（`CLAUDE.md` §10）。
18. **外部からの PR は、バグ報告として扱う。** 7日以内に結論を出し、本物ならクレジットつきで書き直す。
19. **CHANGELOG だけの衝突** は、このリポジトリでは該当なし。
20. **`CONTRACT_DATE` より前の READY** は、新しい receipt が要る。
21. **承認。** 3つの形（レーンごとの記録・計画の ask・マージの ask）。すべての報告と PR 本文に `## Approvals` 表を付ける。
22. **READY の前に `/ship`。** 再 READY でも同じ。READY ブロックに `SHIP_RECEIPT: pr=<n> head=<pin>` を載せる。
23. **レビューは収束させる。** gstack の review convergence 方針に従い、パス数を READY に書く。範囲は `REVIEW_SCOPE`。
24. **作る前に計画。** 何かを作るレーンは、最初に `/autoplan` を走らせ、オーナーの言葉が `APPROVED` になってから作り始める。
    例外: 読み取りだけのレーン、flaky の修正、スロットの刻印、診断済みの不具合の修正、S1 の緊急対応
    （壊れたインストール・赤の `develop`・セキュリティ）。ホーム: `captain.md`（Plan before build）。

## このリポジトリ固有のルール

25. **ブランチは `main` から切る。** `git fetch origin && git switch -c <name> origin/main`。`develop` など他のブランチからは切らない。
    PR のベースは `develop`。ブランチ名の接頭辞は `feature/`・`fix/`・`hotfix/` のみ（`refactor/` などは `branch-check` で落ちる）。
    根拠: `CLAUDE.md` §9 と、グローバル規則（`~/.claude/CLAUDE.md`）。

26. **サーバーには触らない。** 本番・ステージングのサーバーへの SSH、デプロイ、データ変更はしない（GitHub Actions の状態を読むのは可）。
    ステージングは `develop` へのマージで、本番は `main` へのマージで自動デプロイされる。確認は画面から行い、
    ログインはオーナーが行う（パスワードは入力しない）。
    **`main` へのマージ（本番反映）は、そのつどオーナーの合図を取る。** 先の合図（2026-11-11「本番にリリースして」）は #1008 だけを対象とする。

27. **`develop` の保護は変えない。** 承認レビュー1件が必須の設定は、今までどおり管理者権限（`--admin`）で通す（決定の記録: C1）。
    トレインの着地で管理者権限が使えるかは未検証。試走で確かめ、使えなければコントローラーに報告する（保護設定を変えるのは、オーナーの言葉があってから）。

28. **本番リリースはトレインの外。** `develop → release → main` の昇格 PR は、これまでどおり手で作り、トレインに載せない。

29. **ローカル DB の後片付けは、自分が作った行だけ。** ID の範囲指定で消さない。確認用データは、作った ID を控えてから ID 指定で削除する。

30. **画面の修正は、実ブラウザで修正前後を比べる。** 不具合と書く前に、実際の経路で再現する。
