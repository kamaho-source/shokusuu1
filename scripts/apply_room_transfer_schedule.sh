#!/bin/bash
set -e

CONTAINER_NAME="${1:-kamakura-shokusu_web}"
APP_PATH="/var/www/html/kamaho-shokusu"

echo "Starting room transfer schedule apply: $(date) [container: ${CONTAINER_NAME}]"

docker exec "$CONTAINER_NAME" \
  bash -c "cd ${APP_PATH} && php bin/cake.php apply_room_transfer_schedule"

echo "Room transfer schedule apply completed: $(date)"
