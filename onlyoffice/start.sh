#!/bin/sh
# The editor's entrypoint (see Dockerfile): checks the Environment tab's
# values, writes the settings the image has no variable for, then starts the
# image's own entrypoint. It refuses to start while a value is missing: the
# image would otherwise make up a JWT secret the tracker doesn't know.
set -eu

missing=
for name in JWT_SECRET SECURE_LINK_SECRET DOCS_TRACKER_URL; do
    eval "value=\${$name:-}"
    [ -n "$value" ] || missing="$missing $name"
done
if [ -n "$missing" ]; then
    echo "sdoc-start: set$missing in the Environment tab (onlyoffice/.env.example)." >&2
    exit 1
fi
if [ "${#JWT_SECRET}" -lt 32 ]; then
    echo "sdoc-start: JWT_SECRET must be at least 32 characters." >&2
    exit 1
fi
case "$DOCS_TRACKER_URL" in
    *[!A-Za-z0-9.:/-]*|*//*/*/) bad=1 ;;
    https://*/) bad= ;;
    *) bad=1 ;;
esac
if [ -n "$bad" ]; then
    echo "sdoc-start: DOCS_TRACKER_URL must be the tracker's https origin with a trailing slash, such as https://tasks.sdoc.gov.np/" >&2
    exit 1
fi

# Loaded after the local.json the image's entrypoint writes, so these win.
#  - Only the tracker's URLs are fetched directly (documents, saves); any other
#    URL, even one inside a signed token, goes through the private-address
#    filter.
#  - No public Document Builder or conversion API: nothing here calls them.
cat > /etc/onlyoffice/documentserver/local-production-linux.json <<EOF
{
  "externalRequest": {
    "directIfIn": { "allowList": ["$DOCS_TRACKER_URL"], "jwtToken": false }
  },
  "services": {
    "CoAuthoring": {
      "server": { "docbuilderEnable": false, "convertServiceEnable": false }
    }
  }
}
EOF

exec /app/ds/run-document-server.sh "$@"
