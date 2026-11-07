#!/usr/bin/env bash
# Build ready-to-upload zips of the Laravel app for a cPanel host (vendor included, no dev packages, no secrets).
#   dudhwala-app.zip          the app: unzip to /home/USER/dudhwala/   (so the app is /home/USER/dudhwala/app)
#   dudhwala-public_html.zip  only the web folder, for hosts where you cannot point the (sub)domain at app/public:
#                             unzip into the (sub)domain's document root. Its index.php finds the app via APP_REL.
# Usage: scripts/package-app.sh [output.zip]
#        APP_REL=/../dudhwala/app scripts/package-app.sh    (path from the document root to the app; default shown)
# Needs: composer, rsync, zip
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

# web-folder-only package: index.php patched to load the app from elsewhere and to serve uploads from its own folder
APP_REL="${APP_REL:-/../dudhwala/app}"
PUB_OUT="${OUT%.zip}-public_html.zip"
rm -rf "$TMP/pub" && mkdir -p "$TMP/pub" && rsync -a --exclude storage --exclude hot "$ROOT/app/public/" "$TMP/pub/"
python3 - "$TMP/pub/index.php" "$APP_REL" <<'PY'
import sys
p, rel = sys.argv[1], sys.argv[2]
s = open(p).read().replace("__DIR__.'/../", "__DIR__.'" + rel + "/")
s = s.replace("(require_once __DIR__.'" + rel + "/bootstrap/app.php')\n    ->handleRequest(Request::capture());",
              "$app = require_once __DIR__.'" + rel + "/bootstrap/app.php';\n$app->usePublicPath(__DIR__); // uploads and the storage link live in this folder\n$app->handleRequest(Request::capture());")
assert "usePublicPath" in s and rel in s
open(p, 'w').write(s)
PY
rm -f "$PUB_OUT"
( cd "$TMP/pub" && zip -qr "$PUB_OUT" . )
echo "Wrote $PUB_OUT ($(du -h "$PUB_OUT" | cut -f1)) with the app expected at <docroot>$APP_REL"
