# Forgejo at git.sdoc.gov.np: the Dokploy application git-forgejo (project
# "git"), build path /forgejo. Runbook: docs/forgejo.md. Every setting is a
# FORGEJO__section__KEY variable, so app.ini on the data volume is never
# edited by hand. The fixed ones are below; start.sh derives the rest from
# the application's Environment tab (see .env.example next to this file).
#
# The domain is added in Dokploy (port 3000, HTTPS), which puts this service
# on Traefik's dokploy-network. The web port is never published; only the
# built-in SSH server is, on 2222.

# 15.0 LTS, supported to 15 July 2027. Pin the exact point release and bump
# it by hand (docs/forgejo.md, Upgrades).
FROM codeberg.org/forgejo/forgejo:15.0.11

ENV FORGEJO__server__HTTP_PORT=3000 \
    FORGEJO__server__START_SSH_SERVER=true \
    FORGEJO__server__SSH_PORT=2222 \
    FORGEJO__server__SSH_LISTEN_PORT=2222 \
    FORGEJO__database__DB_TYPE=postgres \
    FORGEJO__database__NAME=forgejo \
    FORGEJO__database__USER=forgejo \
    # No web installer, ever.
    FORGEJO__security__INSTALL_LOCK=true \
    # The forge is on the internet: every person enrols 2FA (bot users are exempt).
    FORGEJO__security__GLOBAL_TWO_FACTOR_REQUIREMENT=all \
    # Sign-in only through the tracker (OIDC source "sdoc-tasks"): no
    # password form, no password over HTTP basic auth (access tokens and SSH
    # keys still work), no sign-up form, no OpenID 2.0, nothing visible to
    # anonymous visitors.
    FORGEJO__service__REQUIRE_SIGNIN_VIEW=true \
    FORGEJO__service__ALLOW_ONLY_EXTERNAL_REGISTRATION=true \
    FORGEJO__service__ENABLE_INTERNAL_SIGNIN=false \
    FORGEJO__service__ENABLE_BASIC_AUTHENTICATION=false \
    FORGEJO__openid__ENABLE_OPENID_SIGNIN=false \
    FORGEJO__openid__ENABLE_OPENID_SIGNUP=false \
    # Forgejo 16+ only (15 ignores it); the default there is false too.
    FORGEJO__service__ADD_MEMBERS_BY_INVITATIONS=false \
    # The tracker's organization is the tenant boundary: forge organizations
    # are made by a site admin for a tracker organization, never by users,
    # and are private.
    FORGEJO__service__DEFAULT_ORG_VISIBILITY=private \
    FORGEJO__service__DEFAULT_ALLOW_CREATE_ORGANIZATION=false \
    FORGEJO__admin__DISABLE_REGULAR_ORG_CREATION=true \
    FORGEJO__repository__DEFAULT_PRIVATE=private \
    # First sign-in creates the account. "nickname" takes the id_token's
    # preferred_username (the tracker's users.git_username) on 15 and 17.
    FORGEJO__oauth2_client__ENABLE_AUTO_REGISTRATION=true \
    FORGEJO__oauth2_client__USERNAME=nickname \
    FORGEJO__oauth2_client__ACCOUNT_LINKING=login \
    FORGEJO__migrations__ALLOW_LOCALNETWORKS=false \
    FORGEJO__mailer__PROTOCOL=smtps

COPY --chmod=755 start.sh /usr/local/bin/sdoc-start

# start.sh checks the environment, then hands over to the image's own
# entrypoint. Setting ENTRYPOINT clears the base image's CMD, so it is
# given again.
ENTRYPOINT ["/usr/local/bin/sdoc-start"]
CMD ["/usr/bin/s6-svscan", "/etc/s6"]

# The image ships curl. Swarm holds traffic back until this passes.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fsS -o /dev/null http://localhost:3000/api/healthz || exit 1
