#!/usr/bin/env bash
set -euo pipefail

# Manual deploy from your own machine (uses your "hetzner" SSH alias)
REMOTE_HOST="hetzner"
REMOTE_PATH="/home/elgersmav/html/hps-calendar/"

echo "==> Checking event files..."
bad=0
for f in events/*.json; do
    if ! python3 -m json.tool "$f" >/dev/null 2>/tmp/hps-json-err; then
        echo "  ✗ $f: $(cat /tmp/hps-json-err)"
        bad=1
    fi
done
if [ "$bad" -ne 0 ]; then
    echo "Fix the file(s) above first. Nothing was uploaded."
    exit 1
fi
echo "  ✓ $(ls events/*.json | wc -l | tr -d ' ') event files OK"

echo "==> Uploading to $REMOTE_HOST..."
# For the "Compiled on … from … e-mails … and these sites" line in the page header:
# the deploy time, how many mails have been read (inbox/ itself stays local) and the readme's sources
python3 - <<'PY' > site-info.json
import json, re, subprocess
updated = subprocess.run(["date", "+%Y-%m-%d %H:%M"], env={"TZ": "Europe/Amsterdam"}, capture_output=True, text=True).stdout.strip()
try:
    emails = len({l.strip() for l in open("inbox/.processed") if l.strip()})
except FileNotFoundError:
    emails = 0
readme = open("readme.md", encoding="utf-8").read()
section = readme.split("## Sources to check", 1)[1].split("\n## ", 1)[0]
# "- UPAC colloquia (Utrecht Philosophy …): https://…" -> name without the parenthetical, url
sources = [{"name": re.sub(r"\s*\(.*?\)", "", m[1]).strip(), "url": m[2]}
           for m in re.finditer(r"^- (.+?): (https?://\S+)", section, re.M)]
print(json.dumps({"updated": updated, "emails": emails, "sources": sources}, ensure_ascii=False, indent=1))
PY
# --delete only applies inside events/: removing an event file locally removes that event online
rsync -avz --delete index.php event.php calendar.php subscribe.php lib.php correction.php contributing.php about.php style.css site-info.json fonts events "$REMOTE_HOST:$REMOTE_PATH"

echo "==> Done: https://hps-calendar.vjbe.net"
