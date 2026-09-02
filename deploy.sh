#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy the pmopm stack on Dokploy by triggering each service's deploy webhook.
# Order: api first (the app), then queue and scheduler (workers off the same image).
# Webhook URLs are secrets (anyone with one can trigger a deploy) — they live in
# .dokploy.env (gitignored), never in this committed script.
#
# Usage:
#   ./deploy.sh              # deploy api, queue, scheduler
#   ./deploy.sh api queue    # deploy only the named services

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.dokploy.env"

[ -f "$ENV_FILE" ] || { echo "ERROR: $ENV_FILE not found (holds the DEPLOY_HOOK_* URLs)." >&2; exit 1; }
# shellcheck source=/dev/null
set -a; . "$ENV_FILE"; set +a

# service -> env var holding its webhook URL
declare -A HOOK=(
  [api]="${DEPLOY_HOOK_API:-}"
  [queue]="${DEPLOY_HOOK_QUEUE:-}"
  [scheduler]="${DEPLOY_HOOK_SCHEDULER:-}"
)

# default order if no args given
services=("${@:-api queue scheduler}")
# split the default single-string arg into words
services=(${services[@]})

trigger() {
  local svc="$1" url="${HOOK[$1]:-}"
  if [ -z "$url" ]; then
    echo "ERROR: unknown service '$svc' (expected: api, queue, scheduler)" >&2
    return 2
  fi
  printf '→ deploying %-9s ... ' "$svc"
  local code
  code=$(curl -fsS -m 30 -o /dev/null -w '%{http_code}' "$url") \
    && { echo "OK (HTTP $code)"; return 0; } \
    || { echo "FAILED (HTTP ${code:-?})"; return 1; }
}

fails=0
for svc in "${services[@]}"; do
  trigger "$svc" || fails=$((fails+1))
done

echo
if [ "$fails" -eq 0 ]; then
  echo "All deploy webhooks fired. Watch progress at ${DOKPLOY_URL:-the Dokploy panel}."
else
  echo "$fails deploy webhook(s) failed — check the Dokploy panel." >&2
  exit 1
fi
