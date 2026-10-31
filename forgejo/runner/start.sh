#!/bin/bash
# Starts one runner (see Dockerfile). It writes the runner's configuration,
# token included, to a tmpfs (never a volume), then runs the daemon. The
# secret lives only in the Environment tab and, hashed, in Forgejo, which
# registered it with `forgejo-cli actions register` (docs/forgejo.md,
# section 14).
set -euo pipefail

fail() {
    echo "sdoc-runner: $*" >&2
    exit 1
}

[ -n "${RUNNER_NAME:-}" ] || fail "set RUNNER_NAME."
[ -n "${FORGEJO_URL:-}" ] || fail "set FORGEJO_URL."
[[ "${RUNNER_SECRET:-}" =~ ^[0-9a-f]{40}$ ]] ||
    fail "$RUNNER_NAME: its secret must be the 40 hexadecimal characters Forgejo registered (forgejo/runner/.env.example)."

# Forgejo makes a registered runner's uuid from the secret's first 16
# characters, read as bytes (RegisterRunner, models/actions/forgejo.go); the
# runner's token is the whole secret.
hex=$(printf '%s' "${RUNNER_SECRET:0:16}" | od -An -tx1 | tr -d ' \n')
uuid="${hex:0:8}-${hex:8:4}-${hex:12:4}-${hex:16:4}-${hex:20:12}"

config=/run/sdoc-runner/config.yml
umask 077
cat >"$config" <<EOF
log:
  level: info
  job_level: info

runner:
  capacity: 1
  # A job still running after an hour is stopped, so a hung test can't hold
  # one of the four runners for Forgejo's default three hours.
  timeout: 1h
  fetch_timeout: 30s
  fetch_interval: 2s
  report_interval: 1s
  # GitHub's runner images, as act publishes them: actions written for
  # GitHub (shivammathur/setup-php, actions/setup-node, ...) find apt, sudo
  # and the usual tools.
  labels:
    - ubuntu-latest:docker://ghcr.io/catthehacker/ubuntu:act-24.04
    - ubuntu-24.04:docker://ghcr.io/catthehacker/ubuntu:act-24.04
    - ubuntu-22.04:docker://ghcr.io/catthehacker/ubuntu:act-22.04

# No actions/cache server: it would write to the VM's disk outside dind's
# cap, and four runners' separate caches would rarely hit. A job's cache
# step finds nothing and goes on.
cache:
  enabled: false

container:
  # A network of its own for each job, inside dind.
  network: ""
  privileged: false
  # Jobs mount nothing and get no Docker socket.
  valid_volumes: []
  docker_host: "-"
  force_pull: false

server:
  connections:
    sdoc:
      url: ${FORGEJO_URL}
      uuid: ${uuid}
      token: ${RUNNER_SECRET}
EOF
unset RUNNER_SECRET hex

exec forgejo-runner daemon --config "$config"
