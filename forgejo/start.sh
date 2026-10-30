#!/bin/sh
# The forge's entrypoint (see Dockerfile): turns the Environment tab's
# FORGEJO_* values into Forgejo's FORGEJO__section__KEY settings, then starts
# the image's own entrypoint. It refuses to start while a required value is
# empty: an empty SECRET_KEY, for one, would fall back to a public constant.
set -eu

missing=
for name in FORGEJO_DOMAIN FORGEJO_DB_HOST FORGEJO_DB_PASSWORD FORGEJO_SECRET_KEY \
    FORGEJO_TRUSTED_PROXIES FORGEJO_WEBHOOK_ALLOWED_HOSTS \
    FORGEJO_SMTP_ADDR FORGEJO_SMTP_USER FORGEJO_SMTP_PASSWORD FORGEJO_MAIL_FROM; do
    eval "value=\${$name:-}"
    [ -n "$value" ] || missing="$missing $name"
done
if [ -n "$missing" ]; then
    echo "sdoc-start: set$missing in the Environment tab (forgejo/.env.example)." >&2
    exit 1
fi

export FORGEJO__server__DOMAIN="$FORGEJO_DOMAIN"
export FORGEJO__server__ROOT_URL="https://$FORGEJO_DOMAIN/"
export FORGEJO__server__SSH_DOMAIN="$FORGEJO_DOMAIN"

export FORGEJO__database__HOST="$FORGEJO_DB_HOST"
export FORGEJO__database__PASSWD="$FORGEJO_DB_PASSWORD"

export FORGEJO__security__SECRET_KEY="$FORGEJO_SECRET_KEY"
# The 15.x image defaults this to "*"; trust only Traefik's network.
export FORGEJO__security__REVERSE_PROXY_TRUSTED_PROXIES="$FORGEJO_TRUSTED_PROXIES"

export FORGEJO__webhook__ALLOWED_HOST_LIST="$FORGEJO_WEBHOOK_ALLOWED_HOSTS"

export FORGEJO__mailer__ENABLED="${FORGEJO_MAILER_ENABLED:-true}"
export FORGEJO__mailer__SMTP_ADDR="$FORGEJO_SMTP_ADDR"
export FORGEJO__mailer__SMTP_PORT="${FORGEJO_SMTP_PORT:-465}"
export FORGEJO__mailer__USER="$FORGEJO_SMTP_USER"
export FORGEJO__mailer__PASSWD="$FORGEJO_SMTP_PASSWORD"
export FORGEJO__mailer__FROM="$FORGEJO_MAIL_FROM"

# The branding (forgejo/custom). Forgejo reads custom files from GITEA_CUSTOM,
# /data/gitea on the volume, so they are copied there on every start, over
# whatever was there: the image is their only source.
custom="${GITEA_CUSTOM:-/data/gitea}"
mkdir -p "$custom"
for dir in public templates; do
    rm -rf "${custom:?}/$dir"
    cp -R "/usr/local/share/sdoc-forgejo/custom/$dir" "$custom/$dir"
    chown -R git:git "$custom/$dir"
done

exec /usr/bin/entrypoint "$@"
