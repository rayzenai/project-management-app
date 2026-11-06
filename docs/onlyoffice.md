# ONLYOFFICE Docs at docs.sdoc.gov.np

The editor behind a project's **Documents** tab: Word, Excel and PowerPoint
files edited together in the browser. Design:
`docs/superpowers/specs/2026-11-05-documents-design.md`. API:
`docs/api.md` (Documents).

ONLYOFFICE Docs Community Edition (AGPL-3.0) is only the editor. It has no
users and keeps no documents:

- the tracker decides who may open a document
  (`WorkspaceAccess::canUseProjectDocuments`) and signs every editor
  session with the shared JWT secret;
- the editor fetches the file from the tracker and, when everyone has closed
  it, reports the save; the tracker fetches the saved file back and keeps it
  as the next version;
- every version is its own object in RustFS (bucket `tasks-documents`),
  never on the VM's root disk, never overwritten, never deleted by the app.

So there is nothing on the editor to back up; RustFS holds the documents
(its off-VM copy is the same open gap as the rest of the VM).

## What runs where

| Piece | Where |
|---|---|
| Image | `onlyoffice/` in this repo: `Dockerfile` (pinned `onlyoffice/documentserver`), `start.sh`, `fonts/`, `.env.example` |
| Dokploy | project `docs` (jZlO1axG5edftq5gDD4kF), environment production (ccUpR0a6Ddi2tZbcFoA_d), application `docs-onlyoffice` (D84pytTNeartz06U2Ze6R, Swarm service `docs-onlyoffice-vaq4tc`) |
| Source | GitHub `rayzenai/project-management-app` `main`, Dockerfile `onlyoffice/Dockerfile`, context `onlyoffice`; no auto-deploy |
| Limits | 4 vCPUs, 6 GB (2 GB reserved), stop-first updates (one editor at a time: two would split a document's co-editing session) |
| Domain | docs.sdoc.gov.np, port 80, HTTPS by Let's Encrypt through Traefik |
| Working cache | bind mount `/srv/rustfs/onlyoffice-cache` -> `/var/lib/onlyoffice`, on the `rustfs` logical volume (the root disk holds no document) |
| Files | RustFS bucket `tasks-documents`, user `tasks-documents` (`ops/rustfs/setup-documents.sh`) |

DNS: public A `docs.sdoc.gov.np` -> 103.175.192.178, and NITC's internal
record -> 10.5.23.138, as for git. The tracker reaches the editor (to fetch a
saved file) and the editor reaches the tracker (file and save calls) through
Traefik on the VM's own address, with real certificates.

## Environment

The editor (`docs-onlyoffice`), from `onlyoffice/.env.example`:

| Variable | |
|---|---|
| `JWT_SECRET` | 64 hex characters; the same value as the tracker's `ONLYOFFICE_JWT_SECRET` |
| `SECURE_LINK_SECRET` | signs the editor's links to its own cache; fixed so links survive a restart |
| `DOCS_TRACKER_URL` | `https://tasks.sdoc.gov.np/`: the only origin the editor fetches from directly |

The tracker (`tasks-web`):

| Variable | |
|---|---|
| `ONLYOFFICE_URL` | `https://docs.sdoc.gov.np` |
| `ONLYOFFICE_JWT_SECRET` | the editor's `JWT_SECRET` |
| `DOCUMENTS_DISK` | `rustfs` |
| `RUSTFS_ENDPOINT` | `http://rustfs:9000` (tasks-web joins the `storage` network) |
| `RUSTFS_BUCKET` | `tasks-documents` |
| `RUSTFS_KEY` / `RUSTFS_SECRET` | `tasks-documents` and its secret |

Documents are on only while `ONLYOFFICE_URL` and a JWT secret of at least
32 characters are set (`config/documents.php`). Commented copies of the
secrets are in the gitignored `.env.sdoc`; never print them.

## Hardening

- **JWT both ways.** The tracker signs each editor config (`token`); the
  editor signs its file and save calls (`Authorization: Bearer`, with
  `operation` `download` or `callback`), and the tracker refuses anything
  else (`App\Support\Documents\EditorToken`). The routes are also signed
  URLs.
- **Only the tracker, directly.** `start.sh` writes
  `local-production-linux.json`: `externalRequest.directIfIn.allowList` is
  the tracker's origin, so only its URLs skip the private-address filter;
  every other URL (an image someone inserts by address, say) goes through it,
  and `ALLOW_PRIVATE_IP_ADDRESS` stays false. The tracker in turn fetches a
  saved file only from the editor's own host.
- **Off:** the demo app (`EXAMPLE_ENABLED`, it edits files without signing
  in; `/example/` answers 502), bundled plugins, the public Document Builder
  and conversion APIs (404), WOPI. `WELCOME_PAGE_ENABLED` is false, yet 10.0
  still answers `/` and `/welcome/` with a bare status page (the product's
  name and whether it runs); nothing about this installation. In each editor config: AI tools, plugins, macros
  (several 10.0 fixes were macro sandbox escapes) and chat.
- `start.sh` refuses to start without the three variables (the image would
  otherwise invent a JWT secret nobody knows).
- The ONLYOFFICE logo stays: the Community Edition's licence requires it.

## Check

```bash
curl -s https://docs.sdoc.gov.np/healthcheck          # true
curl -sI https://docs.sdoc.gov.np/web-apps/apps/api/documents/api.js | head -1   # 200
# On the VM: limits, health, our settings, the fonts
C=$(sudo docker ps -qf name=docs-onlyoffice)
sudo docker inspect -f '{{.State.Health.Status}}' $C
sudo docker exec $C cat /etc/onlyoffice/documentserver/local-production-linux.json
sudo docker exec $C sh -c 'grep -oE "Mukta|Noto Sans Devanagari" /var/www/onlyoffice/documentserver/server/FileConverter/bin/AllFonts.js | sort | uniq -c'
```

Then in the tracker: a project's Documents tab, New, type a line, close the
tab; within a minute the version list shows version 2, saved by you.

## Storage

`ops/rustfs/setup-documents.sh` (run on the VM as root, the user's secret on
stdin; running it again with a new secret rotates the key) makes the bucket
(versioning on, nothing expires), the policy (read and write objects in
`tasks-documents` only: no delete, no old versions, no bucket settings) and
the user, then proves as that user that it can write and read but not
delete, and cannot read `sdoc-private`.

## Fonts

`onlyoffice/fonts/` holds openly licensed Devanagari fonts (Noto Sans and
Noto Serif Devanagari, Mukta; SIL OFL, licences beside them). The entrypoint
adds them to the editor's font list on every start. Kalimati and Preeti are
left out: their licences are unclear and this repository is public. A
document typed in Kalimati shows in a stand-in Devanagari font and keeps its
font name, so it looks right again wherever Kalimati is installed.

## Upgrades

Bump the tag in `onlyoffice/Dockerfile` to an exact release, read that
release's changelog (`ONLYOFFICE/DocumentServer` CHANGELOG.md: "Behavior
change" lines, the token and `externalRequest` settings), push, and deploy
`docs-onlyoffice`. Stop-first means open editors disconnect for the restart.
On a clean shutdown the editor puts each open document's unsaved changes
aside as a "forgotten" file in its cache (the rustfs volume), so the
service has a two-minute stop grace period to do it; this is still a
disruption, so deploy outside office hours.

## When something is off

- **Documents tab missing**: documents are off (`ONLYOFFICE_URL` or the JWT
  secret unset on tasks-web), or the person isn't on the project's team.
- **"The editor isn't reachable right now"**: the browser can't load
  `api.js` from docs.sdoc.gov.np (the app down, DNS, the certificate).
- **Opens but never saves** (no version 2 after closing): the editor can't
  reach the tracker or the tracker refuses it. Look in tasks-web's log for
  `Documents:` warnings and in the editor's
  `/var/log/onlyoffice/documentserver/docservice/out.log` for the callback.
  A JWT mismatch shows as 403s on `/documents/{id}/callback`.
- **The certificate**: Let's Encrypt validates DNS from several regions; a
  slow answer from NITC's nameservers fails it ("During secondary
  validation: DNS problem: query timed out"). Traefik does not retry on its
  own: delete and add the domain again in Dokploy.
