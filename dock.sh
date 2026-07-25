#!/usr/bin/env bash
#
# dock.sh — run PMOPM in Docker, exactly as Dokploy ships it.
#
# Builds ./Dockerfile (the real deployment image: nginx + php-fpm 8.5 on 8080,
# Vite assets baked in, AUTORUN doing migrate/storage:link/config cache on boot)
# and runs it against your LOCAL Postgres, so you test the production image with
# real data.
#
# Usage:
#   ./dock.sh                 # build if needed, run, wait for health, show URLs
#   ./dock.sh build           # force a rebuild, then run
#   ./dock.sh build --no-cache
#   ./dock.sh restart         # recreate the container from the current image
#   ./dock.sh down            # stop + remove the container
#   ./dock.sh logs            # follow container logs (nginx/fpm + Laravel)
#   ./dock.sh shell           # bash inside the running container
#   ./dock.sh artisan <cmd>   # e.g. ./dock.sh artisan migrate:status
#   ./dock.sh status          # container + health summary
#
# Flags (on the default/build/restart commands):
#   --port N        host port to publish on (default 8101, or $PORT)
#   --no-migrate    boot without running `migrate --force` against your dev DB
#
# NOTE: the image bakes the source in at build time. Code changes need
# `./dock.sh build` to show up — this is the deployment artifact, not a dev
# server. For hot-reload dev use `composer dev` instead.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="$ROOT_DIR"
IMAGE="pmopm:local"
CONTAINER="pmopm-web"
DOCKER_ENV="$API_DIR/.env.docker"
SOURCE_ENV="$API_DIR/.env"
PORT="${PORT:-8101}"
RUN_MIGRATIONS=true

# --- Parse command + flags -------------------------------------------------
CMD="up"
BUILD_ARGS=()
ARTISAN_ARGS=()
case "${1:-}" in
    build|rebuild)  CMD="build"; shift ;;
    up|start)       CMD="up"; shift ;;
    down|stop|rm)   CMD="down"; shift ;;
    restart)        CMD="restart"; shift ;;
    logs|log)       CMD="logs"; shift ;;
    shell|sh|bash)  CMD="shell"; shift ;;
    artisan)        CMD="artisan"; shift; ARTISAN_ARGS=("$@"); set -- ;;
    status|ps)      CMD="status"; shift ;;
    -h|--help)      sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
esac

while [[ $# -gt 0 ]]; do
    case "$1" in
        --port)       PORT="$2"; shift 2 ;;
        --port=*)     PORT="${1#*=}"; shift ;;
        --no-migrate) RUN_MIGRATIONS=false; shift ;;
        --no-cache)   BUILD_ARGS+=(--no-cache); shift ;;
        *)            echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

# -it only when we actually have a terminal, so `./dock.sh artisan ...` still
# works from scripts, CI, and non-interactive shells.
docker_exec() {
    if [[ -t 0 ]]; then
        docker exec -it "$@"
    else
        docker exec "$@"
    fi
}

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m warn:\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# --- Make sure the Docker daemon is actually up ----------------------------
ensure_docker() {
    docker info >/dev/null 2>&1 && return 0
    log "Docker daemon not running — starting Docker Desktop..."
    open -a Docker 2>/dev/null || die "could not launch Docker Desktop; start it manually"
    for _ in $(seq 1 90); do
        docker info >/dev/null 2>&1 && { log "Docker ready."; return 0; }
        sleep 1
    done
    die "Docker did not become ready in 90s"
}

# --- Build a container-safe .env from ./.env -------------------------------
# Rewrites host-loopback addresses to host.docker.internal (Docker Desktop
# proxies that back to the host's 127.0.0.1, so your local Postgres is reachable
# without opening it to the network), strips quotes that docker --env-file would
# otherwise keep literally, and expands ${VAR} references Laravel would.
generate_env() {
    [[ -f "$SOURCE_ENV" ]] || die "no $SOURCE_ENV — copy .env.example and set it up first"
    APP_URL_OVERRIDE="http://localhost:$PORT" python3 - "$SOURCE_ENV" "$DOCKER_ENV" <<'PY'
import os, re, sys

src, dst = sys.argv[1], sys.argv[2]
# Anything the container would resolve to itself must point back at the host.
HOST_ALIASES = {"127.0.0.1", "localhost", "::1", "0.0.0.0"}
values, order = {}, []

for line in open(src, encoding="utf-8"):
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, _, val = line.partition("=")
    key = key.replace("export ", "").strip()
    val = val.strip()
    if len(val) >= 2 and val[0] == val[-1] and val[0] in "\"'":
        val = val[1:-1]
    val = re.sub(r"\$\{(\w+)\}", lambda m: values.get(m.group(1), ""), val)
    if key.endswith("_HOST") and val in HOST_ALIASES:
        val = "host.docker.internal"
    if key not in values:
        order.append(key)
    values[key] = val

values["APP_URL"] = os.environ["APP_URL_OVERRIDE"]
# Send Laravel's log stream to stdout so `./dock.sh logs` shows it.
values["LOG_CHANNEL"] = "stderr"
for k in ("APP_URL", "LOG_CHANNEL"):
    if k not in order:
        order.append(k)

with open(dst, "w", encoding="utf-8") as f:
    f.write("# Generated by dock.sh — do not edit; edit ./.env instead.\n")
    for key in order:
        f.write(f"{key}={values[key]}\n")
PY
    chmod 600 "$DOCKER_ENV"
}

