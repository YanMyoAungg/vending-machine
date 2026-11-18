#!/usr/bin/env bash
# Add or replace the vending-machine vhost inside the LMS nginx config.
# Usage: apply-vhost.sh /tmp/vending-bootstrap.conf|/tmp/vending-final.conf
set -euo pipefail

conf=/var/www/lms-api/nginx/nginx.conf
src=$1
backup="${conf}.bak-$(date +%Y%m%d-%H%M%S)"

sudo cp "$conf" "$backup"

sudo python3 - "$conf" "$src" <<'PY'
import re
import sys

conf, src = sys.argv[1], sys.argv[2]
text = open(conf).read()
block = open(src).read()

text = re.sub(
    r"\n *# >>> vending-machine >>>.*?# <<< vending-machine <<<\n",
    "\n",
    text,
    flags=re.S,
)

idx = text.rstrip().rfind("}")
if idx == -1:
    raise SystemExit("no closing brace found")

text = text[:idx] + block + text[idx:]
open(conf, "w").write(text)
PY

if sudo docker exec lms-proxy nginx -t; then
    sudo docker exec lms-proxy nginx -s reload
    echo "reloaded; backup at ${backup}"
else
    echo "nginx -t failed, restoring ${backup}" >&2
    sudo cp "$backup" "$conf"
    exit 1
fi
