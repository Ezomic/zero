#!/usr/bin/env bash
# =============================================================================
# deploy.sh — deploy latest code to the production server
#
# Run ON the server (as the deploy user):
#   cd /home/deploy/mail && bash scripts/deploy.sh
#
# Run FROM your local machine:
#   bash scripts/deploy.sh --remote deploy@your-server
#   (Requires SSH access and the server already provisioned.)
#
# What it does:
#   1. Puts the site into maintenance mode
#   2. Pulls latest code from main
#   3. Installs/updates Composer dependencies (no-dev)
#   4. Installs npm dependencies and rebuilds frontend assets
#   5. Runs migrations
#   6. Clears and rebuilds caches
#   7. Creates storage symlink if missing
#   8. Sets correct permissions
#   9. Restarts PHP-FPM and all mail-app supervisor programs
#  10. Takes the site out of maintenance mode
#  11. Runs a smoke test (HTTP 200 on /)
# =============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/home/deploy/mail}"
PHP="${PHP:-php}"

# ── Remote mode ───────────────────────────────────────────────────────────────
# If --remote <user@host> is passed, re-execute this script over SSH.
if [[ "${1:-}" == "--remote" ]]; then
  if [[ -z "${2:-}" ]]; then
    echo "Usage: bash scripts/deploy.sh --remote deploy@your-server" >&2
    exit 1
  fi
  HOST="$2"
  echo "▶ Deploying to $HOST"
  ssh -T "$HOST" "cd $APP_DIR && bash scripts/deploy.sh"
  exit $?
fi

# ── Guards ────────────────────────────────────────────────────────────────────
if [[ ! -f "$APP_DIR/artisan" ]]; then
  echo "ERROR: $APP_DIR/artisan not found. Run from the repo root or set APP_DIR." >&2
  exit 1
fi

cd "$APP_DIR"

if [[ ! -f ".env" ]]; then
  echo "ERROR: .env not found in $APP_DIR. Copy .env.example and fill it in." >&2
  exit 1
fi

# ── Helpers ───────────────────────────────────────────────────────────────────
step() { echo; echo "▶ $*"; }
ok()   { echo "  ✓ $*"; }

# A failure anywhere after maintenance mode is enabled must not strand the
# site down — always try to bring it back up on exit, whatever the cause.
MAINTENANCE_ACTIVE=false
restore_on_failure() {
  local exit_code=$?
  if [[ "$exit_code" -ne 0 && "$MAINTENANCE_ACTIVE" == true ]]; then
    echo "▶ Deploy failed — restoring service before exiting" >&2
    $PHP artisan up || true
  fi
}
trap restore_on_failure EXIT

START=$(date +%s)
echo "════════════════════════════════════════════"
echo "  Deploying mail-app  —  $(date '+%Y-%m-%d %H:%M:%S')"
echo "════════════════════════════════════════════"

# ── 1. Maintenance mode ───────────────────────────────────────────────────────
step "Enabling maintenance mode"
$PHP artisan down --retry=10
MAINTENANCE_ACTIVE=true
ok "Site is down"

# ── 2. Pull latest code ───────────────────────────────────────────────────────
step "Pulling from origin/main"
git fetch origin
git reset --hard origin/main
ok "$(git log -1 --format='%h %s')"

# ── 3. Composer ───────────────────────────────────────────────────────────────
step "Installing Composer dependencies"
composer install \
  --no-dev \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader \
  --quiet
ok "Composer up to date"

# ── 4. Frontend assets ────────────────────────────────────────────────────────
step "Building frontend assets"
npm ci --no-audit --no-fund --silent
npm run build --silent
ok "Assets built"

# ── 5. Storage symlink ────────────────────────────────────────────────────────
if [[ ! -L "public/storage" ]]; then
  step "Creating storage symlink"
  $PHP artisan storage:link
  ok "Symlink created"
fi

# ── 6. Database migrations ────────────────────────────────────────────────────
step "Running migrations"
$PHP artisan migrate --force
ok "Migrations complete"

# ── 7. Caches ─────────────────────────────────────────────────────────────────
step "Clearing and rebuilding caches"
$PHP artisan cache:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
ok "Caches rebuilt"

# ── 8. Permissions ────────────────────────────────────────────────────────────
# Only chmod files this user actually owns — storage/ accumulates files
# written at runtime by PHP-FPM (www-data) and by supervisor as root, which
# `deploy` can't chmod even though it can read/write them via group
# membership. Scoping by owner fixes newly pulled files without erroring
# on those.
step "Fixing permissions"
find storage bootstrap/cache -user "$(id -un)" -exec chmod 755 {} + 2>/dev/null || true
chmod 664 database/database.sqlite 2>/dev/null || true
ok "Permissions set"

# ── 9. Restart services ───────────────────────────────────────────────────────
step "Restarting PHP-FPM"
sudo systemctl reload php8.4-fpm
ok "PHP-FPM reloaded"

step "Restarting mail-app supervisor programs"
sudo supervisorctl restart mail-queue:* mail-scheduler:* mail-reverb:* mail-idle-10:* > /dev/null
ok "Queue, scheduler, Reverb, and IMAP IDLE restarted"

# ── 10. Back online ───────────────────────────────────────────────────────────
step "Disabling maintenance mode"
$PHP artisan up
MAINTENANCE_ACTIVE=false
ok "Site is live"

# ── 11. Smoke test ────────────────────────────────────────────────────────────
step "Smoke test"
APP_URL=$($PHP artisan tinker --execute="echo config('app.url');" 2>/dev/null | tail -1)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$APP_URL/" || echo "000")

if [[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "302" ]]; then
  ok "GET $APP_URL/ → $HTTP_CODE"
else
  echo "  ✗ GET $APP_URL/ → $HTTP_CODE" >&2
  echo "  Check /var/log/nginx/mail-error.log and storage/logs/laravel.log" >&2
  exit 1
fi

END=$(date +%s)
echo
echo "════════════════════════════════════════════"
echo "  Deploy complete in $((END - START))s"
echo "════════════════════════════════════════════"
