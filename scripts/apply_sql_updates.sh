#!/usr/bin/env bash
set -euo pipefail

DB_CONTAINER="${1:-stg_db}"
DB_NAME="${2:-kamaho_shokusu}"
DB_USER="${3:-root}"
DB_PASS="${4:-}"
SQL_DIR="${5:-sql/updates}"

if [ -z "${DB_PASS}" ]; then
  echo "ERROR: DB password is required." >&2
  exit 1
fi

if [ ! -d "${SQL_DIR}" ]; then
  echo "No SQL update directory found: ${SQL_DIR}. Skipping."
  exit 0
fi

mysql_exec() {
  docker exec -i "${DB_CONTAINER}" mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" "$@"
}

mysql_exec -e "
CREATE TABLE IF NOT EXISTS schema_sql_history (
  file_name VARCHAR(255) NOT NULL PRIMARY KEY,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
"

set +f
sql_glob=("${SQL_DIR}"/*.sql)
set -f

if [ "${#sql_glob[@]}" -eq 0 ] || [ "${sql_glob[0]}" = "${SQL_DIR}/*.sql" ]; then
  echo "No SQL update files found under ${SQL_DIR}."
  exit 0
fi

IFS=$'\n' sorted_files=($(printf '%s\n' "${sql_glob[@]}" | sort))
unset IFS

for file in "${sorted_files[@]}"; do
  base_name="$(basename "${file}")"
  checksum="$(sha256sum "${file}" | awk '{print $1}')"
  escaped_name="${base_name//\'/\'\'}"

  existing_checksum="$(
    mysql_exec -Nse "SELECT checksum FROM schema_sql_history WHERE file_name='${escaped_name}' LIMIT 1"
  )"

  if [ -z "${existing_checksum}" ]; then
    echo "Applying SQL: ${base_name}"
    docker exec -i "${DB_CONTAINER}" mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${file}"
    mysql_exec -e "
      INSERT INTO schema_sql_history (file_name, checksum, applied_at)
      VALUES ('${escaped_name}', '${checksum}', NOW())
    "
    continue
  fi

  if [ "${existing_checksum}" != "${checksum}" ]; then
    echo "ERROR: ${base_name} was already applied, but file content changed." >&2
    echo "Please add a new SQL file instead of modifying an applied one." >&2
    exit 1
  fi

  echo "Skip (already applied): ${base_name}"
done
