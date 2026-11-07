#!/bin/bash
set -e

DATE=$(date +%y%m%d)
CONTAINER_NAME="kamakura-shokusu_web_db"
BACKUP_DIR="${BACKUP_DIR:-/home/ubuntu/backups/shokusu}"
DB_NAME="${DB_NAME:-shokusu}"
DB_USER="${DB_USER}"
DB_PASS="${DB_PASS}"

# コンテナ内でダンプ（上書き）
docker exec "$CONTAINER_NAME" \
  mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --result-file=/tmp/dump.sql

# コンテナ外へコピー
mkdir -p "$BACKUP_DIR"
docker cp "$CONTAINER_NAME":/tmp/dump.sql "${BACKUP_DIR}/dump_${DATE}.sql"

echo "Backup saved: ${BACKUP_DIR}/dump_${DATE}.sql"

# 7日以上経過したバックアップファイルを削除
find "$BACKUP_DIR" -name "dump_*.sql" -mtime +7 -delete

echo "Old backups cleaned up."

# ── Google Drive への退避（#652）────────────────────────────────
# サーバーの rclone に GDRIVE_REMOTE のリモートが設定されているときだけ動く（認証はサーバー上の rclone.conf に置き、リポジトリには置かない）。
# 退避に失敗しても、上のローカルのバックアップは成功のまま終える。
GDRIVE_REMOTE="${GDRIVE_REMOTE:-gdrive:shokusu-backups}"
GDRIVE_KEEP_DAYS="${GDRIVE_KEEP_DAYS:-14}"
REMOTE_NAME="${GDRIVE_REMOTE%%:*}:"

if ! command -v rclone >/dev/null 2>&1 || ! rclone listremotes 2>/dev/null | grep -qxF "$REMOTE_NAME"; then
  echo "Drive upload skipped: rclone remote ${REMOTE_NAME} is not configured."
  exit 0
fi

# 同じ名前のファイルは送り直さないので、毎回ローカルにある分（直近7日）をまとめて送る
if ! rclone copy "$BACKUP_DIR" "$GDRIVE_REMOTE" --include "dump_*.sql"; then
  echo "WARNING: Drive upload failed. Local backup is kept." >&2
  exit 0
fi
echo "Drive upload done: ${GDRIVE_REMOTE}"

# Drive 上は、ファイル名の日付（dump_YYMMDD.sql）が GDRIVE_KEEP_DAYS 日より前のものを消す（更新日時は使わない）
CUTOFF=$(date -d "-${GDRIVE_KEEP_DAYS} days" +%y%m%d)
rclone lsf "$GDRIVE_REMOTE" --include "dump_*.sql" 2>/dev/null | while read -r FILE; do
  FILE_DATE="${FILE#dump_}"
  FILE_DATE="${FILE_DATE%.sql}"
  if [[ "$FILE_DATE" =~ ^[0-9]{6}$ && "$FILE_DATE" < "$CUTOFF" ]]; then
    rclone deletefile "${GDRIVE_REMOTE}/${FILE}" || echo "WARNING: failed to delete ${FILE} on Drive" >&2
  fi
done
echo "Drive cleanup done (kept ${GDRIVE_KEEP_DAYS} days)."
