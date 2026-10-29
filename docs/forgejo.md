# Forgejo at git.sdoc.gov.np

The runbook for SDOC's git forge: a Forgejo 15 LTS instance that people sign
in to with their tracker account. Design and reasons:
[`docs/superpowers/specs/2026-10-29-git-integration-design.md`](superpowers/specs/2026-10-29-git-integration-design.md)
(Phase 0). The deploy kit is [`deploy/forgejo/`](../deploy/forgejo): one
Compose file and its `.env.example`.

Nothing here has been run against production yet. The sign-in was proved end
to end on a laptop (Forgejo 17.0.1 against this tracker), and the account,
organization and suspension steps were checked against the same settings; see
[Forgejo 15 and 17](#forgejo-15-and-17). Follow the steps in order; the owner
decides when.

To run the same forge on your own machine against your local tracker, one
command does it: [Try it locally](#try-it-locally).

## What runs where

| Piece | Where |
| --- | --- |
| Forgejo `15.0.11` + Postgres 17 | SDOC VM (`10.5.23.138`), Dokploy Compose project `git`, named volumes `forgejo-data` and `forgejo-db` |
| Web | `https://git.sdoc.gov.np` through Traefik (Let's Encrypt), container port 3000, never published |
| Git over SSH | Forgejo's built-in SSH server on host port **2222** (the host's sshd keeps 22). The gateway forwards only 80 and 443, so 2222 is reachable only at `10.5.23.138`: from the VPN, whose resolver returns that address, and from office machines only if their resolver returns it too. HTTPS works wherever the web does |
| Sign-in | Only "Sign in with sdoc-tasks", the tracker as OpenID Connect provider. No password sign-in anywhere: no password form and no password over HTTP basic auth. Access tokens and SSH keys work. No sign-up form, no OpenID 2.0, no anonymous browsing |
| Tracker | `tasks.sdoc.gov.np` (Dokploy project `tasks`): the OIDC provider, and the side that calls the forge's API with the `sdoc-bot` token |

## 1. DNS at NITC

Ask NITC for an `A` record `git.sdoc.gov.np → 103.175.192.178`, the same
public address as `tasks.sdoc.gov.np`. The gateway already forwards 80 and 443
to the VM's Traefik, and Let's Encrypt's HTTP-01 challenge needs port 80, so
the record must be public even if the pages stay internal (next section).

Ask the network administrator to add the same name to the VPN's resolver,
pointing at `10.5.23.138`, as for `tasks`. Only VPN clients get that private
record. Office machines that resolve the name through public DNS get the
gateway's address, where 2222 is not forwarded, so for them SSH works only if
their resolver also returns `10.5.23.138`; HTTPS works either way.

```sh
dig +short git.sdoc.gov.np @8.8.8.8      # 103.175.192.178
dig +short git.sdoc.gov.np @1.1.1.1      # 103.175.192.178
dig +short git.sdoc.gov.np               # on the VPN: 10.5.23.138
```

## 2. Optional: web pages inside the SDOC network only

Open question 2 of the spec. If the owner wants the forge's pages reachable
only from the SDOC network and the VPN, put a Traefik IP allowlist in front of
them. Not tested here; check it from both sides before relying on it.

1. Define the middleware in Traefik's file provider on the VM, e.g.
   `/etc/dokploy/traefik/dynamic/sdoc-only.yml`:

   ```yaml
   http:
     middlewares:
       sdoc-only:
         ipAllowList:
           sourceRange:
             - "<SDOC office CIDR>"   # from the network administrator
             - "<VPN pool CIDR>"
   ```

2. Attach it to the forge's HTTPS router. A domain added in Dokploy's Domains
   tab gets labels Dokploy writes itself, so instead remove that domain and
   give the `forgejo` service its own labels (Dokploy's "manual" domain
   method), plus `dokploy-network` as an external network:

   ```yaml
   labels:
     - traefik.enable=true
     - traefik.http.routers.forgejo-web.rule=Host(`git.sdoc.gov.np`)
     - traefik.http.routers.forgejo-web.entrypoints=web
     - traefik.http.routers.forgejo-web.middlewares=redirect-to-https@file
     - traefik.http.routers.forgejo.rule=Host(`git.sdoc.gov.np`)
     - traefik.http.routers.forgejo.entrypoints=websecure
     - traefik.http.routers.forgejo.tls.certresolver=letsencrypt
     - traefik.http.routers.forgejo.middlewares=sdoc-only@file
     - traefik.http.services.forgejo.loadbalancer.server.port=3000
   ```

   Check the entrypoint, resolver and redirect middleware names against the
   labels Dokploy generated for `tasks` before copying these.

Rules for the list:

- Public traffic reaches Traefik either with its own address or with the
  gateway's. The gateway's inside address must **not** be in the list, even
  though it is in the SDOC range. Read Traefik's access log for one request
  from outside and one from the VPN to see which addresses actually arrive.
- Calls that start on the VM (the tracker's API calls now and later, the
  bootstrap's `curl --resolve … 127.0.0.1` below) arrive with a Docker-side
  address; admit the one the access log shows, or they get 403.
- HTTP-01 still works: the challenge is answered on port 80 before any router.

## 3. Tracker prerequisites

### Project keys

Task codes (`SG-12`) go into commit messages for good, so every SDOC project
key is settled before git is turned on (bootstrap step 6):

1. Right after the deploy that carries the git migrations, in the
   `tasks-web` terminal:

   ```sh
   php artisan db:seed --class=SdocProjectsSeeder --force
   ```

   It gives the SDOC projects their agreed keys and leaves any key an admin
   already chose.
2. The SDOC office admin opens each project's edit form and confirms its
   key, changing any that is wrong. A later change still works (the old key
   stays as an alias), but confirm them now so the first commits carry the
   right ones.

### Passport signing keys

The tracker signs the forge's id_tokens with an RSA key. In production the
key pair lives in `tasks-web`'s environment, not on disk:

```sh
openssl genrsa -out oauth-private.key 4096
openssl rsa -in oauth-private.key -pubout -out oauth-public.key
awk 'BEGIN { ORS = "\\n" } { print }' oauth-private.key   # one line, for PASSPORT_PRIVATE_KEY
awk 'BEGIN { ORS = "\\n" } { print }' oauth-public.key    # one line, for PASSPORT_PUBLIC_KEY
```

Set both in Dokploy (tasks → `tasks-web` → Environment), keep a copy in the
gitignored `.env.sdoc`, delete the local `.key` files, and deploy `tasks-web`.
Then:

```sh
curl -fsS https://tasks.sdoc.gov.np/.well-known/openid-configuration
#   "issuer": "https://tasks.sdoc.gov.np" (exactly APP_URL, no trailing slash),
#   endpoints on https, and no userinfo_endpoint (every claim is in the id_token)
curl -fsS https://tasks.sdoc.gov.np/oauth/jwks     # one RSA key
```

### Queue worker

Production runs `QUEUE_CONNECTION=sync` and nothing is queued yet, but phase 1
(webhooks, forge calls) needs a worker. Add it now, as the old PMOPM instance
had:

1. Dokploy, project `tasks`: a new application `tasks-queue` from the same
   repository, branch and Dockerfile as `tasks-web`, with `tasks-web`'s
   environment plus `AUTORUN_ENABLED=false` (it must not race the web app's
   migrations), command `php artisan queue:work redis --sleep=3 --tries=3
   --max-time=3600`, and the Swarm healthcheck set to none (it runs no nginx),
   as `tasks-scheduler` is configured.
2. Set `QUEUE_CONNECTION=redis` on `tasks-web`, `tasks-scheduler` and
   `tasks-queue`, and deploy all three.
3. `./deploy.sh` knows only `web` and `scheduler`. Deploy `tasks-queue` from
   Dokploy after each push, or add its application id and Swarm service name
   to `service_for` in `deploy.sh`.

## 4. The Dokploy compose project

Do not use Dokploy's Forgejo template (old version, a hard-coded database
password, no SSH).

1. Open the SDOC Dokploy (`../sdoc/open_dokploy.sh`) and create project `git`.
2. Create a service of type **Compose**: provider GitHub,
   `rayzenai/project-management-app`, branch `main`, compose path
   `./deploy/forgejo/compose.yml`.
3. Environment: paste [`deploy/forgejo/.env.example`](../deploy/forgejo/.env.example)
   and fill in every blank ([Environment](#5-environment)). Keep a copy with
   the other SDOC credentials.
4. Domains: host `git.sdoc.gov.np`, service `forgejo`, container port `3000`,
   HTTPS on, certificate Let's Encrypt. (Or the labels in section 2.)
5. Deploy. The compose file refuses to start while a required variable is
   empty, and the `forgejo` service turns healthy once `/api/healthz`
   answers.
6. Check, from a VPN-connected machine:

   ```sh
   curl -fsS https://git.sdoc.gov.np/api/healthz          # "pass"
   curl -s -o /dev/null -w '%{http_code}\n' https://git.sdoc.gov.np/explore/repos   # 303 to /user/login
   ssh-keyscan -p 2222 git.sdoc.gov.np                    # the forge's host keys
   ```

Everything below runs on the VM (`ssh sdocapp@10.5.23.138`, VPN), in bash.
Dokploy names the containers after the compose project, so look them up:

```sh
FJ=$(sudo docker ps --format '{{.Names}}' | grep -- '-forgejo-1$')
FJDB=${FJ%-forgejo-1}-db-1
fj() { sudo docker exec -u git -i "$FJ" forgejo "$@"; }   # the forgejo CLI, as the git user
```

Secrets stay off command lines, where `ps` would show them: read them with
`read -rs`, hand them to `curl` as a header file (`-H @<(printf …)`; `printf`
is a shell builtin), pass them to a container on stdin, and `unset` them after.

### Tracker and forge must reach each other

The forge fetches the tracker's discovery document and exchanges codes at
`https://tasks.sdoc.gov.np/oauth/token`; the tracker calls
`https://git.sdoc.gov.np/api/v1`. From inside containers both names resolve
to the public address, so these calls go out to the gateway and back in.
Check both directions before the bootstrap:

```sh
sudo docker exec "$FJ" curl -fsS -o /dev/null -w 'forge -> tracker %{http_code}\n' https://tasks.sdoc.gov.np/.well-known/openid-configuration
sudo docker exec "$(sudo docker ps -qf name=tasks-web | head -1)" curl -fsS -o /dev/null -w 'tracker -> forge %{http_code}\n' https://git.sdoc.gov.np/api/healthz
```

- Forge → tracker fails: add `extra_hosts: ["tasks.sdoc.gov.np:host-gateway"]`
  to the `forgejo` service. Dokploy runs this project with docker compose,
  which supports `host-gateway`; Traefik publishes 443 on the host, so the
  name lands on Traefik and the certificate still matches. Not tested here.
- Tracker → forge fails: `tasks-web` is a Dokploy application, not this
  compose project, so the same switch isn't available to it. The fallback
  (gateway hairpin from the network administrator, or another route) is for
  the operator to decide at rollout.

Keep TLS on these calls: the forge takes the id_token's claims as they come
from the token endpoint and does not check its signature, so the token
endpoint's certificate is what it trusts.

## 5. Environment

| Variable | What |
| --- | --- |
| `FORGEJO_DOMAIN` | `git.sdoc.gov.np`. `ROOT_URL` becomes `https://git.sdoc.gov.np/`, the SSH clone host the same name |
| `FORGEJO_DB_PASSWORD` | The `forgejo` Postgres role's password, used by both services. `openssl rand -hex 32` once |
| `FORGEJO_SECRET_KEY` | Forgejo's global secret. `openssl rand -hex 32` once, never change it. An empty value would fall back to a public constant, so the compose file requires it |
| `FORGEJO_TRUSTED_PROXIES` | Traefik's network as comma-separated CIDRs: `sudo docker network inspect dokploy-network --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' \| xargs \| tr ' ' ','`. The 15.x image would otherwise trust `*` |
| `FORGEJO_WEBHOOK_ALLOWED_HOSTS` | `tasks.sdoc.gov.np`: the only host webhooks may reach (phase 1) |
| `FORGEJO_SMTP_*`, `FORGEJO_MAIL_FROM` | SDOC's SMTP account, as the tracker uses: `smtp.nepal.gov.np`, 465 (implicit TLS), `no-reply@sdoc.gov.np`. Certificate checks stay on |
| `FORGEJO_SSH_PUBLISH_PORT`, `FORGEJO_MAILER_ENABLED` | Left out in production (2222, on). Only a [restore drill](#restore-drill) sets them |

Forgejo writes these into `app.ini` on the data volume at every start, so the
volume (and its backups) holds the secrets too.

## 6. Settings

Every setting is a `FORGEJO__section__KEY` variable in `compose.yml`, each
checked against the 15.0 and 17.0 configuration cheat sheets
(`forgejo.org/docs/v15.0/admin/config-cheat-sheet/`).

| Setting | Value | Why |
| --- | --- | --- |
| `server.START_SSH_SERVER`, `SSH_PORT`, `SSH_LISTEN_PORT` | `true`, `2222`, `2222` | Built-in SSH on 2222, published as `2222:2222`. The image's own OpenSSH still runs on the container's 22, unpublished and without keys |
| `security.INSTALL_LOCK` | `true` | The web installer never appears |
| `security.REVERSE_PROXY_TRUSTED_PROXIES` | Traefik's network | Client addresses only from Traefik's `X-Forwarded-For` |
| `service.REQUIRE_SIGNIN_VIEW` | `true` | Nothing, not even the explore pages or the API, without signing in |
| `service.ALLOW_ONLY_EXTERNAL_REGISTRATION` | `true` | No sign-up form; accounts come only from the OIDC source |
| `service.ENABLE_INTERNAL_SIGNIN` | `false` | No password form; the login page shows only "Sign in with sdoc-tasks" |
| `service.ENABLE_BASIC_AUTHENTICATION` | `false` | No password over HTTP basic auth (API, git over HTTPS). Access tokens still work, in a header or as the basic-auth password |
| `openid.ENABLE_OPENID_SIGNIN`, `ENABLE_OPENID_SIGNUP` | `false` | No OpenID 2.0 sign-in or sign-up (already off by default once `INSTALL_LOCK` is set; explicit so it stays off) |
| `service.ADD_MEMBERS_BY_INVITATIONS` | `false` | Not in 15 (ignored); exists from 16, where `false` is also the default. Set so the upgrade changes nothing |
| `service.DEFAULT_ORG_VISIBILITY`, `service.DEFAULT_ALLOW_CREATE_ORGANIZATION`, `admin.DISABLE_REGULAR_ORG_CREATION` | `private`, `false`, `true` | The tracker's organization is the tenant boundary, so only a site admin makes a forge organization, for a tracker organization, and it is private. (The API ignores the default visibility: pass `"visibility":"private"`, as below) |
| `repository.DEFAULT_PRIVATE` | `private` | New repositories start private |
| `oauth2_client.ENABLE_AUTO_REGISTRATION` | `true` | The first sign-in creates the account |
| `oauth2_client.USERNAME` | `nickname` | The account's name. Forgejo's OIDC provider fills "nickname" from the `nickname` claim, else `preferred_username`; the tracker sends only `preferred_username` (its `users.git_username`). 16 and later also offer `preferred_username`; 15 does not (it would warn and fall back to `nickname`) |
| `oauth2_client.ACCOUNT_LINKING` | `login` | If the name or email already exists, show the link page instead of merging silently |
| `webhook.ALLOWED_HOST_LIST` | `tasks.sdoc.gov.np` | Webhooks may call the tracker and nothing else |
| `migrations.ALLOW_LOCALNETWORKS` | `false` | Migrations and mirrors can't reach private addresses (the default, set explicitly) |
| `mailer.*` | SDOC SMTP over `smtps` | Notifications and admin mail |

Left at their defaults on purpose: `oauth2_client.REGISTER_EMAIL_CONFIRM`
(off, so new accounts are active at once) and the source's
`--allow-username-change` (off: "Non-local users are not allowed to change
their username").

## 7. Bootstrap

Once, in this order. The account names are fixed: the tracker never hands out
`forge-admin`, any connected organization's name, or `{owner}-bot` as a
person's username (`GitUsernames`), because forge users and organizations
share one namespace. Use email addresses no tracker person has, or that
person's first sign-in lands on the link page.

1. **Break-glass site admin** `forge-admin`, used only through `forgejo admin`
   inside the container:

   ```sh
   fj admin user create --admin --username forge-admin \
     --email forge-admin@sdoc.gov.np --random-password --must-change-password=false >/dev/null
   ```

   The generated password is discarded unseen: nothing accepts it (no
   password form, no basic auth). When an admin API call is needed that no
   CLI command covers, mint a token for this account with the CLI, use it, and
   delete it, as in step 3. Super-admins already arrive as site admins
   through the tracker for anything in the web UI.

2. **The bot** `sdoc-bot` (`{owner}-bot`), owner of the `sdoc` organization
   and nothing else:

   ```sh
   fj admin user create --username sdoc-bot \
     --email sdoc-bot@sdoc.gov.np --random-password --must-change-password=false >/dev/null
   fj admin user generate-access-token --username sdoc-bot --token-name tasks-sdoc \
     --scopes read:user,write:organization --raw
   ```

   The token is shown once; it goes into the tracker in step 6 and nowhere
   else. `read:user` lets the tracker check who the token is;
   `write:organization` lets it manage the organization (its webhook in
   phase 1). Phase 2 (repos, teams) will need a wider token, replaced from
   the same Organizations dialog.

3. **The organization** `sdoc`, private, owned by the bot. Users can't create
   organizations, so `forge-admin` creates it, hands it to the bot and
   leaves; the one-off token is then deleted. The calls go to Traefik on the
   VM itself (`--resolve`), whatever the public name resolves to:

   ```sh
   (
     set -euo pipefail
     ADMIN_TOKEN=$(fj admin user generate-access-token --username forge-admin \
       --token-name bootstrap --scopes write:organization --raw)
     api() { curl -fsS --resolve git.sdoc.gov.np:443:127.0.0.1 \
       -H @<(printf 'Authorization: token %s\n' "$ADMIN_TOKEN") \
       -H 'Content-Type: application/json' "$@"; }
     api -d '{"username":"sdoc","full_name":"SDOC","visibility":"private"}' \
       https://git.sdoc.gov.np/api/v1/orgs
     OWNERS=$(api https://git.sdoc.gov.np/api/v1/orgs/sdoc/teams \
       | python3 -c 'import json, sys; print(next(t["id"] for t in json.load(sys.stdin) if t["name"] == "Owners"))')
     api -X PUT "https://git.sdoc.gov.np/api/v1/teams/$OWNERS/members/sdoc-bot"
     api -X DELETE https://git.sdoc.gov.np/api/v1/orgs/sdoc/members/forge-admin
     echo 'sdoc ready'
   )
   sudo docker exec "$FJDB" psql -U forgejo -d forgejo \
     -c "DELETE FROM access_token WHERE name = 'bootstrap'"
   ```

   The calls run in a subshell with `set -euo pipefail`, so the first one
   that fails stops the rest, prints curl's error, and never prints
   `sdoc ready`; the token only lives inside the subshell. Run it as one
   block, not inside `&&` or `||` (bash ignores `set -e` there). The delete
   after it always runs:

   - It must print `DELETE 1`. `DELETE 0` means no token was made (the
     first command failed); anything else, stop and look at `access_token`
     before going on.
   - No `sdoc ready`: fix what curl reported, then finish only the calls
     that did not happen (a new `bootstrap` token first, the same delete
     after). Creating `sdoc` again fails once it exists.

   Forgejo can delete a token over its API only with basic auth, which is
   off, hence the one-line delete in the database. The forge organization is
   named after the tracker organization's slug.

4. **The OIDC client** on the tracker (`tasks-web` terminal in Dokploy):

   ```sh
   php artisan git:oidc-client https://git.sdoc.gov.np/user/oauth2/sdoc-tasks/callback
   ```

   It prints the client ID and secret; the secret is stored hashed and shown
   only this once. The callback must match the forge's byte for byte:
   `{ROOT_URL}user/oauth2/{source name}/callback`. Running it again keeps the
   ID and issues a new secret.

5. **The OIDC source** on the forge. `forgejo admin auth add-oauth` takes the
   secret only as a flag, so it goes in on stdin and is placed on that flag
   inside the container: it never reaches the host's `docker` command line or
   the shell history, and sits on the CLI's own command line only for the
   second it runs.

   ```sh
   read -r CLIENT_ID
   read -rs CLIENT_SECRET
   printf '%s\n' "$CLIENT_SECRET" | sudo docker exec -i -u git "$FJ" sh -c '
     read -r SECRET
     exec forgejo admin auth add-oauth --name sdoc-tasks --provider openidConnect \
       --key "$1" --secret "$SECRET" \
       --auto-discover-url https://tasks.sdoc.gov.np/.well-known/openid-configuration \
       --scopes openid --scopes profile --scopes email --scopes git \
       --group-claim-name groups --admin-group forge-admins \
       --required-claim-name git_orgs' sh "$CLIENT_ID"
   unset CLIENT_SECRET
   ```

   - Repeat `--scopes` once per scope. One quoted `"openid profile email git"`
     is stored as a single scope, and the forge then asks for
     `openid profile email git openid`.
   - The source name is also the button's label and part of the callback
     URL. Forgejo 15 and 17 have no separate display name, so the button reads
     "Sign in with sdoc-tasks"; a name with a space ("SDOC Tasks") would put
     `%20` in the callback.
   - `--required-claim-name git_orgs`: the tracker leaves `git_orgs` out for
     anyone it lets into no forge organization, and Forgejo then refuses the
     sign-in. The tracker already answers 403 at `/oauth/authorize` for them,
     so this is the second lock.
   - `--admin-group forge-admins`: tracker super-admins get `groups:
     ["forge-admins"]` and are made site admins on every sign-in; losing
     super-admin removes it at the next sign-in.
   - After `forgejo admin auth update-oauth ...` restart the `forgejo` service
     (Dokploy → Restart): the running server keeps the old settings until
     then. `add-oauth` takes effect at once.

6. **Turn git on for SDOC** in the tracker, once the office admin has
   confirmed every project key ([Project keys](#project-keys)): a
   super-admin opens **Organizations**, chooses **Git** on SDOC, and enters
   forge address `https://git.sdoc.gov.np`, forge organization `sdoc` and the
   bot token from step 2. The tracker checks the token against the forge
   before saving it (encrypted). Its answers:

   | Message | Meaning |
   | --- | --- |
   | Couldn't reach the forge at {url}. | No answer, or a 5xx: wrong address, or [no hairpin](#tracker-and-forge-must-reach-each-other). The cause (the cURL error) is in the tracker's log |
   | The forge refused that token. | A 401 or 403 on `/api/v1/user`: a mistyped, deleted or under-scoped token |
   | {url} doesn't look like a Forgejo forge. | A 404, or a 200 that carries no login: the address is not a Forgejo |
   | The forge answered {status}. Try again shortly. | Any other status |
   | {login} doesn't own {owner} on the forge. | The token works but its user is not an owner of that organization |
   | {owner} is already connected to another office. | Another office already uses that forge organization |

   Two validation messages come before any forge call: the address must not
   carry credentials, and must not carry a query or a fragment.

## 8. Check

The spec's Phase 0 check, after the bootstrap:

- An SDOC member chooses "Sign in with sdoc-tasks", signs in to the tracker
  if needed, and lands on the forge signed in. Their forge username equals
  the tracker's stored one:
  `php artisan tinker --execute 'echo App\Models\User::where("email", "…")->value("git_username");'`.
  They create an access token (Settings → Applications) and push over HTTPS
  with it, and add an SSH key and push over port 2222 from the VPN.
- Someone with no SDOC membership and on no SDOC project team gets the
  tracker's 403 page ("You don't have access to git. Ask your office
  admin.") and no forge account.
- A deactivated member who has never used the forge gets the same 403. One
  who already has a forge account is taken out of the forge organization by
  the tracker at once (check the confirmation says "{git_username} was
  removed from the forge."); their personal tokens and SSH keys are cut only
  by [suspending](#9-offboarding) the account.
- A super-admin arrives as a site admin (Site administration in the user
  menu).

## 9. Offboarding

When an office admin deactivates or deletes someone with a forge account, the
tracker removes them from every connected forge organization (`DELETE
/api/v1/orgs/{owner}/members/{git_username}`, with each organization's own
bot token) and the confirmation says so ("{git_username} was removed from the
forge."). That ends their access through organization membership and teams; direct per-repository collaborator grants are separate, and a site admin removes those by hand. A
restored member must be re-added to the forge teams by hand.

Suspending the forge account stays as the fallback, and as the way to also
cut their personal access tokens and SSH keys, which removal from an
organization does not touch. Do it when the confirmation says "Couldn't
remove {git_username} from the forge; ask a super-admin to suspend them there
today." (the tracker couldn't reach the forge), or when the person must lose
everything at once:

1. A super-admin signs in to the forge (they are site admins) and opens Site
   administration → Identity & access → User accounts.
2. The person (their forge username is the tracker's `git_username`) → Edit
   → tick **Suspended account** → Update user account.

That blocks everything at once: the web session, API tokens ("This account is
prohibited from signing in"), git over HTTPS (the "Account is suspended" page)
and SSH ("Forgejo: Your account is disabled."). There is no `forgejo admin`
command for it in 15; the API equivalent is
`PATCH /api/v1/admin/users/{username}` with `{"prohibit_login": true}`, using
a `write:admin` token minted for `forge-admin` and deleted afterwards as in
bootstrap step 3. Untick it if the person comes back.

Taking someone off a cross-organization project team while they stay active
does not touch the forge; that waits for phase 1's nightly reconcile.

A connected forge must sign people in only through this tracker, so forge
usernames are the tracker's; removal on deactivation walks every connected
forge organization by that name.

## 10. Backups

From day one: a nightly database dump and a copy of the data volume, kept off
the VM, and a restore drill.

**Go-live gate:** nobody pushes real code until all three hold:

1. the nightly copy reaches a destination off the VM (where is the owner's
   open question 3);
2. one [restore drill](#restore-drill) has passed;
3. a deactivation can't leave forge access open: met by the tracker
   removing the person from every connected forge organization
   ([offboarding](#9-offboarding)); a "Couldn't remove" confirmation still
   needs a super-admin to suspend the account the same day.

`/usr/local/sbin/forgejo-backup` (root, mode 700):

```sh
#!/usr/bin/env bash
set -euo pipefail
DEST=/var/backups/forgejo
STAMP=$(date +%F)
FJ=$(docker ps --format '{{.Names}}' | grep -- '-forgejo-1$')
FJDB=${FJ%-forgejo-1}-db-1
DATA=${FJ%-forgejo-1}_forgejo-data
mkdir -p "$DEST" && chmod 700 "$DEST"
docker exec "$FJDB" pg_dump -U forgejo -Fc forgejo > "$DEST/forgejo-db-$STAMP.dump"
docker run --rm -v "$DATA":/data:ro -v "$DEST":/backup alpine \
  tar czf "/backup/forgejo-data-$STAMP.tar.gz" -C /data .
find "$DEST" -name 'forgejo-*' -mtime +14 -delete
# then copy $DEST off the VM, e.g. rsync to the backup host once it exists
```

Run it nightly from root's crontab (`sudo crontab -e`):

```
30 1 * * * /usr/local/sbin/forgejo-backup >> /var/log/forgejo-backup.log 2>&1
```

The data volume holds the repositories, attachments, SSH host keys and
`app.ini` with its secrets: keep the copies as private as the Dokploy
credentials. The dump runs first, so a push during the copy can leave a
repository slightly ahead of the database; `forgejo doctor` reconciles that
after a restore.

### Restore drill

On a scratch copy, never on top of production:

1. Dokploy: a new compose project (say `git-restore`) from the same
   repository and compose path, with production's environment and these
   overrides, and no domain:

   ```
   FORGEJO_DOMAIN=git-restore.invalid
   FORGEJO_SSH_PUBLISH_PORT=2224
   FORGEJO_MAILER_ENABLED=false
   FORGEJO_WEBHOOK_ALLOWED_HOSTS=loopback
   ```

   so it can't take port 2222, email real people or call the production
   tracker. Deploy it once (it starts empty).

2. Restore the latest backup into it (`R` is the scratch project's app name
   in Dokploy):

   ```sh
   R=<scratch app name>
   sudo docker stop "$R-forgejo-1"
   sudo docker exec -i "$R-db-1" pg_restore -U forgejo -d forgejo --clean --if-exists \
     < forgejo-db-YYYY-MM-DD.dump
   sudo docker run --rm -v "${R}_forgejo-data":/data -v "$PWD":/backup alpine \
     sh -c 'find /data -mindepth 1 -delete && tar xzf /backup/forgejo-data-YYYY-MM-DD.tar.gz -C /data'
   sudo docker start "$R-forgejo-1"
   sudo docker exec -u git "$R-forgejo-1" forgejo doctor check --all
   ```

3. Check it with a token, not a sign-in: the copy's OIDC source still sends
   people to production's callback. The copy has no domain, so clone inside
   its container, which checks what the drill is for (the restored database
   and repositories, and token access):

   ```sh
   T=$(sudo docker exec -u git "$R-forgejo-1" forgejo admin user generate-access-token \
     --username forge-admin --token-name drill --scopes read:repository --raw)
   printf '%s\n' "$T" | sudo docker exec -i -u git "$R-forgejo-1" sh -c '
     read -r T
     export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.extraHeader GIT_CONFIG_VALUE_0="Authorization: token $T"
     git clone -q http://localhost:3000/sdoc/<repo>.git /tmp/drill && git -C /tmp/drill log --oneline -3'
   unset T
   ```

   The last commits should match production's.

4. Delete the scratch project and its volumes in Dokploy, and note the date
   of the drill.

Dokploy's own volume backups to an S3-compatible destination are an
alternative for the volume copy once such a destination exists.

## 11. Upgrades

- **15.0.x point releases** (security fixes): read the release notes on
  codeberg.org, run the backup, bump the tag in `compose.yml`
  (`codeberg.org/forgejo/forgejo:15.0.N`), push, redeploy, then
  `fj doctor check --all` and the [checks](#8-check). Forgejo publishes
  security advisories on its blog and release notes; watch them.
- **15 → 19 LTS** (19.0 is due in April 2027; 15 is supported to 15 July
  2027): Forgejo's upgrade guide says to back up fully, then go straight to the
  latest release; if that fails, step through the latest release of each
  series in between. Never use 17.0.0, which the project advised against.
  Rehearse on a [restore drill](#restore-drill) copy first, then:
  1. Read the breaking changes of 16, 17, 18 and 19.
  2. Back up, bump the image tag to the exact 19.0.x, redeploy,
     `fj doctor check --all`, run the [checks](#8-check).
  3. Settings to revisit: `oauth2_client.USERNAME` may become
     `preferred_username` (16+), though `nickname` keeps working;
     `ADD_MEMBERS_BY_INVITATIONS` starts to apply (already `false`); the
     image's `REVERSE_PROXY_TRUSTED_PROXIES` default stops being `*` from 16
     (ours is explicit anyway). Postgres stays on 17 unless 19 requires more.

## Try it locally

[`deploy/forgejo/local.sh`](../deploy/forgejo/local.sh) runs Forgejo from
Homebrew on your machine with the settings of `compose.yml` (SQLite instead of
Postgres, plain http on `127.0.0.1`, no mail) and connects it to your tracker,
exactly as the [bootstrap](#7-bootstrap) does in production. Homebrew has
Forgejo 17, not 15; see [Forgejo 15 and 17](#forgejo-15-and-17).

If you run `php artisan git:oidc-client` by hand, delete
`$FORGEJO_LOCAL_DIR/oidc-client` (default
`~/.local/share/sdoc-forgejo/oidc-client`) so the next `up` hands the forge
the new secret.

### Before you start

- The tracker is migrated (`php artisan migrate`) and running (`composer dev`)
  at its `APP_URL`.
- Your super-admin's email is in `PM_SUPER_ADMINS` and has a login
  (`php artisan db:seed`).
- SDOC exists (`php artisan db:seed --class=SdocProjectsSeeder`) and has a
  member with a login: as the super-admin, People, Add person, organization
  SDOC (every person gets a login).
- For the 403 check, someone outside SDOC and on no SDOC project team, e.g.
  an OPMCM person from `php artisan db:seed --class=WorkspaceDemoSeeder`
  (password `password`).
- Ports 3300 and 2222 are free. Homebrew is installed; the script installs
  `forgejo` with it if it is missing.

### Commands

```sh
deploy/forgejo/local.sh up       # install, start, set up, and print the next steps
deploy/forgejo/local.sh status   # running or not, and whether the sign-in source exists
deploy/forgejo/local.sh down     # stop the forge (up starts it again)
deploy/forgejo/local.sh reset    # stop it and delete its data, after you type "reset"
```

The forge is at `http://127.0.0.1:3300`, git over SSH at `127.0.0.1:2222`. Its
data, including `app.ini` with its secret key and the bot token (mode 600),
lives in `~/.local/share/sdoc-forgejo`, outside the repository; set
`FORGEJO_LOCAL_DIR` to an absolute path to put it elsewhere (not `/` or your
home directory). The tracker is `APP_URL` from `.env`; set `TRACKER_URL` to
use another one. The log is `forgejo.log` in the data directory. `up` stops
with "port 3300 is in use by …" (or 2222) when another program holds a port.

The first `up` installs Forgejo, writes `app.ini`, starts the server, creates
`forge-admin`, `sdoc-bot` and the private organization `sdoc` (owned by
`sdoc-bot`, the one-off `forge-admin` token deleted afterwards), writes
`sdoc-bot`'s token to `sdoc-bot.token`, runs `php artisan passport:keys` if
the signing keys are missing, registers the forge with `php artisan
git:oidc-client`, and adds the sign-in source `sdoc-tasks` with the flags of
[bootstrap step 5](#7-bootstrap) plus one local-only flag, `--skip-local-2fa`:
locally the forge never asks for its own two-factor code after the tracker's
sign-in (production leaves that flag off). It never prints a token or secret.

Running `up` again reuses all of that, so the bot token and the sign-in
source stay as they are. Two things make it replace one:

- The tracker's Forgejo client is gone or was replaced (a fresh database), or
  `TRACKER_URL` changed. `up` then runs `php artisan git:oidc-client` again
  (a new client, or a new secret for the existing one), updates the source,
  and restarts the forge.
- The forge no longer has the bot token in `sdoc-bot.token`. `up` writes a
  new one; turn git on again with it.

`php artisan` runs with the tracker's `.env`, so it writes the client into the
tracker's database. If `up` says the tracker "calls itself" another address,
`TRACKER_URL` and the tracker's `APP_URL` disagree: the forge must use the
address the tracker puts in its discovery document.

`reset` asks first and does nothing unless you type `reset`. It deletes only a
data directory whose `app.ini` this script wrote.

### An https tracker

The forge, a Go program, must trust the tracker's certificate. `https://pmopm.test`
(served through AssistantHub with its own development CA) passed: a Go
program using the system roots got the proxy's answer, not a certificate
error. If yours fails, the sign-in stops after the tracker with an `x509`
error in `forgejo.log`. Then use plain http on this machine: set
`APP_URL=http://127.0.0.1:8000` in `.env`, run `php artisan config:clear`,
serve the tracker with `php artisan serve` (port 8000), and run
`TRACKER_URL=http://127.0.0.1:8000 deploy/forgejo/local.sh up`, which moves
the sign-in source to that address.

### Checks

`up` ends by printing these steps (with your data directory):

```text
1. Turn git on for SDOC. Copy the bot token with

     pbcopy < ~/.local/share/sdoc-forgejo/sdoc-bot.token

   then, as a super-admin in the tracker: Organizations, Git on SDOC,
   forge address http://127.0.0.1:3300, forge organization sdoc, paste the bot
   token, Turn on git. It says "Git is on."

2. Sign in. Open http://127.0.0.1:3300 in a private window, a new one for each
   person, and choose "Sign in with sdoc-tasks":
   - an SDOC member lands on the forge signed in, with the username the
     tracker stored (git_username);
   - someone outside SDOC and on no SDOC project team gets the tracker's 403
     page, "You don't have access to git. Ask your office admin.", and no
     forge account;
   - a super-admin arrives as a site admin (Site administration in the user
     menu);
   - back in step 1, a wrong token gets "The forge refused that token."

3. Push, as the SDOC member, to a repository they create on the forge:
   - over SSH, after adding a public key (Settings, SSH / GPG keys):
       git push ssh://git@127.0.0.1:2222/<username>/<repo>.git
   - over http, giving an access token that can read and write repositories
     (Settings, Applications) as the password:
       git push http://127.0.0.1:3300/<username>/<repo>.git

4. Offboarding. The super-admin, on the forge, puts the member in a team of
   sdoc (sdoc, Teams, New team, then add the member). In the tracker,
   deactivate the member: the confirmation says "<git_username> was removed
   from the forge.", and they are gone from sdoc's Members on the forge.
```

### Clean up

1. `deploy/forgejo/local.sh reset`, and type `reset`.
2. Turn git off for SDOC in the tracker (Organizations, Git on SDOC, Turn off
   git): the token it holds died with the forge.
3. Keep `storage/oauth-*.key` (gitignored): the tracker signs sign-ins with
   them. The tracker's Forgejo client stays too; the next `up` gives it a new
   secret.
4. `brew uninstall forgejo` if you no longer want it.

## Forgejo 15 and 17

The local checks ran Forgejo 17.0.1 (Homebrew has no 15) with the same
settings as `compose.yml` except SQLite, plain http on `127.0.0.1` and SSH on
a local port: the sign-in against this tracker, and separately the bootstrap
(password basic auth refused, tokens accepted in a header and as the
basic-auth password, regular users refused an organization, the
create-hand-over-leave sequence, the token deleted in the database) and
suspension (API, HTTPS and SSH all refused). What carries over to 15.0.11,
from Forgejo's source and cheat sheets:

- The OIDC path is the same code in both: the same `goth` v1.82.0 provider
  and the same `providers_openid.go`. It fills "nickname" from `nickname`,
  then `preferred_username`; checks `aud`, `iss` against discovery's
  `issuer`, and `exp` (read as a number, so the tracker's microsecond
  `iat`/`exp` are fine); does not check the signature or need a `kid`; and
  skips userinfo when discovery lists no `userinfo_endpoint`.
- Suspension: 15 checks `ProhibitLogin` for SSH (`routers/private/serv.go`),
  git over HTTP and the web (`routers/web/repo/githttp.go`,
  `routers/web/web.go`), and labels it "Suspended account", as 17 does.
- Settings that differ: `oauth2_client.USERNAME = preferred_username` exists
  only from 16 (we use `nickname`, valid in both);
  `service.ADD_MEMBERS_BY_INVITATIONS` exists only from 16; the container
  image's `REVERSE_PROXY_TRUSTED_PROXIES` default is `*` before 16. Every
  other key in `compose.yml` is in both cheat sheets.
- `forgejo admin auth add-oauth` has the same flags in both (17 adds
  `--dyn-group-maps`, unused here). Neither has a CLI command to suspend a
  user or delete a token.
