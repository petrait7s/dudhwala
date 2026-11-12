#!/usr/bin/env bash
# Turn a milk-wave GIF (transparent background) into a small animated WebP for the hero.
#   sudo dnf install libwebp-tools          (once, Fedora)
#   bash scripts/milk-convert.sh ~/Downloads/milk-wave.gif
# Writes web/public/milk/wave.webp. Commit and push it; the next deploy shows it instead of the drawn splash.
set -eu
IN="${1:?usage: milk-convert.sh path/to/wave.gif}"
OUT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/web/public/milk"
mkdir -p "$OUT"
command -v gif2webp >/dev/null || { echo "gif2webp missing: sudo dnf install libwebp-tools"; exit 1; }
gif2webp -q 78 -m 6 -mixed "$IN" -o "$OUT/wave.webp"
cp "$IN" "$OUT/wave.gif"   # fallback for old browsers; the webp is used first
ls -lh "$OUT"
