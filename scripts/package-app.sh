#!/usr/bin/env bash
# Build a ready-to-upload zip of the Laravel app for a cPanel host (vendor included, no dev packages, no secrets).
# Usage: scripts/package-app.sh [output.zip]    Needs: composer, rsync, zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dudhwala-app.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

rsync -a \
  --exclude '.env' --exclude '.env.*' --exclude '!.env.production.example' \
  --exclude 'vendor' --exclude 'node_modules' --exclude '.git' --exclude 'tests' \
  --exclude '.phpunit.cache' --exclude '.phpunit.result.cache' --exclude 'database/*.sqlite' \
  --exclude 'bootstrap/cache/*.php' --exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' --exclude 'storage/app/backups/*' --exclude 'storage/app/public/*' \
  "$ROOT/app/" "$TMP/app/"
cp "$ROOT/app/.env.production.example" "$TMP/app/.env.production.example"

( cd "$TMP/app" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --quiet )
# source installs leave .git folders (hundreds of MB); tests and docs are not needed at runtime
find "$TMP/app/vendor" -name .git -prune -exec rm -rf {} + 2>/dev/null || true
find "$TMP/app/vendor" -type d \( -iname tests -o -iname test -o -iname docs \) -prune -exec rm -rf {} + 2>/dev/null || true
rm -f "$TMP/app/.phpunit.result.cache" "$TMP/app/phpunit.xml"
( cd "$TMP/app" && php artisan package:discover --quiet )   # fresh package manifest for the no-dev vendor
mkdir -p "$TMP/app/storage/framework/"{cache/data,sessions,views} "$TMP/app/storage/logs" "$TMP/app/storage/app/public" "$TMP/app/bootstrap/cache"

rm -f "$OUT"
( cd "$TMP" && zip -qr "$OUT" app )
echo "Wrote $OUT ($(du -h "$OUT" | cut -f1))"
