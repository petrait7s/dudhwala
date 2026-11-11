#!/usr/bin/env bash
# One command to run the admin panel + API on this computer and reach it from the internet (demo or test).
#   bash scripts/demo-admin.sh
# Starts MariaDB if needed, the PHP server and a Cloudflare quick tunnel, writes the tunnel address into app/.env (APP_URL),
# and prints the value to put in Vercel (PUBLIC_API_URL). Ctrl+C stops everything.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/app"
PORT="${PORT:-8000}"
[ -f "$APP/.env" ] || { echo "app/.env not found. Run the install first (php artisan dudhwala:install ...)."; exit 1; }
command -v php >/dev/null || { echo "php not found: sudo dnf install php-cli php-mysqlnd php-mbstring php-xml php-gd php-intl php-bcmath php-zip php-sodium"; exit 1; }
command -v cloudflared >/dev/null || { echo "cloudflared not found: sudo dnf install https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-x86_64.rpm"; exit 1; }

if command -v systemctl >/dev/null && ! systemctl is-active --quiet mariadb 2>/dev/null; then
  echo "Starting MariaDB (sudo password may be asked)..."
  sudo systemctl start mariadb || echo "Could not start MariaDB. Start it yourself, then run this again."
fi

LOG="$(mktemp)"
PIDS=()
cleanup() { echo; echo "Stopping..."; for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -f "$LOG"; exit 0; }
trap cleanup INT TERM

( cd "$APP/public" && exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 ) &
PIDS+=($!)
cloudflared tunnel --url "http://127.0.0.1:$PORT" >"$LOG" 2>&1 &
PIDS+=($!)

echo "Waiting for the tunnel address..."
URL=""
for _ in $(seq 1 60); do
  URL="$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$LOG" | head -1)"
  [ -n "$URL" ] && break
  sleep 1
done
[ -n "$URL" ] || { echo "No tunnel address after 60 s. Last lines:"; tail -5 "$LOG"; cleanup; }

sed -i "s|^APP_URL=.*|APP_URL=$URL|" "$APP/.env"
( cd "$APP" && php artisan config:clear >/dev/null 2>&1 )

echo
echo "================================================================"
echo " Admin panel : $URL/admin"
echo " API check   : $URL/api/catalogue"
echo
echo " Put this in Vercel (Settings > Environment Variables), then Redeploy:"
echo "   PUBLIC_API_URL=$URL/api"
echo "================================================================"
echo " Keep this window open. Press Ctrl+C to stop."
wait "${PIDS[1]}"
cleanup
