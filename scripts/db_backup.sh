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
