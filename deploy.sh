#!/bin/sh
# Pull deploy, run every minute by cron on the server (docs/DEPLOY.md section 6b).
#
# GitHub Actions publishes a ready build to the `production` branch after a merge to master, and to `staging` for a
# pull request, only once the tests pass (public/build included, since the server has no Node). This script installs
# the newest commit of that branch when there is one and does nothing otherwise. .env, storage/ and vendor/ are not
# tracked, so the reset never touches them. A build that fails to install is rolled back and not retried.
#
# After it comes back up it opens the site's /up page and the home page; if either fails, the build is rolled back
# the same way. `sh deploy.sh rollback` (by hand) puts back the build that ran before the current one and keeps the
# current one from coming back. Only the code goes back: the database and uploads (storage/) are never touched, and
# a backup is taken before any migration runs.
#
# Usage: PHP=/opt/cpanel/ea-php84/root/usr/bin/php COMPOSER="$PHP /opt/cpanel/composer/bin/composer" sh deploy.sh production

set -eu

BRANCH="${1:-production}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
LOCK="$APP_DIR/storage/framework/deploy.lock.d"
FAILED="$APP_DIR/storage/framework/deploy.failed"
LOG="$APP_DIR/storage/logs/deploy.log"
HISTORY="$APP_DIR/storage/framework/deploy.history"
SITE="$(grep -E '^APP_URL=' "$APP_DIR/.env" | head -n 1 | cut -d= -f2- | tr -d '"' || true)"

cd "$APP_DIR"

# one run at a time; a lock left by a killed run expires after 30 minutes
if [ -d "$LOCK" ] && [ -n "$(find "$LOCK" -maxdepth 0 -mmin +30)" ]; then
    rmdir "$LOCK"
fi
mkdir "$LOCK" 2>/dev/null || exit 0
trap 'rmdir "$LOCK"' EXIT

OLD="$(git rev-parse HEAD 2>/dev/null || echo none)"
if [ "$BRANCH" = rollback ]; then
    NEW="$(grep -v "^$OLD\$" "$HISTORY" 2>/dev/null | tail -n 1 || true)"
    if [ -z "$NEW" ]; then
        echo "No earlier build in $HISTORY" >&2
        exit 1
    fi
    echo "$OLD" >"$FAILED"
    exec >>"$LOG" 2>&1
    echo "== $(date '+%Y-%m-%d %H:%M:%S') rollback by hand: $OLD -> $NEW ($OLD will not be retried)"
    "$PHP" artisan down --render="errors.503" --retry=60 || true
    git reset --quiet --hard "$NEW"
    $COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet || true
    "$PHP" artisan optimize:clear || true
    "$PHP" artisan optimize || true
    "$PHP" artisan up || true
    grep -v "^$OLD\$" "$HISTORY" >"$HISTORY.tmp" || true
    mv "$HISTORY.tmp" "$HISTORY"
    echo "== rolled back to $NEW"
    exit 0
fi

git fetch --quiet origin "$BRANCH"
NEW="$(git rev-parse "origin/$BRANCH")"
if [ "$NEW" = "$OLD" ] || [ "$NEW" = "$(cat "$FAILED" 2>/dev/null || true)" ]; then
    exit 0
fi

exec >>"$LOG" 2>&1
echo "== $(date '+%Y-%m-%d %H:%M:%S') $BRANCH: $OLD -> $NEW"

rollback() {
    echo "!! failed at: $1. Rolling back to $OLD; $NEW will not be retried."
    echo "$NEW" >"$FAILED"
    if [ "$OLD" != none ]; then
        git reset --quiet --hard "$OLD"
        $COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet || true
        "$PHP" artisan optimize || true
    fi
    "$PHP" artisan up || true
    exit 1
}

"$PHP" artisan down --render="errors.503" --retry=60 || true
git reset --quiet --hard "$NEW" || rollback "git reset"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet || rollback "composer install"
if ! "$PHP" artisan migrate:status --pending --no-ansi | grep -q "No pending migrations"; then
    "$PHP" artisan egmenu:backup || rollback "backup before migrations"
    "$PHP" artisan migrate --force || rollback "migrate"
fi
# built-in roles get new permissions; plans and add-ons are only created when missing (edited prices stay)
"$PHP" artisan db:seed --class=RoleSeeder --force || rollback "roles"
"$PHP" artisan db:seed --class=PlanSeeder --force || rollback "plans"
"$PHP" artisan optimize:clear || rollback "optimize:clear"
"$PHP" artisan optimize || rollback "optimize"
"$PHP" artisan up || rollback "up"
if [ -n "$SITE" ]; then
    sleep 3
    curl -fsS -o /dev/null --max-time 30 "$SITE/up" || rollback "health check $SITE/up"
    curl -fsS -o /dev/null --max-time 30 "$SITE/" || rollback "home page $SITE/"
fi
[ "$OLD" != none ] && ! grep -q "^$OLD\$" "$HISTORY" 2>/dev/null && echo "$OLD" >>"$HISTORY"
echo "$NEW" >>"$HISTORY"
rm -f "$FAILED"
"$PHP" artisan egmenu:doctor || true
echo "== done $NEW"
