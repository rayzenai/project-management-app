# ONLYOFFICE Docs at docs.sdoc.gov.np: the Dokploy application docs-onlyoffice
# (project "docs"), Dockerfile onlyoffice/Dockerfile, context onlyoffice.
# Runbook: docs/onlyoffice.md.
#
# It is only the editor: it has no users and keeps no documents. The tracker
# signs every editor session with the shared JWT secret, serves the file and
# stores each saved version in RustFS. This server holds a working cache in
# /var/lib/onlyoffice, bind-mounted from the rustfs volume, never the VM's root
# disk. start.sh checks the Environment tab (.env.example) and writes the
# settings the image has no variable for.
#
# The domain is added in Dokploy (port 80, HTTPS); Traefik terminates TLS.

# Community Edition, AGPL-3.0. Pin the exact release and bump it by hand
# (docs/onlyoffice.md, Upgrades).
FROM onlyoffice/documentserver:10.0.0.1

# Devanagari fonts under the SIL Open Font License, licences beside them. Not
# in /usr/share/fonts/truetype/custom, which the base image declares a volume.
# The entrypoint rebuilds the editor's font list on every start.
COPY fonts/ /usr/share/fonts/truetype/sdoc/

COPY start.sh /app/sdoc/start.sh

ENV JWT_ENABLED=true \
    JWT_HEADER=Authorization \
    JWT_IN_BODY=false \
    WOPI_ENABLED=false \
    # No demo app (it edits files without signing in), no bundled plugins
    # (several send text to outside services). 10.0 still answers /welcome/
    # with a bare status page (its name and "running"), whatever this says.
    EXAMPLE_ENABLED=false \
    WELCOME_PAGE_ENABLED=false \
    PLUGINS_ENABLED=false \
    # The tracker's URLs are let through by start.sh's allow list; every other
    # address on the VM's private network stays out of reach.
    ALLOW_PRIVATE_IP_ADDRESS=false \
    ALLOW_META_IP_ADDRESS=false \
    GENERATE_FONTS=true

ENTRYPOINT ["/app/sdoc/start.sh"]
