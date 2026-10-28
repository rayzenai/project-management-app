#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy tasks.sdoc.gov.np: rayzenai/project-management-app@main on the SDOC
# VM's own Dokploy (project "tasks"). Default order: web first (its boot runs
# migrate --force), then the scheduler (same image, `schedule:work`). Dokploy
# builds GitHub's main, not this checkout: commit and push first.
#
# The panel listens only on the VM's loopback, so this opens its own SSH tunnel
# (SDOC VPN required) and closes it when done. No credentials live here: SSH
# comes from ../sdoc/ssh.txt and the Dokploy sign-in from
# ../sdoc/dokploy-access.txt, both gitignored in the sdoc checkout.
#
# A deploy counts as done only when the build finished, the Swarm rollout
# completed on the new version (a failed migration pauses it) and the public
# /up answers 200.
#
# Usage:
#   ./deploy.sh              # deploy web, then scheduler
#   ./deploy.sh web          # deploy only the named services (web, scheduler)
#
# Portable to macOS's stock bash 3.2 (no associative arrays).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
SDOC_DIR="${SDOC_DIR:-$SCRIPT_DIR/../sdoc}"
SSH_FILE="$SDOC_DIR/ssh.txt"
ACCESS_FILE="$SDOC_DIR/dokploy-access.txt"
SITE="https://tasks.sdoc.gov.np"
TIMEOUT=1800

# ssh.txt holds key=value lines: ip, usernmae (sic) or username, password.
ssh_field() {
  sed -n "s/^$1[[:space:]]*=//p" "$SSH_FILE" | head -1 | tr -d '\r' \
    | sed -E "s/^[[:space:]]+//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\1/; s/^'(.*)'\$/\1/"
}

# OpenSSH runs this script as its askpass helper, so the password never
# reaches argv or a terminal prompt.
if [ "${TASKS_DEPLOY_ASKPASS:-}" = 1 ]; then
  ssh_field password
  exit 0
fi

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m warn:\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# "<Dokploy applicationId> <Swarm service name>"
service_for() {
  case "$1" in
    web)       printf '%s' "esMcfCXzNzINE0Z8bATg1 tasks-web-cwgjvl" ;;
    scheduler) printf '%s' "gXpBWLno7vIpZgTwq4p9z tasks-scheduler-a7qjip" ;;
    *)         return 1 ;;
  esac
}

if [ "$#" -gt 0 ]; then
  services=("$@")
else
  services=(web scheduler)
fi
for svc in "${services[@]}"; do
  service_for "$svc" >/dev/null || die "unknown service '$svc' (expected: web, scheduler)"
done
[ -f "$SSH_FILE" ] && [ -f "$ACCESS_FILE" ] || die "need $SSH_FILE and $ACCESS_FILE (the sdoc checkout's credentials)."

if git -C "$SCRIPT_DIR" fetch -q origin main 2>/dev/null; then
  ahead="$(git -C "$SCRIPT_DIR" rev-list --count origin/main..HEAD)"
  [ "$ahead" = 0 ] || warn "HEAD has $ahead commit(s) not on origin/main. Dokploy builds origin/main; push first."
  log "Deploying origin/main: $(git -C "$SCRIPT_DIR" log -1 --format='%h %s' origin/main)"
fi

SSH_HOST="$(ssh_field ip)"
SSH_USER="$(ssh_field usernmae)"
[ -n "$SSH_USER" ] || SSH_USER="$(ssh_field username)"
SSH_PASSWORD="$(ssh_field password)"
SOCK="/tmp/tasks-deploy-$$.sock" # short: macOS caps ControlPath at 104 bytes
JAR="$(mktemp)"
PORT="$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"
BASE="http://127.0.0.1:$PORT"

cleanup() {
  curl -s -m 10 -o /dev/null -b "$JAR" -H "Content-Type: application/json" -H "Origin: $BASE" \
    -d '{}' "$BASE/api/auth/sign-out" || true
  if [ -S "$SOCK" ]; then
    ssh -S "$SOCK" -O exit "$SSH_USER@$SSH_HOST" 2>/dev/null || true
  fi
  rm -f "$JAR"
}
trap cleanup EXIT

