#!/usr/bin/env bash
# Run as ubuntu on the staging host, with the staged nginx config as argument.
set -euo pipefail
test "$(hostname)" = staging-vnic
candidate=$(realpath "${1:?Pass the staging nginx configuration}")
test -s /usr/share/GeoIP/dbip-country-lite.mmdb
grep -q 'server_name stg.kamaho-shokusu.jp;' "$candidate"
cd /home/ubuntu/shokusuu1/docker
backup=$(sudo mktemp -d /var/backups/shokusuu-geoip-XXXXXXXX)
sudo cp -a /etc/nginx/sites-available/staging "$backup/staging.conf"
sudo cp -a docker-compose.staging.yml "$backup/docker-compose.staging.yml"
printf 'Rollback files: %s\n' "$backup"

recreate_web() {
    docker compose -f docker-compose.staging.yml --env-file .env.staging \
        up -d --no-deps --no-build --pull never web
}
rollback() {
    trap - ERR
    sudo cp -a "$backup/staging.conf" /etc/nginx/sites-available/staging
    sudo cp -a "$backup/docker-compose.staging.yml" docker-compose.staging.yml
    recreate_web
    sudo nginx -t && sudo systemctl reload nginx
    echo 'Rolled back staging changes after failure' >&2
}
trap rollback ERR

# Avoid accidentally changing the application image during an infrastructure test.
test "$(docker inspect stg_web --format '{{.Image}}')" = \
     "$(docker image inspect ghcr.io/kamaho-source/shokusuu1/web:staging --format '{{.Id}}')"
sudo install -m 644 "$candidate" /etc/nginx/sites-available/staging
sudo nginx -t
python3 - <<'PY'
from pathlib import Path
p = Path('docker-compose.staging.yml')
s = p.read_text()
assert s.count('"8091:80"') == 1 or s.count('"127.0.0.1:8091:80"') == 1
p.write_text(s.replace('"8091:80"', '"127.0.0.1:8091:80"'))
PY
docker compose -f docker-compose.staging.yml --env-file .env.staging config --quiet
recreate_web
ready=0
for attempt in $(seq 1 20); do
    status=$(curl -s --max-time 3 -o /dev/null -w '%{http_code}' \
        -H 'Host: stg.kamaho-shokusu.jp' \
        http://127.0.0.1:8091/kamaho-shokusu/MUserInfo/login || true)
    if [ "$status" = 200 ]; then ready=1; break; fi
    sleep 1
done
test "$ready" = 1
sudo systemctl reload nginx

# Reload is asynchronous: an immediate request can still hit an old worker.
ready=0
for attempt in $(seq 1 20); do
    body=$(curl -fsS --max-time 3 --resolve stg.kamaho-shokusu.jp:443:127.0.0.1 \
        https://stg.kamaho-shokusu.jp/healthz 2>/dev/null || true)
    if [ "$body" = ok ]; then ready=1; break; fi
    sleep 1
done
test "$ready" = 1
docker ps --format '{{.Names}} {{.Ports}}'
sudo ss -ltnp | grep ':8091'
echo 'Staging GeoIP restriction deployed'
