#!/bin/bash
# 本番DBバックアップをGoogle Driveへ同期する。
# rclone / Drive側の失敗はここで握りつぶし、常に exit 0 で終わる。
# mysqldump（scripts/db_backup.sh）の成否には影響させない設計。
set +e

BACKUP_DIR="${BACKUP_DIR:-/home/ubuntu/backups/shokusu}"
RCLONE_REMOTE="${RCLONE_REMOTE:-gdrive:shokusu-backups}"
RETENTION_DAYS=14
DATE=$(date +%y%m%d)
TARGET_FILE="dump_${DATE}.sql"

if ! command -v rclone >/dev/null 2>&1; then
  echo "::warning::rclone が見つかりません。Drive同期をスキップします。"
  exit 0
fi

# 当日分をアップロード
if [ -f "${BACKUP_DIR}/${TARGET_FILE}" ]; then
  if rclone copy "${BACKUP_DIR}/${TARGET_FILE}" "${RCLONE_REMOTE}/" --checksum; then
    echo "Uploaded: ${TARGET_FILE} -> ${RCLONE_REMOTE}/"
  else
    echo "::warning::Google Driveへのアップロードに失敗しました: ${TARGET_FILE}"
  fi
else
  echo "::warning::アップロード対象が見つかりません: ${BACKUP_DIR}/${TARGET_FILE}"
fi

# Drive上でファイル名日付が14日超のdump_*.sqlを削除（mtimeは使わない）
CUTOFF=$(date -d "-${RETENTION_DAYS} days" +%y%m%d)
rclone lsf "${RCLONE_REMOTE}/" --include "dump_*.sql" 2>/dev/null | while IFS= read -r f; do
  FILE_DATE=$(echo "$f" | sed -nE 's/^dump_([0-9]{6})\.sql$/\1/p')
  if [ -n "$FILE_DATE" ] && [ "$FILE_DATE" -lt "$CUTOFF" ]; then
    echo "Deleting old backup on Drive: $f (date=$FILE_DATE < cutoff=$CUTOFF)"
    rclone delete "${RCLONE_REMOTE}/${f}"
  fi
done

exit 0
