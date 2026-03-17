#!/bin/bash
set -e

CONTAINER_NAME="kamakura-shokusu_web_db"
DB_NAME="${DB_NAME:-shokusu}"
DB_USER="${DB_USER}"
DB_PASS="${DB_PASS}"

echo "Starting age update: $(date)"

docker exec "$CONTAINER_NAME" \
  mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  -e "UPDATE m_user_info SET i_user_age = i_user_age + 1, dt_update = NOW() WHERE i_del_flag = 0 AND i_enable = 1;"

echo "Age update completed: $(date)"
