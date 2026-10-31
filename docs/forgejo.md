# Forgejo at git.sdoc.gov.np

The runbook for SDOC's git forge: a Forgejo 15 LTS instance that people sign
in to with their tracker account, whose repositories projects link so that
commits, branches and pull requests show on their tasks. Design and reasons:
[`docs/superpowers/specs/2026-10-29-git-integration-design.md`](superpowers/specs/2026-10-29-git-integration-design.md)
(Phase 0 and Phase 1) and
[`docs/superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md`](superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md)
(Phase 2: [section 13](#13-repositories-access-and-code-activity)). The deploy kit is [`forgejo/`](../forgejo): a
Dockerfile on the pinned Forgejo image, its start script and `.env.example`,
plus `local.sh` for a laptop; [`forgejo/runner/`](../forgejo/runner) holds
the runners that test every pull request ([section 14](#14-runners-and-test-results)).
The forge deploys on its own, never with the tracker.

In production since 2026-10-30: DNS ([section 1](#1-dns-at-nitc)) and the
Dokploy project ([section 4](#4-the-dokploy-project)) are done, and
`https://git.sdoc.gov.np` answers. The [bootstrap](#7-bootstrap) ran the
same day, git is on for SDOC, and the forge carries SDOC's
[branding](#branding). The sign-in was proved end
to end on a laptop (Forgejo 17.0.1 against this tracker), with and without the
two-factor requirement, and the account, organization and suspension steps
were checked against the same settings; see
[Forgejo 15 and 17](#forgejo-15-and-17). Phase 1 was proved the same way on
17.0.1: the webhook made by turning git on, linking with its backfill,
pushes, a branch and a pull request through to its merge, the link back,
a replayed delivery, and the reconcile filling a gap left while the tracker
was down and moving the webhook to a new tracker address. Follow the steps
in order; the owner decides when.

To run the same forge on your own machine against your local tracker, one
command does it: [Try it locally](#try-it-locally).

## What runs where

| Piece | Where |
| --- | --- |
| Forgejo `15.0.11` + Postgres 17 | SDOC VM (`10.5.23.138`), Dokploy project `git`: application `git-forgejo` (built from `forgejo/`, volume `git-forgejo-data` at `/data`) and database `git-postgres` (database and role `forgejo`) |
| Web | `https://git.sdoc.gov.np` through Traefik (Let's Encrypt), container port 3000, never published |
| Git over SSH | Forgejo's built-in SSH server on host port **2222** (the host's sshd keeps 22). The gateway forwards only 80 and 443, so 2222 is reachable only at `10.5.23.138`: from the VPN, whose resolver returns that address, and from office machines only if their resolver returns it too. HTTPS works wherever the web does |
| Sign-in | Only "Sign in with sdoc-tasks", the tracker as OpenID Connect provider, then the forge's own second factor, which every person must enrol ([section 2](#2-public-behind-two-factor-sign-in)). No password sign-in anywhere: no password form and no password over HTTP basic auth. Access tokens and SSH keys work. No sign-up form, no OpenID 2.0, no anonymous browsing |
| Tracker | `tasks.sdoc.gov.np` (Dokploy project `tasks`): the OIDC provider, the side that calls the forge's API with the `sdoc-bot` token, and the receiver of the forge's webhook (`/git/webhooks/{id}`, [section 12](#12-webhook-and-nightly-reconcile)). `tasks-scheduler` also runs `git:reconcile` nightly, `git:sync-access` every ten minutes, `git:commit-stats` every five ([section 13](#13-repositories-access-and-code-activity)) and `git:commit-checks` every two ([section 14](#14-runners-and-test-results)) |
| Runners | Dokploy compose `git-runners` in project `git` (from `forgejo/runner/`): four Forgejo runners, one job each, registered to `sdoc`, every job inside one `docker:dind` held to 4 vCPUs, 10 GB of memory and 30 GB of disk ([section 14](#14-runners-and-test-results)) |

## 1. DNS at NITC

Done (2026-10-30): the public record answers `103.175.192.178`, and the VPN's
resolver and the VM's own answer `10.5.23.138`.

Ask NITC for an `A` record `git.sdoc.gov.np → 103.175.192.178`, the same
public address as `tasks.sdoc.gov.np`. The gateway already forwards 80 and 443
to the VM's Traefik, and Let's Encrypt's HTTP-01 challenge needs port 80, so
the record is public, and so are the forge's pages, behind two-factor sign-in
(next section).

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

## 2. Public, behind two-factor sign-in

The owner chose (2026-10-29) to put the forge on the internet like the
tracker, with no IP allowlist, behind two-factor sign-in:
`security.GLOBAL_TWO_FACTOR_REQUIREMENT = all` in `forgejo/Dockerfile`. Every person
enrols a second factor on the forge and gives it after the tracker's sign-in.
Forgejo exempts bot users, which is what `sdoc-bot` is
([bootstrap](#7-bootstrap)). Git over SSH stays inside the SDOC network and
the VPN (the gateway forwards only 80 and 443); from outside, people push over
HTTPS with an access token.

### Two-factor for people

- **First sign-in.** After "Sign in with sdoc-tasks" and the tracker's own
  sign-in, the forge makes the account and opens Settings → Security with
  "This Forgejo instance requires users to enable two-factor authentication
  before they can access their accounts." Every other page sends them back
  there. Until they enrol, the API answers 403 ("… Enable it at:
  https://git.sdoc.gov.np/user/settings/security") even to a token, git over
  HTTPS is redirected to that page, and SSH is refused with the same message.
  They enrol either:
  - an authenticator app (TOTP): **Enroll into two-factor authentication**,
    scan the image (or type the secret under it), enter the 6-digit
    passcode, **Verify**; or
  - a security key: **Add security key**.
- **Every later sign-in** asks, after the tracker's sign-in, for the passcode
  (or the security key). A wrong passcode is refused ("Your passcode is
  incorrect. If you misplaced your device, use your scratch code to sign
  in.") and nothing is signed in until the right one.
- **Git over HTTPS** takes an access token as the password, made after
  enrolling: Settings → Applications → **New access token**, repository read
  and write. A token works without the passcode, so give it only the scopes
  it needs, and delete it there when a laptop is lost.
- **The recovery key.** Enrolling shows a single-use recovery key once
  ("Store your single-use recovery key (…) in a safe place, as it will not be
  shown again."); keep it somewhere other than the phone. On the passcode
  page, **Use a scratch code** takes it instead; the forge then opens
  Settings → Security, where **Regenerate single-use recovery key** makes a
  new one. Nobody can turn two-factor off ("Two-factor authentication is
  required for your account and cannot be disabled."); a new phone is set up
  with **Re-enroll two-factor authentication** while the old one still works.
- **A lost second factor and no recovery key.** A site admin removes it, after
  confirming the person outside the forge (in person or by phone: this reset
  is what an attacker would ask for):

  ```sh
  fj admin user reset-mfa --username <git_username>
  ```

  It prints "<name>'s two-factor authentication settings have been removed!"
  and deletes the TOTP and any security keys. The web UI does the same: Site
  administration → Identity & access → User accounts → the person → Edit →
  tick **Reset 2FA** → Update user account (nothing else on the account
  changes). Until the person enrols again, every page sends them to enrol and
  their access tokens are refused; once they have, the tokens work again.

## 3. Tracker prerequisites

### Project keys

Task codes (`EGP-12`) go into commit messages for good, so every SDOC
project key is settled before git is turned on (bootstrap step 6).

Production got SDOC's agreed keys from the migration `2026_10_31_140000`
(deployed 2026-10-30): `EGP`, `GIWMS`, `SUTRA`, `GIOMS`, `NAGARIK` and
`OSOP`, each old key kept as an alias. Never run `SdocProjectsSeeder` there.
What remains: the SDOC organizational admin opens each project's edit form
and confirms its key, changing any that is wrong. A later change still works
(the old key stays as an alias), but confirm them now so the first commits
carry the right ones.

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

Phase 1 needs a worker: the forge's webhook deliveries, the backfill after a
repository is linked and the Owners sync after an organizational-admin change all run
on the queue. Production runs `QUEUE_CONNECTION=sync` until it exists, and
then that work runs in the web request after its response: php-fpm sends the
forge its 202 first (`fastcgi_finish_request`), so the forge's delivery
timeout never waits on it, but it holds an FPM worker until it is done, and a
failed run is not retried (the worker retries a job three times, a minute
apart). Either way, a failed delivery is processed again when someone
replays it on the forge ([section 12](#12-webhook-and-nightly-reconcile)),
or the nightly reconcile fills in what it carried. Add the worker:

1. Dokploy, project `tasks`: a new application `tasks-queue` from the same
   repository, branch and Dockerfile as `tasks-web`, with `tasks-web`'s
   environment plus `AUTORUN_ENABLED=false` (it must not race the web app's
   migrations), command `php artisan queue:work redis --sleep=3 --tries=3
   --timeout=320 --max-time=3600`, and the Swarm healthcheck set to none (it
   runs no nginx), as `tasks-scheduler` is configured. A backfill may take up
   to five minutes (`BackfillGitRepository::$timeout = 300`), so the worker's
   `--timeout` sits above it, and Redis's `retry_after` must sit above that
   again, or a second worker starts the same job while the first still runs:
   set `REDIS_QUEUE_RETRY_AFTER=360` (the default is 90; see
   `config/queue.php`) on all three applications.
2. Set `QUEUE_CONNECTION=redis` on `tasks-web`, `tasks-scheduler` and
   `tasks-queue`, and deploy all three.
3. `./deploy.sh` knows only `web` and `scheduler`. Deploy `tasks-queue` from
   Dokploy after each push, or add its application id and Swarm service name
   to `service_for` in `deploy.sh`.

### Request size

The webhook intake takes deliveries of up to 2 MB and answers 413 to anything
larger, so the tracker's web server must let a 2 MB body through to it. The
image (`serversideup/php:8.5-fpm-nginx`) already does: its
`NGINX_CLIENT_MAX_BODY_SIZE` (nginx `client_max_body_size`) and
`PHP_POST_MAX_SIZE` (PHP `post_max_size`) both default to `100M`, the tracker
sets neither, and Traefik limits a body only through a buffering middleware,
which Dokploy does not add. If either variable is ever set on `tasks-web`,
keep it at `2M` or more.

## 4. The Dokploy project

Set up on 2026-10-30, apart from the tracker's project `tasks`: the forge
deploys, restarts and is backed up on its own, and a tracker deploy never
touches it. Do not use Dokploy's Forgejo template (old version, a hard-coded
database password, no SSH). To build it again (`../sdoc/open_dokploy.sh`
opens the panel):

1. Project `git`.
2. **Database** `git-postgres` (app name `git-postgres-gy325l`): PostgreSQL,
   image `postgres:17`, database `forgejo`, user `forgejo`, password
   `openssl rand -hex 32`, no external port. Deploy it.
3. **Application** `git-forgejo` (app name `git-forgejo-a845sc`):
   - Provider Git: `https://github.com/rayzenai/project-management-app.git`,
     branch `main`, build path `/`.
   - Build type Dockerfile: Docker file `forgejo/Dockerfile`, Docker context
     path `forgejo`.
   - Auto deploy off. Deploy it by hand when `forgejo/` changes (an upgrade
     or a setting); a push to `main` never restarts the forge.
   - Environment: [`forgejo/.env.example`](../forgejo/.env.example) with
     every blank filled ([Environment](#5-environment)); Create Env File off.
   - Volumes: volume `git-forgejo-data`, mount path `/data`.
   - Ports: published `2222`, target `2222`, TCP, publish mode Host.
   - Domains: host `git.sdoc.gov.np`, path `/`, container port `3000`, HTTPS
     on, certificate Let's Encrypt.
   - Advanced, Swarm settings, Update config:
     `{"Parallelism": 1, "Order": "stop-first", "FailureAction": "rollback"}`.
     Dokploy otherwise starts the new container before it stops the old one,
     and two Forgejo processes must never share `/data`, whose queues and
     indexes take file locks (the published port would clash too).
4. Deploy. `start.sh` refuses to start while a required variable is empty
   (`sdoc-start: set … in the Environment tab (forgejo/.env.example).` in the
   application's logs), and Swarm sends no traffic until `/api/healthz`
   answers.
5. Check (the web checks from anywhere, `ssh-keyscan` from the VPN):

   ```sh
   curl -fsS https://git.sdoc.gov.np/api/healthz          # "pass"
   curl -s -o /dev/null -w '%{http_code}\n' https://git.sdoc.gov.np/explore/repos   # 303 to /user/login
   ssh-keyscan -p 2222 git.sdoc.gov.np                    # the forge's host keys
   ```

Everything below runs on the VM (`ssh sdocapp@10.5.23.138`, VPN), in bash.
Swarm names a container `<app name>.<slot>.<task id>`, so look them up, and
again after every deploy or restart (the task id changes):

```sh
FJ=$(sudo docker ps --format '{{.Names}}' | grep -E '^git-forgejo-[a-z0-9]+\.' | head -1)
FJDB=$(sudo docker ps --format '{{.Names}}' | grep -E '^git-postgres-[a-z0-9]+\.' | head -1)
fj() { sudo docker exec -u git -i "$FJ" forgejo "$@"; }   # the forgejo CLI, as the git user
```

Paste the blocks below one at a time, not as one long paste: `docker exec -i`
reads standard input, so lines pasted after it can be swallowed.

Secrets stay off command lines, where `ps` would show them: read them with
`read -rs`, hand them to `curl` as a header file (`-H @<(printf …)`; `printf`
is a shell builtin), pass them to a container on stdin, and `unset` them after.

### Tracker and forge reach each other on the VM

The forge fetches the tracker's discovery document and exchanges codes at
`https://tasks.sdoc.gov.np/oauth/token`; the tracker calls
`https://git.sdoc.gov.np/api/v1`, and the forge delivers its webhook to the
tracker. On the VM both names resolve to the VM's own address, `10.5.23.138`
(its resolvers, `202.45.144.2` and `.3`, answer that, and containers inherit
them), so these calls go to Traefik on the VM, with the real certificates,
and never leave it. They don't depend on anyone's VPN. The public address
doesn't loop back: from the VM, `103.175.192.178` doesn't answer. Both
directions answered 200 on 2026-10-30; check again after any DNS or network
change:

```sh
TW=$(sudo docker ps --format '{{.Names}}' | grep -E '^tasks-web-[a-z0-9]+\.' | head -1)
getent hosts git.sdoc.gov.np tasks.sdoc.gov.np     # 10.5.23.138, both
sudo docker exec "$FJ" curl -fsS -o /dev/null -w 'forge -> tracker %{http_code}\n' https://tasks.sdoc.gov.np/.well-known/openid-configuration
sudo docker exec "$TW" curl -fsS -o /dev/null -w 'tracker -> forge %{http_code}\n' https://git.sdoc.gov.np/api/healthz
```

If a call times out and `getent` shows the public address, the VM's resolver
lost the private records: ask the network administrator to restore them.

Keep TLS on these calls, and never point either side at a container name
over plain http: the forge takes the id_token's claims as they come from the
token endpoint and does not check its signature, so the token endpoint's
certificate is what it trusts, and any service on Dokploy's shared network
could answer to a container name.

## 5. Environment

| Variable | What |
| --- | --- |
| `FORGEJO_DOMAIN` | `git.sdoc.gov.np`. `ROOT_URL` becomes `https://git.sdoc.gov.np/`, the SSH clone host the same name |
| `FORGEJO_DB_HOST` | `git-postgres`'s Internal Host in Dokploy, with the port: `git-postgres-gy325l:5432` |
| `FORGEJO_DB_PASSWORD` | The `forgejo` role's password: the same value as on `git-postgres`. `openssl rand -hex 32` once |
| `FORGEJO_SECRET_KEY` | Forgejo's global secret. `openssl rand -hex 32` once, never change it. An empty value would fall back to a public constant, so `start.sh` requires it |
| `FORGEJO_TRUSTED_PROXIES` | Traefik's network as comma-separated CIDRs (`172.20.1.0/24`): `sudo docker network inspect dokploy-network --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' \| xargs \| tr ' ' ','`. The 15.x image would otherwise trust `*` |
| `FORGEJO_WEBHOOK_ALLOWED_HOSTS` | `tasks.sdoc.gov.np`: the only host webhooks may reach. It must admit the tracker's host, because the tracker's webhook calls `https://tasks.sdoc.gov.np/git/webhooks/{id}` ([section 12](#12-webhook-and-nightly-reconcile)); a host it leaves out gets no delivery |
| `FORGEJO_SMTP_*`, `FORGEJO_MAIL_FROM` | SDOC's SMTP account, as the tracker uses: `smtp.nepal.gov.np`, 465 (implicit TLS), `no-reply@sdoc.gov.np`. Certificate checks stay on |
| `FORGEJO_MAILER_ENABLED` | Left out in production (on). Only a [restore drill](#restore-drill) sets it, to `false` |

`start.sh` turns these into Forgejo's settings, and Forgejo writes them into
`app.ini` on the data volume at every start, so the volume (and its backups)
holds the secrets too. The two generated secrets are also kept, commented
out, in the gitignored `.env.sdoc`.

## 6. Settings

Every setting is a `FORGEJO__section__KEY` variable, the fixed ones in
`forgejo/Dockerfile` and the rest set by `forgejo/start.sh` from the
Environment tab, each checked against the 15.0 and 17.0 configuration cheat sheets
(`forgejo.org/docs/v15.0/admin/config-cheat-sheet/`).

| Setting | Value | Why |
| --- | --- | --- |
| `server.START_SSH_SERVER`, `SSH_PORT`, `SSH_LISTEN_PORT` | `true`, `2222`, `2222` | Built-in SSH on 2222, published as `2222:2222`. The image's own OpenSSH still runs on the container's 22, unpublished and without keys |
| `security.INSTALL_LOCK` | `true` | The web installer never appears |
| `security.REVERSE_PROXY_TRUSTED_PROXIES` | Traefik's network | Client addresses only from Traefik's `X-Forwarded-For` |
| `security.GLOBAL_TWO_FACTOR_REQUIREMENT` | `all` | Every person enrols a second factor before anything works, the API and git included ([Two-factor for people](#two-factor-for-people)); bot users are exempt. Any value other than `none`, `all` or `admin` silently means `none`, so check it in the [checks](#8-check) |
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
| `webhook.ALLOWED_HOST_LIST` | `tasks.sdoc.gov.np` | Webhooks may call the tracker's intake and nothing else |
| `migrations.ALLOW_LOCALNETWORKS` | `false` | Migrations and mirrors can't reach private addresses (the default, set explicitly) |
| `mailer.*` | SDOC SMTP over `smtps` | Notifications and admin mail |
| `APP_NAME`, `other.SHOW_FOOTER_*`, `ui.*`, `ui.meta.*` | `SDOC Git`, `false`, the SDOC themes, SDOC's name | [Branding](#branding) |

Left at their defaults on purpose: `oauth2_client.REGISTER_EMAIL_CONFIRM`
(off, so new accounts are active at once) and the source's
`--allow-username-change` (off: "Non-local users are not allowed to change
their username").

### Branding

The forge is "SDOC Git", a sibling of the tracker in sdoc.gov.np's identity:
the Nepal emblem, its Nepali and English names, Public Sans with Noto Sans
Devanagari, and its colours (navy ink, government blue, crimson for the
centre's Nepali name, the same dark navy in dark mode). All of it rides on the
stock image through Forgejo's own customization points, so an upgrade is
still a `FROM` bump:

- **Settings** (`forgejo/Dockerfile`): `APP_NAME = SDOC Git` (page titles,
  the "©" line at the foot of every email; it lives in app.ini's unnamed top
  section, so its variable is `FORGEJO____APP_NAME`, four underscores);
  `other.SHOW_FOOTER_POWERED_BY`, `SHOW_FOOTER_VERSION` and
  `SHOW_FOOTER_TEMPLATE_LOAD_TIME` off (site admins still see the version on
  admin pages); `ui.DEFAULT_THEME = sdoc-auto` and `ui.THEMES` the three SDOC
  themes; `ui.meta` author, description and keywords (`ui_0X2E_meta`, the
  escape for the dot).
- **Files** ([`forgejo/custom/`](../forgejo/custom)), laid out as Forgejo's
  custom directory, which `start.sh` copies over `/data/gitea/public` and
  `/data/gitea/templates` on every start (`GITEA_CUSTOM`, on the volume): so
  the repository is their only source, and nothing is edited on the VM.
  - `public/assets/img/`: `logo.svg`, `favicon.svg` (the emblem, from
    `../sdoc/public/assets/nepal-emblem.png`, padded square, inside an SVG),
    `logo.png`, `favicon.png`, `apple-touch-icon.png`, and `sdoc-tasks.svg`
    (the tracker's mark, the sign-in button's icon).
  - `public/assets/css/`: `sdoc.css` (fonts, the home page, and hiding
    Forgejo's hard-coded Help link to its own documentation) and the themes
    `theme-sdoc-light.css`, `theme-sdoc-dark.css`, `theme-sdoc-auto.css`. Each
    imports Forgejo's own theme and overrides only its colour variables.
  - `public/assets/fonts/`: Public Sans and Noto Sans Devanagari (OFL 1.1),
    from sdoc's `node_modules`.
  - `templates/home.tmpl`: the signed-out home page, in place of Forgejo's.
    Its button goes straight to `/user/oauth2/sdoc-tasks`.
    `templates/custom/extra_links.tmpl` adds **Tasks** to the navbar, and
    `extra_links_footer.tmpl` adds sdoc.gov.np to the footer (Forgejo's empty
    hooks for exactly this).
- **The sign-in button's icon**, stored with the OIDC source, set once:

  ```sh
  fj admin auth update-oauth --id 1 --icon-url https://git.sdoc.gov.np/assets/img/sdoc-tasks.svg
  ```

  then restart the forge. The button's label stays "Sign in with
  sdoc-tasks": the source name is also in the callback URL.
- **Existing accounts keep the theme stored on them**, and Forgejo still
  serves its own theme files, so after any change to `ui.THEMES` move them
  onto one that is offered (done on 2026-10-30 for the accounts made before
  the branding; no restart needed):

  ```sh
  sudo docker exec "$FJDB" psql -U forgejo -d forgejo \
    -c "UPDATE \"user\" SET theme = 'sdoc-auto' WHERE theme NOT IN ('sdoc-auto', 'sdoc-light', 'sdoc-dark')"
  ```

Forgejo's name stays only where admins and developers look (Site
administration, the API's Swagger page). To try a change, run a stock
`forgejo web --custom-path forgejo/custom` against a scratch `app.ini`
(Homebrew's 17 renders these templates the same way).

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
   password form, no basic auth). Super-admins already arrive as site admins
   through the tracker for anything in the web UI.

   It stays an ordinary (individual) user for good. Forgejo makes the first
   person to sign in a site admin when no other individual user exists, and
   never demotes its last individual admin, so `forge-admin` must always be
   counted as one. Under the two-factor requirement the API refuses an
   ordinary user's token, so it is a bot only while step 3 uses its one-off
   token; any later admin API call that no CLI command covers is done the
   same way.

2. **The bot** `sdoc-bot` (`{owner}-bot`), owner of the `sdoc` organization
   and nothing else, made a bot user before its token is made or used:

   ```sh
   fj admin user create --username sdoc-bot \
     --email sdoc-bot@sdoc.gov.np --random-password --must-change-password=false >/dev/null
   sudo docker exec "$FJDB" psql -U forgejo -d forgejo \
     -c "UPDATE \"user\" SET type = 4 WHERE lower_name = 'sdoc-bot'"
   fj admin user generate-access-token --username sdoc-bot --token-name tasks-sdoc \
     --scopes read:user,write:organization,write:repository --raw
   ```

   The update must print `UPDATE 1`. A service account can't enrol a second
   factor, and Forgejo exempts bot users (type 4) from the requirement;
   neither the CLI nor the API sets the type, hence the database. It stays a
   bot for good: it is not an admin, so it never counts for the first-user or
   last-admin rules.

   The token is shown once; it goes into the tracker in step 6 and nowhere
   else. `read:user` lets the tracker check who the token is;
   `write:organization` lets it manage the organization (its webhook, its
   Owners team and its members); `write:repository` lets it read the
   repositories, branches, commits and pull requests a project links, and set
   each linked repository's external tracker, the forge's link back to the
   task. Phase 2 uses the same scopes (it creates repositories, sets their
   collaborators and branch rule, and reads commits and diffs with
   `write:repository`). It is still not a site-admin token.

   **Replacing the token** (an install whose token predates phase 1, made
   with `read:user,write:organization` only: the Code tab still lists the
   forge organization's repositories, but linking one fails with "The forge
   answered 403. Try again shortly."):

   ```sh
   fj admin user generate-access-token --username sdoc-bot --token-name tasks-sdoc-2 \
     --scopes read:user,write:organization,write:repository --raw
   ```

   A super-admin enters it in the tracker exactly as in step 6
   (**Organizations**, **Git** on SDOC, the same forge address and forge
   organization, the new token). It says "Git is on." and keeps the webhook,
   or creates it if there is none yet. Then delete the old token, in the
   database because the API deletes tokens only with basic auth; it must say
   `DELETE 1`:

   ```sh
   sudo docker exec "$FJDB" psql -U forgejo -d forgejo \
     -c "DELETE FROM access_token WHERE name = 'tasks-sdoc' AND uid = (SELECT id FROM \"user\" WHERE lower_name = 'sdoc-bot')"
   ```

3. **The organization** `sdoc`, private, owned by the bot. Users can't create
   organizations, so `forge-admin` creates it, hands it to the bot and
   leaves; the one-off token is then deleted. `forge-admin` is a bot only
   inside this block, which is safe because nobody can sign in yet (the
   sign-in source comes in step 5, git for SDOC in step 6). The calls go to
   Traefik on the VM itself (`--resolve`), whatever the public name resolves
   to:

   ```sh
   fjsql() { sudo docker exec "$FJDB" psql -U forgejo -d forgejo "$@"; }
   end_bootstrap() {
     fjsql -c "DELETE FROM access_token WHERE name = 'bootstrap'" \
       -c "UPDATE \"user\" SET type = 0 WHERE lower_name = 'forge-admin'" \
       -c "SELECT lower_name, type FROM \"user\" WHERE lower_name = 'forge-admin'"
   }
   (
     set -euo pipefail
     trap end_bootstrap EXIT
     fjsql -c "UPDATE \"user\" SET type = 4 WHERE lower_name = 'forge-admin'"
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
   ```

   The calls run in a subshell with `set -euo pipefail`, so the first one
   that fails stops the rest, prints curl's error, and never prints
   `sdoc ready`; the token only lives inside the subshell. Run it as one
   block, not inside `&&` or `||` (bash ignores `set -e` there). However the
   subshell ends, a failed call included, its `trap` runs `end_bootstrap`,
   which deletes the token and makes `forge-admin` an ordinary user again.
   It ends with `UPDATE 1` and this, every time:

   ```text
    lower_name  | type
   -------------+------
    forge-admin |    0
   (1 row)
   ```

   - The `DELETE` above it must say `DELETE 1`. `DELETE 0` means no token was
     made (the token command failed); anything else, stop and look at
     `access_token` before going on.
   - A type other than `0`, or a block cut off half-way (a dropped SSH
     session): in the new session repeat the container lookup above (`FJ`,
     `FJDB`, `fj`), define `fjsql` and `end_bootstrap` again and run
     `end_bootstrap` until it shows `0`. Nothing goes on while it isn't.
   - No `sdoc ready`: fix what curl reported, then run the block again,
     leaving out the calls that already succeeded: `POST /orgs` once `sdoc`
     exists, and `forge-admin` leaving once it has left. Adding `sdoc-bot` to
     Owners again is harmless.

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
   - No `--skip-local-2fa`, ever: with it the forge takes the tracker's
     sign-in alone and never asks for the passcode. `forgejo admin auth
     update-oauth` silently ignores that flag (it exits 0 and changes
     nothing, in 15 and 17), so the source must be added without it from the
     start. If it ever was: Site administration → Authentication sources →
     sdoc-tasks → untick **Skip local 2FA**.
   - After `forgejo admin auth update-oauth ...` restart the forge (Dokploy,
     `git-forgejo`, Reload): the running server keeps the old settings until
     then. `add-oauth` takes effect at once.

6. **Turn git on for SDOC** in the tracker, once the organizational admin has
   confirmed every project key ([Project keys](#project-keys)): a
   super-admin opens **Organizations**, chooses **Git** on SDOC, and enters
   forge address `https://git.sdoc.gov.np`, forge organization `sdoc` and the
   bot token from step 2. The tracker checks the token against the forge
   before saving it (encrypted), then creates the organization's webhook
   ([section 12](#12-webhook-and-nightly-reconcile)); nothing is saved
   unless both work. Its answers:

   | Message | Meaning |
   | --- | --- |
   | Couldn't reach the forge at {url}. | No answer, or a 5xx: wrong address, or [the forge unreachable from the VM](#tracker-and-forge-reach-each-other-on-the-vm). The cause (the cURL error) is in the tracker's log |
   | The forge refused that token. | A 401 or 403 on `/api/v1/user`: a mistyped, deleted or under-scoped token, or `sdoc-bot` not made a bot user (step 2), which the two-factor requirement refuses |
   | {url} doesn't look like a Forgejo forge. | A 404, or a 200 that carries no login: the address is not a Forgejo |
   | The forge answered {status}. Try again shortly. | Any other status |
   | {login} doesn't own {owner} on the forge. | The token works but its user is not an owner of that organization |
   | {owner} is already connected to another office. | Another office already uses that forge organization |
   | Couldn't create the forge webhook: the forge answered {status}. | The token passed the checks but the forge refused the webhook: a 403 is a token that may read the organization but not write it (`read:organization`) |

   Two validation messages come before any forge call: the address must not
   carry credentials, and must not carry a query or a fragment. The address
   must also be the forge's real one, not an alias that redirects to it
   (such as an `http://` or `www.` name that forwards elsewhere), because the
   tracker's ownership checks do not follow redirects.

## 8. Check

The spec's Phase 0 check, after the bootstrap:

- The two-factor setup: Site administration → Configuration shows "Global
  two-factor requirement: All users"; Authentication sources → sdoc-tasks has
  **Skip local 2FA** unticked; and the service accounts have the right types,
  `forge-admin` `0` (an ordinary admin) and `sdoc-bot` `4` (a bot):

  ```sh
  sudo docker exec "$FJDB" psql -U forgejo -d forgejo \
    -c "SELECT lower_name, type, is_admin FROM \"user\" WHERE lower_name IN ('forge-admin', 'sdoc-bot')"
  ```

- An SDOC member chooses "Sign in with sdoc-tasks", signs in to the tracker
  if needed, and lands on the forge's Settings → Security with the red
  "requires users to enable two-factor authentication" message, not as a
  site admin, even if they are the first person ever to sign in. Every other
  page sends them back there. They enrol and keep the recovery key. Their
  forge username equals the tracker's stored one:
  `php artisan tinker --execute 'echo App\Models\User::where("email", "…")->value("git_username");'`.
- They sign out and in again: after the tracker, the forge asks for the
  passcode; a wrong one is refused, the right one signs them in. They create
  an access token (Settings → Applications) and push over HTTPS with it, and
  add an SSH key and push over port 2222 from the VPN.
- Someone with no SDOC membership and on no SDOC project team gets the
  tracker's 403 page ("You don't have access to git. Ask your
  organizational admin.") and no forge account.
- A deactivated member who has never used the forge gets the same 403. One
  who already has a forge account is taken out of the forge organization by
  the tracker at once (check the confirmation says "{git_username} was
  removed from the forge."); their personal tokens and SSH keys are cut only
  by [suspending](#9-offboarding) the account.
- A super-admin is sent to enrol like everyone else, and then arrives as a
  site admin (Site administration in the user menu).

Phase 1, once git is on (`SG-1` stands for any task code of the project):

- **The webhook.** On the forge, sdoc → Settings → Webhooks lists one
  Forgejo hook to `https://tasks.sdoc.gov.np/git/webhooks/{id}`, active, with
  the push, create, delete, pull request and repository events.
- **Linking.** The SDOC organizational admin is an owner of `sdoc` (Teams → Owners;
  the reconcile puts them there once they have signed in to the forge, so
  run it by hand after their first sign-in, [section 12](#12-webhook-and-nightly-reconcile)).
  They create a repository in `sdoc` and push a commit `SG-1 first change`.
  In the tracker, the project's **Code** tab → **Link repository** → the
  repository: "Linked sdoc/{name}.", and within seconds the commit is in
  `SG-1`'s Code section (the backfill, on the queue).
- **Push.** A commit `SG-1 second change` pushed to the repository is in the
  task's Code section within seconds; the hook's Recent deliveries on the
  forge show a 202 for it.
- **Pull request.** A branch `sg-1-thing` and a pull request from it show in
  the task's Code section, and the task's board card shows the pull-request
  mark. Merging it shows it merged; the task's status does not change.
- **Link back.** On the forge, `SG-1` in a commit message is a link to
  `https://tasks.sdoc.gov.np/workspace/go/sdoc/SG-1`, which opens the task
  (after the tracker's sign-in); someone who can't see the task gets a 404.
- **Reconcile.** In `tasks-web`'s terminal, `php artisan git:reconcile` prints
  `sdoc at https://git.sdoc.gov.np: reconciled 1 repository.` and exits 0. A
  second run prints the same, with no "repaired the webhook" and no write to
  the hook.

Phase 2, once the Phase 1 checks pass (see [section 13](#13-repositories-access-and-code-activity)):

- **New repository.** As the project's manager (or an organizational admin),
  Code tab → Link repository → New repository `sg-api`: "Created and linked
  sdoc/sg-api." On the forge it is private, with a README on `main` and a
  `main` rule (Settings → Branches): push off, admins included, status
  checks `*` and one approval required ([section 14](#14-runners-and-test-results)).
- **Access.** Within a minute the repository's Settings → Collaborators lists
  the project's manager (and its program's manager) as Administrator. Give a
  teammate `write` in the Code tab's Access: they appear as a collaborator
  with Write; they can push a branch, but a push straight to `main` is
  refused, theirs and the manager's alike. Remove the grant: they are gone from
  Collaborators. A person who has never signed in to the forge shows as
  waiting, and `php artisan git:sync-access` prints `1 waiting`.
- **Leaving.** Take a granted teammate off the project's team: their
  collaborator entry goes at once. Deactivate a test account that has a forge
  account: it is gone from the forge organization and from every repository.
- **Code activity.** After a few pushes, `php artisan git:commit-stats`
  prints `Counted {n} commits.`, and the sidebar's Code activity lists the
  pusher with lines counted. A commit made with an email the forge doesn't know
  shows under "Not matched to anyone".

## 9. Offboarding

When an organizational admin deactivates or deletes someone with a forge account, the
tracker removes them from every connected forge organization (`DELETE
/api/v1/orgs/{owner}/members/{git_username}`, with each organization's own
bot token) and the confirmation says so ("{git_username} was removed from the
forge."). That ends their access through organization membership and teams;
their access as a repository's collaborator goes next (below). A restored
member must be re-added to the forge teams by hand.

Suspending the forge account stays as the fallback, and as the way to also
cut their personal access tokens and SSH keys, which removal from an
organization does not touch. Do it when the confirmation says "Couldn't
remove {git_username} from the forge; ask a super-admin to suspend them there
today." (the forge couldn't be reached, or refused a removal), or when the person must lose
everything at once:

1. A super-admin signs in to the forge (they are site admins) and opens Site
   administration → Identity & access → User accounts.
2. The person (their forge username is the tracker's `git_username`) → Edit
   → tick **Suspended account** → Update user account.

That blocks everything at once: the web session, API tokens ("This account is
prohibited from signing in"), git over HTTPS (the "Account is suspended" page)
and SSH ("Forgejo: Your account is disabled."). There is no `forgejo admin`
command for it in 15, so it is done here, by a super-admin, who has a second
factor. Untick it if the person comes back.

Deactivating or deleting someone also takes them off the collaborators of every
repository of each connected forge organization, linked to a project or not,
at once and with the bot's token
([section 13](#13-repositories-access-and-code-activity)). It lists each
repository's collaborators and removes the person only where they are one, so
the log ("Took someone off a forge repository's collaborators.") holds only
real removals. The message says so, or asks for a manual suspend when the
forge could not be reached or a repository could not be checked.

Unlinking a repository keeps its forge collaborators: people who had access
keep it on the forge until it's changed there or the repository is linked
again (deactivating someone still takes them off it while the forge
organization is connected). Turning git off, or re-pointing a connection at
another forge or forge organization, leaves every collaborator as they are
too, and offboarding no longer reaches that forge organization: suspend a
person who leaves on that forge as above.

Taking someone off a project's team, or moving them to another office, while
they stay active changes their repository access at once: leaving the team
deletes their grants on its repositories, and either change queues an access
sync of the repositories concerned
([Who has access](#who-has-access)), which production's sync queue runs right
after the web response. Their forge organization membership waits for the
nightly reconcile, which removes them from every forge organization the
tracker no longer lets them into
([section 12](#12-webhook-and-nightly-reconcile)). It also keeps each forge organization's Owners team in step with the office's
active organizational admins, and a reactivated organizational admin goes back into Owners at
once;
everyone else who comes back is re-added to forge teams by hand.

A connected forge must sign people in only through this tracker, so forge
usernames are the tracker's; removal on deactivation walks every connected
forge organization by that name.

## 10. Backups

From day one: a nightly database dump and a copy of the data volume, on the
VM. Copies off the VM and a restore drill come with a new backup VM (the
owner's answer, 2026-10-29).

**Go-live gate:** nobody pushes real code until a deactivation can't leave
forge access open: met by the tracker removing the person from every
connected forge organization and from the collaborators of its repositories
([offboarding](#9-offboarding)); a "Couldn't
remove" confirmation still needs a super-admin to suspend the account the
same day.

The owner deferred the gate's other two conditions until the backup VM
exists: the nightly copy reaching it, and one [restore drill](#restore-drill)
passed. **The risk until then:** every copy of the forge sits on the VM it
runs on. The nightly dumps undo a bad upgrade or a deleted repository, not the
loss of the VM. If its disk, the VM or its host is lost before the backup VM
exists, the forge's issues, pull requests, reviews, wikis, accounts and
settings are gone for good, and the code survives only in the branches
developers happen to have cloned. Once the backup VM exists, copy `$DEST` to
it nightly (the script's last comment) and run the restore drill.

`/usr/local/sbin/forgejo-backup` (root, mode 700):

```sh
#!/usr/bin/env bash
set -euo pipefail
DEST=/var/backups/forgejo
STAMP=$(date +%F)
FJDB=$(docker ps --format '{{.Names}}' | grep -E '^git-postgres-[a-z0-9]+\.' | head -1)
DATA=git-forgejo-data
[ -n "$FJDB" ] || { echo "git-postgres is not running" >&2; exit 1; }
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

1. Dokploy, project `git`: a database `git-restore-postgres` made like
   `git-postgres` (its own password), and an application `git-restore` made
   like `git-forgejo` but with no domain, no port and its own volume
   `git-restore-data` at `/data`, with production's environment and these
   overrides:

   ```
   FORGEJO_DOMAIN=git-restore.invalid
   FORGEJO_DB_HOST=<git-restore-postgres's Internal Host>:5432
   FORGEJO_DB_PASSWORD=<git-restore-postgres's password>
   FORGEJO_MAILER_ENABLED=false
   FORGEJO_WEBHOOK_ALLOWED_HOSTS=loopback
   ```

   so it can't take port 2222, email real people or call the production
   tracker. Deploy both once (the forge starts empty).

2. Restore the latest backup into it (`RS` is `git-restore`'s app name in
   Dokploy). Scale it to zero rather than stopping its container, which Swarm
   would only replace:

   ```sh
   RS=<git-restore's app name>
   RDB=$(sudo docker ps --format '{{.Names}}' | grep -E '^git-restore-postgres-[a-z0-9]+\.' | head -1)
   sudo docker service scale "$RS=0"
   sudo docker exec -i "$RDB" pg_restore -U forgejo -d forgejo --clean --if-exists \
     < forgejo-db-YYYY-MM-DD.dump
   sudo docker run --rm -v git-restore-data:/data -v "$PWD":/backup alpine \
     sh -c 'find /data -mindepth 1 -delete && tar xzf /backup/forgejo-data-YYYY-MM-DD.tar.gz -C /data'
   sudo docker service scale "$RS=1"
   R=$(sudo docker ps --format '{{.Names}}' | grep -E "^$RS\." | head -1)
   sudo docker exec -u git "$R" forgejo doctor check --all
   ```

   The restored `app.ini` carries production's settings, but `start.sh`
   writes the overrides above over them at every start.

3. Check it with a token, not a sign-in: the copy's OIDC source still sends
   people to production's callback. The copy has no domain, so clone inside
   its container, which checks what the drill is for (the restored database
   and repositories, and token access). The copy requires two-factor too, and
   git refuses an ordinary user's token there, so `forge-admin` is a bot for
   the check, exactly as in [bootstrap step 3](#7-bootstrap):

   ```sh
   rsql() { sudo docker exec "$RDB" psql -U forgejo -d forgejo "$@"; }
   end_drill() {
     rsql -c "DELETE FROM access_token WHERE name = 'drill'" \
       -c "UPDATE \"user\" SET type = 0 WHERE lower_name = 'forge-admin'" \
       -c "SELECT lower_name, type FROM \"user\" WHERE lower_name = 'forge-admin'"
   }
   (
     set -euo pipefail
     trap end_drill EXIT
     rsql -c "UPDATE \"user\" SET type = 4 WHERE lower_name = 'forge-admin'"
     T=$(sudo docker exec -u git "$R" forgejo admin user generate-access-token \
       --username forge-admin --token-name drill --scopes read:repository --raw)
     printf '%s\n' "$T" | sudo docker exec -i -u git "$R" sh -c '
       read -r T
       export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.extraHeader GIT_CONFIG_VALUE_0="Authorization: token $T"
       git clone -q http://localhost:3000/sdoc/<repo>.git /tmp/drill && git -C /tmp/drill log --oneline -3'
   )
   ```

   The last commits should match production's, and it ends with
   `forge-admin` at type `0`.

4. Delete `git-restore` and `git-restore-postgres` in Dokploy, then the
   volume (`sudo docker volume rm git-restore-data`), and note the date of
   the drill.

Dokploy's own volume backups to an S3-compatible destination are an
alternative for the volume copy once such a destination exists.

## 11. Upgrades

- **15.0.x point releases** (security fixes): read the release notes on
  codeberg.org, run the backup, bump the `FROM` tag in `forgejo/Dockerfile`
  (`codeberg.org/forgejo/forgejo:15.0.N`), push, deploy `git-forgejo` in
  Dokploy (auto deploy is off), then
  `fj doctor check --all` and the [checks](#8-check). Then compare
  Forgejo's `templates/home.tmpl` at the new tag with ours (the one page we
  replace) and look over the home, sign-in and a repository page in both
  themes ([Branding](#branding)). Forgejo publishes
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

## 12. Webhook and nightly reconcile

### The webhook

Turning git on ([bootstrap step 6](#7-bootstrap)) creates one organization
webhook on `sdoc`, owned by the tracker: type Forgejo, `POST` to
`{APP_URL}/git/webhooks/{connection id}` (`https://tasks.sdoc.gov.np/git/webhooks/1`),
JSON, every branch, the events push, create, delete, pull request,
repository and the workflow-run events success and failure (the forge lists
pull request with its sub-events), signed with a
secret the tracker keeps encrypted. Leave it alone on the forge: the
reconcile puts back whatever an owner changes. Entering the token again keeps
it; pointing the connection at another forge, or another forge organization,
makes a new one, deletes the old, and unlinks every repository linked from
the old one, with their history in the tracker ("Git is on. Unlinked 2
repositories that were on the old forge."): link them again from the new
one. Turning git off deletes the hook and points every linked repository
back at the forge's own issue tracker (best effort: a forge that is down
never keeps git on, and a repository it missed keeps linking `TP-12` here
until an owner changes it under the repository's Settings). Either way every
repository's collaborators stay as they are on the forge
([section 9](#9-offboarding)).

What it needs: `FORGEJO_WEBHOOK_ALLOWED_HOSTS` admitting the tracker's host
([Environment](#5-environment)), the forge reaching the tracker
([section 4](#tracker-and-forge-reach-each-other-on-the-vm)), a 2 MB request body
([Request size](#request-size)) and the queue worker
([Queue worker](#queue-worker)).

The tracker stores each delivery once, by its delivery id, keeps it 14 days,
and answers: 202 `Received.` (queued), 200 `Already received.` (a delivery
id it already has; one still unprocessed is queued again), 403 for a missing
or wrong signature, 404 for an unknown connection, 413 over 2 MB, 400 for
anything that is not a forge delivery. Events other than those seven are
stored and ignored. Applying a delivery twice changes nothing, so any
delivery can be sent again. The forge never retries on its own. To resend
one that failed (the forge shows it with a warning sign; the tracker keeps a
failed run's error on its `git_webhook_deliveries` row): sdoc → Settings →
Webhooks → the hook → Recent deliveries → the delivery → the replay icon
("Replay this webhook."). Forgejo sends the payload again under a new
delivery id, so the tracker answers 202 and processes it as new. Whatever
nobody replays, including everything sent while the tracker was down, is
filled in by the nightly reconcile.

### `git:reconcile`

It runs every night at 01:30 Nepal time from `tasks-scheduler`, and by hand
from `tasks-web`'s terminal:

```sh
php artisan git:reconcile                   # every connection
php artisan git:reconcile --connection=1    # one: the id at the end of its webhook URL
```

For each connection, in this order, each step on its own (one that fails is
logged and the rest still run; an unreachable forge ends that connection's
steps):

1. **The webhook.** A deleted one is created again, with a new secret. One
   that lacks an event the tracker needs is replaced: a new hook with a new
   secret, then the old one deleted ("replaced the webhook"), because
   Forgejo 15 can't add the workflow-run events to an existing hook (the
   first reconcile after 2026-10-31 did this to the hook made at the
   bootstrap). One that was switched off, pointed elsewhere (or still at an
   old `APP_URL`), or narrowed to some branches or another content type gets
   those settings back ("repaired the webhook"); one that looks right is
   left alone. The forge never shows a hook's secret and its edit can't
   write one, so a secret changed on the forge shows only as deliveries the
   tracker refuses (403, "Invalid signature."): each such delivery marks the
   connection for three days, and the next reconcile replaces the hook, with
   a new secret ("replaced the webhook"). Anyone can send a badly signed
   request, which costs only that replacement.
2. **Each linked repository**, looked up by its forge id. Deleted on the
   forge, or moved to another forge organization: unlinked, once every
   repository has been looked at (the project's activity says "Unlinked
   repository …", the log `Unlinked a repository the forge organization no
   longer has.`), unless the unlinks are held back
   ([below](#when-the-reconcile-holds-removals-back)). Renamed: its names
   and addresses follow. Its link back is set again. Commits, branches and pull requests
   since the last reconcile (less a day; 90 days the first time) are filled
   in. Commits no branch holds any more (force-pushed, or squash-merged and
   their branch deleted) are marked "Unreachable" on their tasks, never
   deleted, and lose the mark if they come back. Branches gone from the
   forge leave their tasks.
3. **Its access**, right after its own step (not for one found gone): the
   collaborators and the main branch's rule are written as the tracker
   wants them ([section 13](#13-repositories-access-and-code-activity)). It
   shows as `{full_name} access` in a failure.
4. **Owners.** Every active organizational admin of the office who has a forge
   account is put in the forge organization's Owners team; anyone else the
   tracker knows is taken out. `forge-admin`, every `{owner}-bot` and forge
   accounts the tracker doesn't know are never touched, here or below.
5. **Members.** Anyone the tracker no longer lets into the forge
   organization (deactivated, moved to another office, taken off a
   cross-organization project team) is removed from it.
6. Deliveries older than 14 days are deleted.

It prints one line per connection, such as `sdoc at
https://git.sdoc.gov.np: reconciled 3 repositories.`, adding `, unlinked 1`,
`, recreated the webhook`, `, replaced the webhook` or `, repaired the webhook` when it did those. A
failure reads `sdoc at …: failed (sdoc/app: The forge answered 500);
reconciled 2 repositories.`, and the command exits non-zero. Its warnings and
errors carry no token or secret. Production logs to standard error
(`LOG_CHANNEL=stderr`, `LOG_LEVEL=warning`): the nightly run's are in
Dokploy, `tasks-scheduler` → Logs, a run by hand's in `tasks-web` → Logs.
Locally they are in `storage/logs/laravel.log`. Every change to forge
membership (an owner added or removed, someone taken out of the
organization) and every unlinked repository is logged as a warning, so
production keeps a record of it.

### When the reconcile holds removals back

The Owners and Members steps refuse to remove more than 5 people, or more
than a quarter of the people the tracker knows in that forge organization if
that is more, in one run. Past that limit the step removes nobody (Owners
still gets its additions), logs `Held back forge removals: too many at once.`
with the forge organization, the step (`owners` or `members`) and the
counts, and the command exits non-zero with `members: Held back: it would
remove 6 of the 6 people it knows there`. That many departures in one night
is more often a broken setting (an empty `PM_SUPER_ADMINS`, the wrong
database) than real ones, and a removal cannot be undone from the tracker.

Unlinking repositories is held back the same way, all or none, because an
unlink drops the repository's history from the tracker:

- Before it unlinks anything, it asks the forge once whether the bot still
  owns the forge organization. A bot taken off its Owners team, or an
  organization deleted or renamed, makes every repository look gone. If it
  doesn't own it under the connection's name, nothing is unlinked, the log
  has `Held back repository unlinks: the bot no longer owns sdoc; check the
  Organizations page.`, and the command exits non-zero with `unlinks: Held
  back: the bot no longer owns sdoc; check the Organizations page`.
- It unlinks at most 3 repositories of a connection, or a quarter of those
  linked if that is more. Past that it unlinks none, logs `Held back
  repository unlinks: too many at once.` with the counts, and exits non-zero
  with `unlinks: Held back: it would unlink 4 of the 4 linked repositories`.

When it trips:

1. Find the warning in the logs (above) and note the step and the counts.
2. Check that the departures are real: on the forge, the organization's
   Members (or Teams → Owners); in the tracker, People, for each of them:
   deactivated, moved to another office, off every project team of the office,
   or no longer an organizational admin. If they are not, fix the tracker's setting
   first; nothing was removed. For unlinks: the bot is in the forge
   organization's Owners team, the organization still has the name the
   tracker's **Organizations** page shows (after a rename, enter the new name
   there), and the repositories really are deleted or moved.
3. Once the departures are real, either remove the people who really left by
   hand on the forge, as a super-admin (the organization → Members →
   **Remove**, or Teams → Owners → **Remove**), so the next run finds
   nothing to hold back, or lift the breakers for one run:

   ```sh
   php artisan git:reconcile --connection=1 --allow-removals
   ```

   It prints `Removal breakers lifted for this run (--allow-removals).`
   first, then removes and unlinks everything it found, whatever the count.
   It never lifts the check that the bot owns the organization. Suspend
   anyone who must also lose their tokens and SSH keys
   ([section 9](#9-offboarding)).

## 13. Repositories, access and code activity

Phase 2 adds three things to a connected forge: a project's manager makes a
repository from the tracker, the tracker decides who may pull and push it, and
a report counts what each person committed. Design and reasons:
[`docs/superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md`](superpowers/specs/2026-10-30-git-phase-2-access-and-reports-design.md).
It needs nothing new on the forge or in the environment: the bot's token
already carries `write:organization` and `write:repository`, `tasks-scheduler`
runs the new commands, and the access syncs are queued jobs that production's
sync queue runs in the web request right after its response (on the worker
once there is one, [Queue worker](#queue-worker)).
The HTTP surface is in [`docs/api.md`](api.md) (Code repositories, repository
access, Code activity).

### Making a repository

On a project's Code tab, **Link repository → New repository** (whoever may
link: the project's manager, its program's manager, an organizational admin of
its office, a super-admin). The name is the project's key in lowercase
(`sg`) or that followed by `-` and more (`sg-api`); lowercase letters and
digits with a single `.`, `_` or `-` between them; at most 100 characters; not
ending in `.git`, `.wiki`, `.rss` or `.atom`. The description is optional, at
most 255 characters.

The tracker, with the bot's token:

1. Creates it in the forge organization: private, with a README on `main`,
   its default branch. A name that exists answers "A repository named {name}
   already exists in {owner}. Link it instead."
2. Protects `main` with the tracker's rule ([section 14](#14-runners-and-test-results)):
   nobody pushes to it, and a pull request merges with one approval once
   every check on it passed.
3. Links it to the project like any other ([Phase 1's linking](#8-check)).

If the forge refuses the rule, the repository is still created and linked and
the message says "Created and linked sdoc/sg-api, but main couldn't be
protected yet; the tracker keeps trying." (the access sync puts the rule on
within ten minutes, and Maintenance lists the repository until it does). If
linking fails after the forge made it, the message says the repository exists
("Created sdoc/sg-api on the forge, but couldn't link it: …") and it can be
linked from the list. A linked repository gets the same rule on its default
branch from its first access sync.

Work starts on a branch: clone, branch, push the branch, open a pull request.
The first one adds the repository's workflow ([section 14](#14-runners-and-test-results)),
since until a check has run nothing can merge.

### Who has access

The forge's collaborators of every linked repository follow the tracker, never
the other way:

- **Maintainers**, `admin`: the project's manager and its program's manager,
  while active. Their access follows the role and is not edited.
- **Grants**, `read` or `write`: given on the project's Code tab (Access) by
  whoever may link repositories, to an active person of the project's
  organization, or on the project's team. A manager or a program manager gives
  it only to people on the team; an organizational admin also to anyone in the
  office. Never to a maintainer, someone deactivated, or a super-admin's own
  member. Someone wanted twice gets the higher permission.
- **Owners and site admins** of the forge reach every repository without being
  collaborators: the forge organization's Owners team is every active
  organizational admin of the office ([section 12](#12-webhook-and-nightly-reconcile)).

A grant counts only while the person is active and in the project's
organization or on its team; otherwise it stays stored and gives nothing.
Linking an existing repository reads its current collaborators first and makes
each one an organizational admin could grant a grant, whoever links it: an
active person of the project's organization or on its team, not a maintainer
(`admin` and `write` on the forge become `write`, `read` stays `read`), in one
transaction with the link, so linking never takes anyone's access away. If the
forge can't answer that, nothing is linked: "Couldn't read sdoc/sg-api's
collaborators on the forge, so it wasn't linked. Try again shortly."

Repositories linked before phase 2 never had their collaborators imported: the
first sync takes off every collaborator the tracker knows who has no grant.
Give those people their grants first.

People leaving change the forge at once:

- Leaving the project's team deletes that person's grants on its repositories
  in the same transaction as the removal, whoever made them.
- Moving to another office queues a sync of every repository they hold a grant
  on (their grants stop counting there).
- Deactivating or deleting someone removes them from every connected forge
  organization and takes them off the collaborators of every repository of
  those forge organizations where they are one, linked or not, with each
  connection's own bot token. A forge that is down never blocks the change:
  the message asks for a manual suspend ([section 9](#9-offboarding)), and the
  rest of that forge is not retried call by call. Only real removals are
  logged.
- A deactivated project manager loses the manager's powers in the tracker too,
  and the sync takes them off as a maintainer.

Unlinking a repository keeps its forge collaborators: people who had access
keep it on the forge until it's changed there or the repository is linked
again. Turning git off or re-pointing a connection leaves collaborators as
they are too ([section 9](#9-offboarding)).

### The access sync

A sync writes one repository's wanted collaborators to the forge. It is queued
(`SyncRepositoryAccess`, after the database transaction that caused it
commits; on production's sync queue it runs right after the web response) when
a grant is made, changed or removed, a repository is linked, someone joins or
leaves the project's team or becomes or stops being its manager, the project
changes program, a program gets a new manager, or a person moves office. A
forge failure is logged ("Couldn't sync a forge repository's access.") and the
job ends there; the next ten-minute run tries again. Two things run it
regardless: the `git:sync-access` command every ten minutes from
`tasks-scheduler`, which picks up people whose first forge sign-in came after
their grant, and the nightly reconcile's `access` step per repository.

In this order, so nobody gains access while a removal is stuck:

1. **Removals.** Every collaborator the tracker knows (a user with a stored
   forge username) who is no longer wanted is taken off. A failure here stops
   the run.
2. **Grants.** Each wanted login is set (PUT) at its permission, which also
   restates the permission of those already there. A 422 ("user does not
   exist") means the person has not signed in to the forge yet: they are
   *waiting*, and the next run gives them access. Any other refusal for one
   login (a disabled account answers 500, a blocked one 403) holds up nobody
   else; the run finishes the others and the rule, then fails with the first
   refusal. An unreachable forge fails the run at once.
3. **The main branch's rule** (the repository's protected branch: its
   default branch when it was linked, `main` for one the tracker made), every
   run: written over whatever is on the forge, and created again if someone
   deleted it ([section 14](#14-runners-and-test-results)). Making another
   branch the default on the forge doesn't move it. A refusal marks
   the repository unprotected (Maintenance lists it) and fails the run after
   the rest.

Never touched, in any step: `{owner}-bot`, `forge-admin`, and logins the
tracker has no user for. The forge's organization-level access is
[section 12](#12-webhook-and-nightly-reconcile)'s job.

```sh
php artisan git:sync-access                   # every linked repository
php artisan git:sync-access --connection=1    # one connection
```

One line per repository, such as `sdoc/sg-api: 4 with access, 1 waiting.`, or
`sdoc/sg-api: failed (forge answered 500).`. The command exits non-zero when
any failed; one failing never holds up the next, and an unreachable forge
fails that connection's other repositories without waiting for it again.

Production's log (`LOG_LEVEL=warning`) records each new collaborator ("Gave
someone access to a forge repository."), each removal ("Took someone off a
forge repository's collaborators.") and each failed sync ("Couldn't sync a
forge repository's access.", with the status and, for an unreachable forge, its
address and the cause with the token masked). Nobody's permission is logged
when nothing changed. The forge shows the same in a repository's Settings →
Collaborators; do not edit them there, the next sync puts them back (it takes
off only people the tracker knows).

What the forge does, as checked on a real instance and relied on above:

- With pushes off and the rule applied to admins, nobody pushes to the
  protected branch, admin collaborators and Owners included ("Not allowed to
  push to protected branch main"); everyone with write pushes other branches
  and opens pull requests.
- Adding a collaborator who already is one answers 204 and sets the
  permission; removing one who isn't answers 204.

### Counting commits: `git:commit-stats`

Every commit the tracker ingests starts without line counts. The command counts
them, oldest first, at most 300 per run (`--limit=300`), every five minutes
from `tasks-scheduler`:

```sh
php artisan git:commit-stats               # up to 300
php artisan git:commit-stats --limit=50
```

It prints `Counted 120 commits.`, adding ` 480 left for the next run.` when
the limit stopped it. For each commit it asks the forge for the commit (the
forge's own added and removed totals and its parents) and, for an ordinary
commit, its diff, which it classifies per file into code, comment, blank, docs
(`.md`, `.markdown`, `.rst`, `.txt`, `.adoc`) and generated lines (lock files,
`vendor/`, `node_modules/`, `dist/`, `build/`, minified files, source maps,
binaries; counted only as generated). Comment detection is a heuristic like
`cloc`, by file type. Outcomes, kept on the commit as `stats_status`:

| Status | Meaning |
| --- | --- |
| `ok` | Counted. |
| `merge` | More than one parent. Marked, not counted, and left out of the report. |
| `too_large` | A diff over 5 MB (5242880 bytes) is not read; only the forge's totals are kept. |
| `missing` | The forge no longer has the commit (404). |

Failures never stall the queue behind them. Every attempt counts toward
`--limit`. A forge answer of 0 (unreachable), 401, 403 or 429 stops that
connection for the rest of the run; any other 4xx, or a 5xx, skips only that
commit until the next run, and five 5xx in a row stop the connection. Other
connections go on. Each failure is logged at warning ("Counting a commit
failed.", the repository, commit id and status, never the token). A 403 here
is the same under-scoped token as in [Replacing the token](#7-bootstrap); the
fix is the same.

After a big backfill the backlog drains at 3,600 commits an hour; the report
shows how many of its commits are still uncounted. To drain faster run it by hand
in `tasks-web`'s terminal, or with a higher `--limit`.

The schedule runs one at a time: a killed run's lock lapses after 30 minutes
(`git:sync-access`'s after 15), and `php artisan schedule:clear-cache` clears a
stuck one at once.

The same command also fills `user_id` on commits ingested before their author
had a forge username (see below).

### Matching commits to people

A commit belongs to a person when the forge names its author by username, and
that username is the person's stored `git_username`. The forge does that from
the commit's author email: the primary email of a forge account (which is the
tracker email, set at sign-in) or a verified extra one. So a developer whose
git signs commits with their tracker email needs nothing else; one whose git
uses another address sets `git config user.email` to the tracker email in the
repository (or adds that address under SDOC Git → Settings → Emails and
verifies it). Commits whose
author the forge can't name show in the report as "Not matched to anyone", to
those who see whole repositories.

### Code activity

**Code activity** in the sidebar (shown to anyone git admits), at
`/workspace/reports/code`: per person, their commits, active days,
repositories, files, code, comment, blank and docs lines added and removed,
pull requests opened and merged, and tasks their commits are linked to, over a
range (the last 30 days unless given, at most 366), optionally for one project
or program, and with up to four people compared side by side. Only
non-merge, still-reachable commits count, by their commit date in the tracker's
timezone (a Nepal day).

People are listed by name and the columns can be sorted by the viewer; the page
never ranks anyone. It is for seeing where work is going, not for scoring it:
line counts reward verbosity and generated code, and `docs` and `generated`
lines are kept out of the code figures for that reason.

Who sees what: a super-admin, every connected organization; an organizational
admin, their organization's repositories; a program manager, those of their
programs' projects; a project manager, those of the projects they manage (a
deactivated one sees none); and everyone, their own commits wherever they are.
A project or program filter outside what the viewer sees is a 403.

### What the reconcile does now

[Section 12](#12-webhook-and-nightly-reconcile)'s reconcile also runs the
access sync for each repository, right after that repository's own step (not
for one it found gone). It is the nightly backstop for anything the queue
missed. A sync failure shows in its output as `{full_name} access: …` and
fails the run's exit code like any other step.

### When something is off

| Symptom | Likely cause |
| --- | --- |
| Someone is listed as waiting for days | They never signed in to the forge, so it has no user for their login. The sync gives them access on its next run after they do. |
| A person has the grant but cannot push `main` | Nobody pushes `main`: push a branch and open a pull request. |
| A pull request can't be merged though it is approved | A check failed or still runs, or the repository has no workflow yet ([section 14](#14-runners-and-test-results)). |
| A project's only developer can't merge | Their own approval doesn't count: someone else with write access (a teammate, the program's manager, an organizational admin) approves. |
| `git:sync-access` prints `failed (forge answered 403)` | The bot lost ownership of the forge organization or its token is under-scoped (`write:repository`). |
| Someone added by hand on the forge disappears | The sync took them off: add them as a grant in the tracker instead. |
| A removed collaborator is still on the forge | The removal failed; the log has "Couldn't sync a forge repository's access." with the status. The next run retries. |
| People still reach a repository that was unlinked | Unlinking keeps forge collaborators: change them on the forge, or link the repository again. |
| The report keeps showing uncounted commits | The forge was down or refused; look for "Counting a commit failed." and run `git:commit-stats` by hand. |
| A person's commits are "Not matched to anyone" | Their commit email isn't on their forge account (above). |

## 14. Runners and test results

Every pull request runs its repository's tests on the forge's own runners,
`main` takes nothing that hasn't passed, and the tracker shows the results.

### What runs

- **Actions** are on in the forge (`forgejo/Dockerfile`):
  `FORGEJO__actions__ENABLED=true` and `DEFAULT_ACTIONS_URL=github`, so a
  workflow's `uses: actions/checkout@v4` or `shivammathur/setup-php@v2`
  comes from GitHub, as it would there. The forge reads workflows from
  `.forgejo/workflows`, else `.gitea/workflows`, else `.github/workflows`.
- **Runners**: the Dokploy compose `git-runners` (project `git`, app name
  `compose-program-redundant-application-k85b3j`, which Dokploy chose and
  won't change: its containers are named after it), built from
  [`forgejo/runner/`](../forgejo/runner/compose.yml): four Forgejo runners
  `13.2.0` (`sdoc-runner-1` to `4`), one job each, registered to the forge
  organization `sdoc` (its repositories only, not personal ones). Every job
  runs in a container of the compose's dind service, never on the VM's
  Docker, and dind is held to **4 vCPUs, 10 GB of memory and 30 GB of disk**
  for all four jobs together: its `/var/lib/docker` is an ext4 filesystem in
  a 30 GB file on its volume (`forgejo/runner/dind/start.sh`), so a job that
  fills its disk fills only that file, never the disk the forge and the
  tracker's database live on. Jobs are not privileged, mount nothing and get no Docker socket;
  dind reaches the runners only through a unix socket in a volume, never a
  TCP port a job could reach. dind is privileged (Docker inside Docker needs
  it), so a job that broke out of its container would still have dind
  between it and the VM: keep the runners to SDOC's own repositories. Jobs
  can reach the internet, the VM's published ports (80, 443, 2222) and the
  office LAN, as any program on the VM could.
- **Images**: `runs-on: ubuntu-latest` (or `ubuntu-24.04`) runs
  `ghcr.io/catthehacker/ubuntu:act-24.04`, `ubuntu-22.04` its 22.04
  sibling: GitHub's runner image as act publishes it, with apt, sudo and
  the usual tools. The first job pulls it (about a gigabyte), later jobs
  reuse it. A job that runs over an hour is stopped.
- **Caches**: none. A cache server would write to the VM's disk outside
  dind's cap, and four runners' separate caches would rarely hit, so
  `actions/cache` and the setup actions' `cache:` find nothing and go on;
  each run installs its dependencies afresh. The `prune` service clears what
  finished jobs leave in dind once a day, keeping pulled images, and once a
  week every image no container uses.
- **The rule on main** (`App\Services\Git\MainBranch`), on every linked
  repository's protected branch (`protected_branch`: its default branch when
  it was linked, `main` for one the tracker made). A maintainer is an admin
  of the repository on the forge and can make another branch the default:
  the rule stays where it was, and Maintenance and the Code tab show the
  repository until the default is set back on the forge (or the repository
  is unlinked and linked again, which protects the new default and imports
  its collaborators as grants once more). No pushes, for anyone (`apply_to_admins`); a
  pull request merges with one approval (stale ones dismissed; a request for
  changes blocks) once **every** status check on its head commit passed
  (`status_check_contexts: ["*"]`; a check still running, failed, or no
  check at all keeps the merge button off). The rule names every field the
  forge has, file patterns and approval lists included, so nothing left
  loose on the forge lets a push through. Every access sync reads it and
  writes it only when it differs (each write makes the forge test every
  open pull request again). A repository with no commit yet gets it after
  its first push, since nobody could push a first commit to a protected
  branch. Forgejo lets its own site administrators merge past it; nobody
  else can.
- **What it guarantees**: the approval is the real gate. Anyone who can
  write to a repository can post a commit status by hand, and a pull request
  runs the workflow as the pull request changes it, so a reviewer looks at
  workflow changes as closely as at the code. The author's own approval
  doesn't count: a project whose manager is its only developer needs
  someone else with write access (a teammate given it, the program's
  manager, or an organizational admin) to approve.
- **Results in the tracker**: a finished run's webhook (`action_run_success`,
  `action_run_failure`) makes the tracker read that commit's statuses at
  once; `git:commit-checks` every two minutes asks about recent commits
  whose result it hasn't got yet, so a lost delivery only delays it. Each
  commit and pull request shows a tick, a cross or a dashed ring (still
  running) in the project's Code tab and the task's Code section, each
  opening its run on the forge; the code activity report has a Tests column
  (commits passed and failed per person) and Maintenance lists repositories
  whose main branch fails, isn't protected, or has no tests.

### A repository's workflow

Each repository needs one, on every pull request (no `paths` filter there:
a pull request no check runs on can't merge). For a Laravel repository,
`.forgejo/workflows/tests.yml` (or `.github/workflows/`, where the
`~/rayzen` standard already puts `tests.yml` and `lint.yml`):

```yaml
name: tests
on:
  push:
    branches: [main]
  pull_request:

jobs:
  pest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: ./vendor/bin/pest
```

A job may use `services:` (`postgres:17`, `redis:7-alpine`) as on GitHub.
It can't use `docker build` or mount volumes. The checks are named
`{workflow} / {job} ({event})`, `tests / pest (pull_request)`.

### Setting it up

Done on 2026-10-31, then proved end to end with a throwaway repository: the
bot (an Owner) was refused a direct commit to `main`, a job cloned from the
forge and ran on `ubuntu-latest`, a passing and a failing pull request got
their statuses, the webhook brought both workflow-run events to the
tracker, which read them as passed and failed, and merging the passing one
without an approval was refused ("Does not have enough approvals"). To
build it again, on the forge first:

1. Deploy `git-forgejo` after the `forgejo/Dockerfile` change that turns
   Actions on (it is stop-first, about a minute of downtime).
2. Make four secrets and register them, on the VM (`FJ` as in
   [section 4](#4-the-dokploy-project)). Besides Forgejo (which keeps a
   hash), a secret lives in the compose's Environment tab, the `.env` file
   Dokploy writes from it on the VM, and its runner container's environment:
   anyone who is root on the VM can read them, as they can every other
   secret there.

   ```sh
   for n in 1 2 3 4; do
     read -rs -p "secret for sdoc-runner-$n (openssl rand -hex 20): " SECRET; echo
     printf '%s' "$SECRET" | sudo docker exec -u git -i "$FJ" forgejo forgejo-cli actions register --scope sdoc --name "sdoc-runner-$n" --secret-stdin=-; echo
     unset SECRET
   done
   ```

   Each prints the runner's uuid. In Forgejo 15 `--secret-stdin` takes a
   value (any; the secret still comes from stdin): written bare before
   `--scope`, it swallows `--scope` and the runner is registered for the
   whole forge (sdoc → Settings → Actions → Runners wouldn't list it).
   Registering again with the same secret only updates the name and scope.
3. In Dokploy, project `git`: **Create Service → Compose** `git-runners`,
   type Docker Compose, Provider Git
   `https://github.com/rayzenai/project-management-app.git`, branch `main`,
   compose path `./forgejo/runner/compose.yml`, auto deploy off.
   Environment: [`forgejo/runner/.env.example`](../forgejo/runner/.env.example)
   with the four secrets (Create Env File on). No domain: nothing in it
   listens. Deploy.
4. Check: sdoc → Settings → Actions → Runners lists the four as idle, and

   ```sh
   sudo docker ps --format '{{.Names}} {{.Status}}' | grep k85b3j
   sudo docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' | grep k85b3j
   ```

   shows dind (healthy), four runners and prune.

The runners deploy, restart and fail on their own: a deploy of the forge or
the tracker never touches them, and while they are down pull requests wait
(their checks stay pending) and nothing merges.

### Operating it

- **Add a runner**: a fifth secret, registered as above, and a `runner-5`
  block in the compose (they share dind's 4 vCPUs). **More CPU**: raise
  `cpus` on dind.
- **Take one out**: Settings → Actions → Runners → the runner → Delete, then
  remove it from the compose. A secret that leaked: delete that runner on the
  forge, register a new secret under the same name, put it in the
  Environment tab, deploy.
- **Upgrade**: bump the image tag in `forgejo/runner/Dockerfile` (and dind's
  in the compose), deploy. Running jobs are cancelled; re-run them on the
  forge.
- **Disk**: `sudo docker exec $(sudo docker ps -qf name=k85b3j-dind) df -h /var/lib/docker`
  shows how much of the 30 GB is used, and `docker --host=unix:///var/run/dind/docker.sock system df`
  (same `docker exec`) what fills it. To drop every unused image at once, run
  the same with `image prune --all --force` (the next job pulls what it
  needs). The file never shrinks back on the VM's disk; it grows to the cap
  at most. **More disk**: stop the compose, then on the VM, with `V` the
  `dind-data` volume's directory (`sudo docker volume inspect` shows it):
  `sudo truncate -s 50G $V/docker.ext4 && sudo e2fsck -f $V/docker.ext4 && sudo resize2fs $V/docker.ext4`,
  set `DIND_DISK_SIZE` to match in the compose, deploy.

### When something is off

| Symptom | Likely cause |
| --- | --- |
| A pull request says checks are waiting and nothing runs | No runner is idle (Settings → Actions → Runners), or the repository has no workflow on `pull_request`. |
| A runner shows offline | Its container restarts: its log says why (`sdoc-runner: …` for a missing or wrong secret, or dind not healthy yet). |
| Jobs fail with `no space left on device` | dind's 30 GB is full: prune its images (Operating it, Disk), or give it more. |
| Jobs can't clone (`Could not resolve host` or a timeout) | dind's DNS: it must use the VM's resolvers, which answer `git.sdoc.gov.np` with `10.5.23.138` ([section 4](#tracker-and-forge-reach-each-other-on-the-vm)). |
| The tracker shows no tick or cross | The webhook's events lack the workflow-run ones (the reconcile puts them back), or the forge refused the tracker: look for "Reading a commit's checks failed." and run `git:commit-checks`. |
| Maintenance lists a repository as not protected | The bot can't write the rule (it lost ownership, or its token lacks `write:repository`); `git:sync-access` shows the forge's answer. |

```sh
php artisan git:commit-checks              # up to 200 commits
php artisan git:commit-checks --limit=20
```

## Try it locally

[`forgejo/local.sh`](../forgejo/local.sh) runs Forgejo from
Homebrew on your machine with the settings of `forgejo/Dockerfile` (SQLite instead of
Postgres, plain http on `127.0.0.1`, no mail, no two-factor) and connects it to your tracker,
exactly as the [bootstrap](#7-bootstrap) does in production. Homebrew has
Forgejo 17, not 15; see [Forgejo 15 and 17](#forgejo-15-and-17).

If you run `php artisan git:oidc-client` by hand, delete
`$FORGEJO_LOCAL_DIR/oidc-client` (default
`~/.local/share/sdoc-forgejo/oidc-client`) so the next `up` hands the forge
the new secret.

### Before you start

- The tracker is migrated (`php artisan migrate`) and running (`composer dev`,
  which also runs the queue listener that applies the forge's webhooks) at
  its `APP_URL`.
- `APP_URL` in `.env` is the address you actually browse the tracker at, and
  one the forge can reach. The sign-in source, the forge's link back and the
  webhook URL (`{APP_URL}/git/webhooks/{id}`) are all built from it, so an
  `APP_URL` other than the address you browse breaks all three. Set it to the
  browsed address and run `php artisan config:clear`; then `up` moves the sign-in
  source, and, if git is already on, `php artisan git:reconcile` moves the
  webhook and the link backs.
- That address is a name, not `127.0.0.1` or `localhost`: Forgejo refuses an
  external tracker on a loopback address ("External tracker URL not valid"),
  so with `http://127.0.0.1:8000` linking a repository works but says "The
  forge link back couldn't be set", and `TP-1` on the forge stays plain
  text. Any `.test` name resolves to `127.0.0.1` here, so
  `http://tracker.test:8000` reaches `php artisan serve` just as well.
- Webhooks reach the tracker only on this machine: `app.ini` has
  `ALLOWED_HOST_LIST = loopback`, which admits `127.0.0.1` and any name that
  resolves to it (`projects.test` does, through the `.test` resolver;
  check with `dscacheutil -q host -a name projects.test`). A tracker on
  another address gets no deliveries.
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
forgejo/local.sh up       # install, start, set up, and print the next steps
forgejo/local.sh status   # running or not, and whether the sign-in source exists
forgejo/local.sh down     # stop the forge (up starts it again)
forgejo/local.sh reset    # stop it and delete its data, after you type "reset"
```

The forge is at `http://127.0.0.1:3300`, git over SSH at `127.0.0.1:2222`. Its
data, including `app.ini` with its secret key and the bot token (mode 600),
lives in `~/.local/share/sdoc-forgejo`, outside the repository; set
`FORGEJO_LOCAL_DIR` to an absolute path to put it elsewhere (not `/` or your
home directory). The tracker is `APP_URL` from `.env`; set `TRACKER_URL` to
use another one. The log is `forgejo.log` in the data directory. `up` stops
with "port 3300 is in use by …" (or 2222) when another program holds a port.

The first `up` installs Forgejo, writes `app.ini`, starts the server, creates
`forge-admin` and `sdoc-bot`, makes `sdoc-bot` a bot user, creates the private
organization `sdoc` (owned by `sdoc-bot`; as in production, `forge-admin` is a
bot only while its one-off token lives, then an ordinary admin again, and the
token is deleted), writes `sdoc-bot`'s token (`read:user,write:organization,write:repository`,
as in [bootstrap step 2](#7-bootstrap)) to `sdoc-bot.token`, runs `php
artisan passport:keys` if the signing keys are missing, registers the forge
with `php artisan git:oidc-client`, and adds the sign-in source `sdoc-tasks`
with the flags of [bootstrap step 5](#7-bootstrap) plus one local-only flag,
`--skip-local-2fa`. Locally there is no two-factor: `app.ini` leaves out
`GLOBAL_TWO_FACTOR_REQUIREMENT`, and the flag means the forge never asks for
its own code even if you enrol one. Production has neither. It never prints a
token or secret.

Running `up` again reuses all of that, so the bot token and the sign-in
source stay as they are. Two things make it replace one:

- The tracker's Forgejo client is gone or was replaced (a fresh database), or
  `TRACKER_URL` changed. `up` then runs `php artisan git:oidc-client` again
  (a new client, or a new secret for the existing one), updates the source
  (which keeps `--skip-local-2fa` from when it was added, since `update-oauth`
  ignores that flag), and restarts the forge.
- The forge no longer has the bot token in `sdoc-bot.token`, or has it
  without `write:repository` (a token from before phase 1). `up` writes a new
  one; turn git on again with it (step 1 below), which keeps the webhook.

`php artisan` runs with the tracker's `.env`, so it writes the client into the
tracker's database. If `up` says the tracker "calls itself" another address,
`TRACKER_URL` and the tracker's `APP_URL` disagree: the forge must use the
address the tracker puts in its discovery document.

`reset` asks first and does nothing unless you type `reset`. It deletes only a
data directory whose `app.ini` this script wrote.

### An https tracker

The forge, a Go program, must trust the tracker's certificate. `https://projects.test`
(served through AssistantHub with its own development CA) passed: a Go
program using the system roots got the proxy's answer, not a certificate
error. If yours fails, the sign-in stops after the tracker with an `x509`
error in `forgejo.log`. Then use plain http on this machine: set
`APP_URL=http://tracker.test:8000` in `.env` (a name, for the link back;
it resolves to `127.0.0.1`), run `php artisan config:clear`, serve the
tracker with `php artisan serve` (port 8000), and run
`TRACKER_URL=http://tracker.test:8000 forgejo/local.sh up`, which
moves the sign-in source to that address. Then run `php artisan
git:reconcile`, which moves the webhook and every linked repository's link
back to the new address too ("repaired the webhook"); turning git on again
keeps the webhook as it is.

### Checks

`up` ends by printing these steps (with your data directory):

```text
1. Turn git on for SDOC. Copy the bot token with

     pbcopy < ~/.local/share/sdoc-forgejo/sdoc-bot.token

   then, as a super-admin in the tracker: Organizations, Git on SDOC,
   forge address http://127.0.0.1:3300, forge organization sdoc, paste the bot
   token, Turn on git. It says "Git is on.", and the forge has the
   tracker's webhook (sdoc, Settings, Webhooks).

2. Sign in. Open http://127.0.0.1:3300 in a private window, a new one for each
   person, and choose "Sign in with sdoc-tasks":
   - an SDOC member lands on the forge signed in, with the username the
     tracker stored (git_username);
   - someone outside SDOC and on no SDOC project team gets the tracker's 403
     page, "You don't have access to git. Ask your organizational admin.", and no
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

5. Code, with an SDOC organizational admin who has signed in to the forge (step 2).
   Run php artisan git:reconcile, which puts them in sdoc's Owners team. As
   them, on the forge: a new repository owned by sdoc, and a pushed commit
   naming a task code, such as "SG-1 first change". In the tracker, the
   project's Code tab, Link repository: the commit shows on SG-1. Pushes,
   a branch sg-1-thing and its pull request then show there within
   seconds, and SG-1 on the forge links back to the task if the tracker's
   address is a name, not 127.0.0.1 or localhost (docs/forgejo.md,
   section 8 and Try it locally).
```

### Clean up

1. `forgejo/local.sh reset`, and type `reset`.
2. Turn git off for SDOC in the tracker (Organizations, Git on SDOC, Turn off
   git): the token it holds died with the forge.
3. Keep `storage/oauth-*.key` (gitignored): the tracker signs sign-ins with
   them. The tracker's Forgejo client stays too; the next `up` gives it a new
   secret.
4. `brew uninstall forgejo` if you no longer want it.

## Forgejo 15 and 17

The local checks ran Forgejo 17.0.1 (Homebrew has no 15) with the same
settings as `forgejo/Dockerfile` except SQLite, plain http on `127.0.0.1` and SSH on
a local port: the sign-in against this tracker, and separately the bootstrap
(password basic auth refused, tokens accepted in a header and as the
basic-auth password, regular users refused an organization, the
create-hand-over-leave sequence, the token deleted in the database),
suspension (API, HTTPS and SSH all refused), and the two-factor requirement
(the first person to sign in not made admin, enrolment, the passcode after
the tracker, tokens and git over HTTP refused before enrolling and working
after, the bot token, `forge-admin`'s bot window in step 3 ending at type 0
even when a call fails, `reset-mfa` and **Reset 2FA**). What carries over to
15.0.11, from Forgejo's source and cheat sheets:

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
  other key the Dockerfile and `start.sh` set is in both cheat sheets.
- Two-factor, the same code at the `v15.0.11` and `v17.0.1` tags:
  `GLOBAL_TWO_FACTOR_REQUIREMENT` takes `none`, `all` or `admin`, anything
  else meaning `none`; `MustHaveTwoFactor` exempts Actions users and every
  user that isn't an individual, so bot users; the API's 403 is the same
  check (17 only adds token methods ahead of it); so are the web redirect to
  Settings → Security, the SSH refusal (`routers/private/serv.go`), the
  source's `SkipLocalTwoFA`, `forgejo admin user reset-mfa` and the admin
  UI's Reset 2FA (both delete the TOTP and security keys).
- Why `forge-admin` is not a bot, in both: `handleUserCreated` makes the
  only individual user a site admin, and `IsLastAdminUser` counts only
  individual admins. With `forge-admin` a bot, the first SDOC member to sign
  in on 17.0.1 became a site admin (the `forge-admins` demotion failed with
  "can not delete the last admin user", after the session was already
  made).
- `forgejo admin auth add-oauth` has the same flags in both (17 adds
  `--dyn-group-maps`, unused here). `update-oauth` ignores
  `--skip-local-2fa` in both. Neither has a CLI command to suspend a user or
  delete a token.
