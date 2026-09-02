#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy the pmopm stack on Dokploy via the authenticated API deploy endpoint.
# Default order: api first (the app), then queue and scheduler (workers off the
# same image). The API key lives in .dokploy.env (gitignored); the application
# IDs below are not secret (they identify the Dokploy services, nothing more).
#
# NB: Dokploy's per-app "deploy webhook" URLs are GitHub-push webhooks — a bare
# curl to one returns {"message":"Branch Not Match"} and does NOT deploy. The
# API endpoint below is the reliable manual trigger.
#
# Usage:
#   ./deploy.sh              # deploy api, queue, scheduler
#   ./deploy.sh api queue    # deploy only the named services
#
# Portable to macOS's stock bash 3.2 (no associative arrays).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.dokploy.env"

[ -f "$ENV_FILE" ] || { echo "ERROR: $ENV_FILE not found (holds DOKPLOY_URL + DOKPLOY_KEY)." >&2; exit 1; }
# shellcheck source=/dev/null
set -a; . "$ENV_FILE"; set +a
: "${DOKPLOY_URL:?set DOKPLOY_URL in .dokploy.env}"
: "${DOKPLOY_KEY:?set DOKPLOY_KEY in .dokploy.env}"

app_id_for() {
  case "$1" in
    api)       printf '%s' "eZAUi1vh6VKNqRkkdJvVI" ;;
    queue)     printf '%s' "ZCtyyRBskeIwr4rbEGXg2" ;;
    scheduler) printf '%s' "WYGvc_Obsm8m9gOM1di8f" ;;
    *)         return 1 ;;
  esac
}

if [ "$#" -gt 0 ]; then
  services=("$@")
else
  services=(api queue scheduler)
fi

fails=0
for svc in "${services[@]}"; do
  id="$(app_id_for "$svc")" || { echo "ERROR: unknown service '$svc' (expected: api, queue, scheduler)" >&2; fails=$((fails+1)); continue; }
  printf '\xe2\x86\x92 deploying %-9s ... ' "$svc"
  code="$(curl -sS -m 60 -X POST \
    -H "x-api-key: $DOKPLOY_KEY" -H "Content-Type: application/json" \
    -d "{\"applicationId\":\"$id\"}" \
    -o /dev/null -w '%{http_code}' "$DOKPLOY_URL/api/application.deploy" || true)"
  if [ "$code" = "200" ]; then
    echo "queued (HTTP 200)"
  else
    echo "FAILED (HTTP ${code:-?})"
    fails=$((fails+1))
  fi
done

echo
if [ "$fails" -eq 0 ]; then
  echo "All deploys queued. Watch progress at $DOKPLOY_URL"
else
  echo "$fails deploy(s) failed — check the Dokploy panel." >&2
  exit 1
fi
