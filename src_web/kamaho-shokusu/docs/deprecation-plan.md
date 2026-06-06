# Deprecation削減計画

## 目的

- テスト/CIログのノイズを削減し、実障害の検知精度を上げる
- PHP 8.5 以降での互換性リスクを下げる

## 現状

- CI (`deprecations` job) で件数と top files は取得済み
- 主な発生源は vendor 依存（`cakephp/cakephp`, `league/container`, `react/promise`）

## 優先順位

1. `cakephp/cakephp` 系
2. `league/container` 系
3. `react/promise` 系

## 実行ステップ

1. 依存更新候補の棚卸し
 - `composer outdated` で patch/minor 更新可能範囲を確認
 - 互換性破壊を含む major 更新は別チケット化

2. 低リスク更新の先行適用
 - patch/minor を優先して更新
 - `composer test` / CI でdeprecation件数の減少を確認

3. 高リスク更新の段階適用
 - 影響が大きいものは1ライブラリずつ更新
 - 変更ごとにロールバック可能なコミット粒度で実施

4. 継続運用
 - `deprecations` ジョブの件数を週次で監視
 - 前週比で増加した場合は増加元を調査して即修正

## 完了条件

- `deprecations` ジョブの件数が直近基準より継続的に減少
- 主要 warning/deprecation の発生源が更新済みまたは対応方針確定