image_exists()     { docker image inspect "$IMAGE" >/dev/null 2>&1; }
container_exists() { docker container inspect "$CONTAINER" >/dev/null 2>&1; }
container_running(){ [[ "$(docker container inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" == "true" ]]; }

build_image() {
    log "Building $IMAGE from ./Dockerfile (first build pulls php/node/composer images — give it a few minutes)"
    docker build "${BUILD_ARGS[@]+"${BUILD_ARGS[@]}"}" -t "$IMAGE" -f "$API_DIR/Dockerfile" "$API_DIR"
}

check_db() {
    # Read the DB target out of the generated env so we probe what the app will use.
    local host port db
    host="$(grep -E '^DB_HOST=' "$DOCKER_ENV" | cut -d= -f2-)"
    port="$(grep -E '^DB_PORT=' "$DOCKER_ENV" | cut -d= -f2-)"
    db="$(grep -E '^DB_DATABASE=' "$DOCKER_ENV" | cut -d= -f2-)"
    log "Checking the container can reach Postgres ($db at $host:${port:-5432})..."
    if docker run --rm --add-host=host.docker.internal:host-gateway alpine \
        sh -c "nc -z -w 3 $host ${port:-5432}" >/dev/null 2>&1; then
        return 0
    fi
    warn "the container could NOT reach $host:${port:-5432}."
    warn "Is Postgres running on the host?  brew services list | grep postgres"
    warn "Continuing anyway — the app will report the connection error itself."
}

remove_container() {
    if container_exists; then
        log "Removing existing container $CONTAINER"
        docker rm -f "$CONTAINER" >/dev/null
    fi
}

start_container() {
    remove_container
    log "Starting $CONTAINER on http://localhost:$PORT"
    docker run -d \
        --name "$CONTAINER" \
        -p "$PORT:8080" \
        --env-file "$DOCKER_ENV" \
        --add-host=host.docker.internal:host-gateway \
        -e AUTORUN_ENABLED=true \
        -e "AUTORUN_LARAVEL_MIGRATION=$RUN_MIGRATIONS" \
        "$IMAGE" >/dev/null

    log "Waiting for the app to answer /up ..."
    for i in $(seq 1 60); do
        if ! container_running; then
            warn "container exited during boot — last 40 log lines:"
            docker logs --tail 40 "$CONTAINER" || true
            die "container failed to stay up"
        fi
        if curl -fsS "http://localhost:$PORT/up" >/dev/null 2>&1; then
            printf '\n'
            log "App is up."
            echo "  App       : http://localhost:$PORT"
            echo "  Workspace : http://localhost:$PORT/workspace"
            echo "  API v1    : http://localhost:$PORT/api/v1"
            echo ""
            echo "  Logs    : ./dock.sh logs      Shell: ./dock.sh shell"
            echo "  Artisan : ./dock.sh artisan migrate:status"
            echo "  Stop    : ./dock.sh down"
            [[ "$RUN_MIGRATIONS" == "true" ]] && echo "" && echo "  (migrations ran against your local '$(grep -E '^DB_DATABASE=' "$DOCKER_ENV" | cut -d= -f2-)' DB — pass --no-migrate to skip)"
            return 0
        fi
        printf '.'
        sleep 2
    done
    printf '\n'
    warn "no healthy response after 120s — last 40 log lines:"
    docker logs --tail 40 "$CONTAINER" || true
    die "app did not become healthy"
}

# --- Dispatch --------------------------------------------------------------
case "$CMD" in
    down)
        ensure_docker
        remove_container
        log "Stopped."
        ;;
    logs)
        ensure_docker
        container_exists || die "no container named $CONTAINER — run ./dock.sh first"
        docker logs -f --tail 100 "$CONTAINER"
        ;;
    shell)
        ensure_docker
        container_running || die "$CONTAINER is not running — run ./dock.sh first"
        docker_exec "$CONTAINER" bash
        ;;
    artisan)
        ensure_docker
        container_running || die "$CONTAINER is not running — run ./dock.sh first"
        [[ ${#ARTISAN_ARGS[@]} -gt 0 ]] || die "usage: ./dock.sh artisan <command>"
        docker_exec "$CONTAINER" php artisan "${ARTISAN_ARGS[@]}"
        ;;
    status)
        ensure_docker
        docker ps -a --filter "name=^/${CONTAINER}$" \
            --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}\t{{.Image}}'
        if container_running; then
            code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/up" || echo 000)"
            echo ""
            echo "GET /up on port $PORT -> HTTP $code"
        fi
        ;;
    build)
        ensure_docker
        generate_env
        build_image
        check_db
        start_container
        ;;
    restart)
        ensure_docker
        generate_env
        image_exists || build_image
        start_container
        ;;
    up)
        ensure_docker
        generate_env
        image_exists || build_image
        check_db
        start_container
        ;;
esac
