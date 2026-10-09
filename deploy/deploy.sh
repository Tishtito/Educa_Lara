#!/usr/bin/env bash
#
# Educa API deploy. Runs as tito on the Codepasstech VPS and needs NO sudo:
#   * opcache.validate_timestamps is On (revalidate_freq=2), so new code is
#     live within seconds without an FPM reload.
#   * `queue:restart` is a cache flag the workers poll; Supervisor's
#     autorestart=true brings them back. supervisorctl is not reachable as
#     tito, and does not need to be.
#
# Installed as /home/tito/bin/educa-deploy and pinned as the forced command on
# the CI SSH key (see docs/deployment-vps.md). Deliberately a COPY of this
# file, not a symlink into the repo: the forced command must not be rewritable
# by anyone who can push to dev, or the restriction is worth nothing. Re-run
# the install step by hand when this script changes.
#
#   install -m 0755 ~/educa/deploy/deploy.sh /home/tito/bin/educa-deploy
#
set -euo pipefail

APP=/home/tito/educa
BRANCH=dev
BACKUPS=/home/tito/educa-backups
KEEP=7
HEALTH_URL=https://educa-api.codepasstech.top/up

cd "$APP"

echo "==> Backing up the database"
mkdir -p "$BACKUPS"
DB_USER=$(grep -m1 '^DB_USERNAME=' .env | cut -d= -f2-)
DB_PASS=$(grep -m1 '^DB_PASSWORD=' .env | cut -d= -f2-)
DB_NAME=$(grep -m1 '^DB_DATABASE=' .env | cut -d= -f2-)
STAMP=$(date +%Y%m%d%H%M%S)
# Taken BEFORE the migration: it is the only way back from a bad schema change.
mysqldump --single-transaction --quick \
    -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    | gzip > "$BACKUPS/$DB_NAME-$STAMP.sql.gz"
echo "    $BACKUPS/$DB_NAME-$STAMP.sql.gz ($(du -h "$BACKUPS/$DB_NAME-$STAMP.sql.gz" | cut -f1))"
ls -1t "$BACKUPS"/*.sql.gz | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "==> Fetching origin/$BRANCH"
git fetch --prune origin "$BRANCH"
# reset, not pull: a deploy target matches the remote exactly and never merges.
# Untracked files (.env, .env.bak.*) are left alone.
git reset --hard "origin/$BRANCH"

echo "==> Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Workers match this release"
# Before the migration, so a refusal leaves the database as it was. The cached
# config is the previous release's; clear it so the check reads this one's.
# SKIP_WORKER_CHECK=1 for a first install, where no worker has run yet.
php artisan config:clear
if [ "${SKIP_WORKER_CHECK:-0}" != "1" ]; then
    php artisan ops:check-workers
fi

echo "==> Migrations"
php artisan migrate --force

echo "==> Permissions registry"
# A new route guarded by can:<slug> 403s for everyone until this runs.
php artisan permissions:sync

echo "==> Caches"
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "==> Workers"
# Not optional. A running worker keeps the code and .env it booted with, so
# without this every queued job runs the PREVIOUS deploy indefinitely.
php artisan queue:restart

echo "==> Health"
sleep 3
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL")
if [ "$code" != "200" ]; then
    echo "    FAILED: $HEALTH_URL returned $code"
    exit 1
fi
echo "    $HEALTH_URL -> 200"
echo "==> Deployed $(git rev-parse --short HEAD) ($(git log -1 --pretty=%s))"
