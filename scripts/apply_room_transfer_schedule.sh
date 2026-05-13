#!/bin/bash
set -e

# PHP コンテナ内で CakePHP コマンドを実行する
CONTAINER_NAME="kamakura-shokusu_web"
APP_PATH="/var/www/html/kamaho-shokusu"

echo "Starting room transfer schedule apply: $(date)"

docker exec "$CONTAINER_NAME" \
  bash -c "cd ${APP_PATH} && php bin/cake.php apply_room_transfer_schedule"

echo "Room transfer schedule apply completed: $(date)"