log "Opening the Dokploy tunnel through $SSH_HOST (SDOC VPN required)..."
TASKS_DEPLOY_ASKPASS=1 SSH_ASKPASS="$SELF" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-tasks:0}" \
  ssh -f -N -M -S "$SOCK" -o ExitOnForwardFailure=yes -o StrictHostKeyChecking=accept-new \
    -o ConnectTimeout=15 -o ServerAliveInterval=15 -o NumberOfPasswordPrompts=1 \
    -L "127.0.0.1:$PORT:127.0.0.1:3000" "$SSH_USER@$SSH_HOST" </dev/null \
  || die "SSH to $SSH_HOST failed. Is the SDOC VPN connected?"

for _ in $(seq 1 50); do
  [ "$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$BASE/" || true)" = 200 ] && break
  sleep 0.5
done

python3 - "$ACCESS_FILE" <<'PY' | curl -sS -f -m 30 -o /dev/null -c "$JAR" -H "Content-Type: application/json" -H "Origin: $BASE" --data-binary @- "$BASE/api/auth/sign-in/email" || die "Dokploy sign-in failed; check $ACCESS_FILE."
import json, sys
creds = {}
for line in open(sys.argv[1]):
    key, sep, value = line.partition(":")
    if sep and key.strip() in ("Email", "Password"):
        creds[key.strip().lower()] = value.strip()
print(json.dumps(creds))
PY

api_get()  { curl -sS -f -m 30 -b "$JAR" -H "Origin: $BASE" "$BASE/api/$1"; }
api_post() { curl -sS -f -m 60 -b "$JAR" -H "Content-Type: application/json" -H "Origin: $BASE" -d "$2" "$BASE/api/$1"; }
# Runs a root command on the VM over the tunnel's connection; sudo reads the password from stdin.
remote()   { printf '%s\n' "$SSH_PASSWORD" | ssh -S "$SOCK" "$SSH_USER@$SSH_HOST" "sudo -S -p '' $1"; }

# "<deploymentId> <status> <logPath>" of the newest deployment, or "none".
newest_deployment() {
  api_get "deployment.all?applicationId=$1" | python3 -c '
import json, sys
d = sorted(json.load(sys.stdin), key=lambda x: x["createdAt"])
print(" ".join([d[-1]["deploymentId"], d[-1]["status"], d[-1]["logPath"]]) if d else "none")'
}

# "<version> <update state>" of a Swarm service.
service_state() {
  remote "docker service inspect $1 --format '{{.Version.Index}} {{if .UpdateStatus}}{{.UpdateStatus.State}}{{end}}'"
}

for svc in "${services[@]}"; do
  read -r app_id swarm <<< "$(service_for "$svc")"
  before_dep="$(newest_deployment "$app_id" | cut -d' ' -f1)"
  before_ver="$(service_state "$swarm" | cut -d' ' -f1)"
  [ -n "$before_ver" ] || die "could not read $swarm on the VM (SSH/sudo)."
  deadline=$((SECONDS + TIMEOUT))

  log "Deploying $svc..."
  api_post application.deploy "{\"applicationId\":\"$app_id\",\"title\":\"deploy.sh\"}" >/dev/null \
    || die "Dokploy refused the $svc deploy."

  while :; do
    read -r dep status logpath <<< "$(newest_deployment "$app_id" || true)"
    if [ "$dep" != "$before_dep" ]; then
      case "$status" in
        done)  break ;;
        error) die "$svc build failed. Log on the VM: sudo tail -100 '$logpath' (or Dokploy: tasks > $svc > Deployments)." ;;
      esac
    fi
    [ "$SECONDS" -lt "$deadline" ] || die "$svc: timed out waiting for the build. It keeps running in Dokploy."
    sleep 5
  done

  log "$svc built; waiting for the rollout..."
  while :; do
    read -r ver state <<< "$(service_state "$swarm" || true)"
    if [ -n "$ver" ] && [ "$ver" -gt "$before_ver" ]; then
      case "$state" in
        completed|"")          break ;;
        paused|rollback_*)     die "$svc rollout $state: the new container did not start. Check its logs in Dokploy." ;;
      esac
    fi
    [ "$SECONDS" -lt "$deadline" ] || die "$svc: timed out waiting for the rollout (state: ${state:-unknown})."
    sleep 5
  done
  log "$svc is live."
done

code="$(curl -s -m 20 -o /dev/null -w '%{http_code}' "$SITE/up" || true)"
[ "$code" = 200 ] || die "$SITE/up returned HTTP ${code:-no response}."
log "Healthy: $SITE"
