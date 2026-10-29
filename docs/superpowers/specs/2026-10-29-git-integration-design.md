# Git integration: design

Date: 2026-10-29. Status: draft for approval.
Research behind it: `reports/Self hosted git forge for SDOC.md` (local, not committed).

## Intent

SDOC builds government software (SMS Gateway, eGP v2, GIWMS v2, SuTRA, GIOMS,
Hello Sarkar, Nagarik App, Open Source OS). Its code should live on SDOC's own
infrastructure, and the tracker should know which code belongs to which project
and which task, so that managers see real progress and nobody keeps two systems
in step by hand.

Git is switched on per organization (SDOC first). A git-enabled organization's
projects can each link one or more repositories.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Forge | **Forgejo**, 15.0 LTS (supported to 15 Jul 2027), then 19.0 LTS (Apr 2027) | GPLv3+, non-profit steward, no paid tier; org-wide signed webhooks, OIDC sign-in and pull mirroring are free; ~300 MB idle; chosen by the Dutch government in 2026. Skip 17.0.0 (the project advised against it). |
| Not GitLab CE | | Webhooks covering a whole group, OIDC group rules and pull mirroring are paid; monthly patching with upgrade stops; 8 GB idle; unauthenticated CVSS 10 RCE in Oct 2026; its tracker link drops the `SG` prefix. |
| Not Gitea | | Company-held trademark, fixes only on the latest major, three majors in six months, and moving from Gitea 1.23+ to Forgejo later needs manual database surgery. |
| Where | `git.sdoc.gov.np` on the SDOC VM, own Dokploy Compose project with its own Postgres | Next to the tracker. The public gateway (103.175.192.178) forwards ports 80 and 443 to this VM's Traefik, so the tracker is on the internet; whether the forge is too is an open question below. |
| Git over SSH | Forgejo's built-in SSH server on port **2222**; HTTPS always works | Host sshd keeps port 22. The gateway forwards only 80 and 443, so SSH is reachable inside the SDOC network and over VPN only. |
| Who is the source of truth | **The tracker** for people, projects, teams and keys. The forge for code. | Forgejo can't confine a token to one organization and never retries webhooks, so the tracker needs its own reconciler anyway. |
| Sign-in | The tracker is the forge's **OIDC provider** ("Sign in with SDOC Tasks"); forge passwords off | One account; deactivating someone in the tracker shuts the forge door. Must exist before anyone creates a local forge account. |
| Status automation | Links only by default; at most `not_started`/`unclear` to `in_progress`, opt-in per project; **a merge never marks a task done** | There is no review status, and `done` drives progress and the overdue rule. |

## Goals

- A super-admin switches git on for an organization; nothing changes for other organizations.
- A project's manager or office admin links repositories to the project (later: creates them).
- Each task shows its branches, pull requests and commits, found from its key (`SG-12`) in branch names, commit messages and PR titles.
- People sign in to the forge with their tracker account; repo access follows the project team (phase 2).
- `SG-12` in the forge is a link back to the task.

## Non-goals

- Code browsing, review or CI inside the tracker. The forge does that; the tracker links to it.
- Per-person commit counts, charts or leaderboards.
- Supporting GitHub, GitLab or more than one forge per organization.
- Deleting repositories from the tracker. Archive at most.

## Anti-goals (a failure even if it works)

- A merge or a stray mention silently changes a task's status.
- Commit messages or branch names visible to people outside the project team.
- Someone deactivated in the tracker can still push code.
- A storm of notifications or bot comments on pull requests.

## Prerequisite in the tracker: keys that never change

Today `SG-12` is display only. `Project::code()` builds up to four title
initials when read (fallback `P{id}`), and the number is the task's
`item_number` in `metadata`, which is editable and not unique. Renaming a
project changes every key, SuTRA and a "Sarkari Portal" would both be `S`, and
`P12` fails Forgejo's letters-only pattern. Commits are permanent, so:

- **`projects.key`**: stored, 1 to 10 uppercase letters A-Z, unique within the
  organization. Backfilled from the current initials, with collisions resolved
  by the migration (append letters). Office admins can change it; the old key
  goes into `project_key_aliases` and keeps resolving. `Project::code()` returns
  the key.
- **`tasks.number`**: a real column, unique per project, assigned on create and
  never edited. Backfilled from `item_number` where it is unique in its
  project, otherwise the next free number. `item_number` stays a plan-tracker
  attribute and stops being part of the code. Front end: `BoardView`,
  `schedule.ts`, `TaskCode.svelte` and every other place that builds a code
  switch to `key-number`.

Proposed keys for SDOC (office admin confirms before git goes live):

| Project | Key |
|---|---|
| SMS Gateway | SG |
| eGP v2 | EGP |
| GIWMS v2 | GIWMS |
| SuTRA | SUTRA |
| GIOMS | GIOMS |
| Hello Sarkar | HS |
| Nagarik App | NAGARIK |
| Open Source OS with Office Packages | OSOP |

## The model

| Tracker | Forgejo |
|---|---|
| Organization with git on | One forge organization, named after the organization slug (`sdoc`), owned by a bot user (`sdoc-bot`) that owns nothing else |
| Project | Phase 2: two teams, `<key>-maintainers` (admin; the manager) and `<key>` (write; the team), `includes_all_repositories: false` |
| Linked repository | A repo in that forge organization, linked to exactly one project, stored by Forgejo's numeric id so renames don't break links |
| Office admin | An owner of their organization's forge organization (kept in step from phase 1), so they can create repos and grant access by hand before phase 2 |
| Super-admin | Forgejo site admin, through an OIDC claim (Forgejo's admin-group mapping) |
| Member | A forge user created on first OIDC sign-in; mapped by the OIDC subject (the tracker's user id), never by commit email |

A repo belongs to one project. A project can have many repos.

**Who may enter the forge** (my call, following the tracker's model): an active
member of a git-enabled organization, anyone on the team of a project in a
git-enabled organization (so a cross-organization team's outsiders get in, as
the tracker already lets them see the project), and super-admins. Code
visibility in the tracker, forge team membership and the sign-in claim all use
this one rule.

Forge owners can edit or delete the tracker's webhook and link-back setting, so
the nightly reconcile puts them back and logs that it did.

## Phases

Each phase ships on its own and is useful on its own. Risk comes last.

### Phase 0: foundations

1. **Keys and numbers** (section above), shipped to everyone first.
2. **Queue worker.** Production runs `QUEUE_CONNECTION=sync`. Add a
   `tasks-queue` Dokploy service (`queue:work` on Redis, `AUTORUN_ENABLED=false`,
   healthcheck off), as the old PMOPM instance had. Webhooks and forge calls
   need it.
3. **Tracker as OIDC provider.** Laravel Passport 13 plus
   `jeremy379/laravel-openid-connect` (supports Laravel 13; switch its userinfo
   endpoint on). One first-party confidential client for Forgejo, consent
   skipped. Claims: `sub` (user id), `email`, `email_verified`, `name`,
   `preferred_username` (a stable username stored on the user once, unique,
   never changed when email changes), `git_orgs` (the git-enabled
   organizations this person may enter, by the rule in The model) and
   `groups` (`forge-admins` for super-admins). Forgejo requires `git_orgs` to
   be non-empty, so people with no git-enabled organization or project team
   can't get in.
4. **Forgejo on the VM.** Dokploy Compose project `git`: Forgejo 15.0.x and its
   own Postgres 17 (Postgres 18 support is unconfirmed), named volumes only
   (Dokploy backs up named volumes and may clean absolute host paths),
   `git.sdoc.gov.np` through Traefik, SSH on 2222. Settings:
   `REQUIRE_SIGNIN_VIEW=true`, private repos by default,
   `ALLOW_ONLY_EXTERNAL_REGISTRATION=true`, `ENABLE_INTERNAL_SIGNIN=false`,
   `ACCOUNT_LINKING=login`, OIDC source pointing at the tracker,
   `ADD_MEMBERS_BY_INVITATIONS=false`, webhook `ALLOWED_HOST_LIST` set to the
   tracker host, mail through the SDOC SMTP account. A break-glass site-admin
   account is used through the `forgejo admin` CLI only. Dokploy's own Forgejo
   template is not used (stale version, hard-coded database password, no SSH).
   If the forge is to stay off the internet: a public DNS record at NITC (so
   Let's Encrypt HTTP-01 still works through the gateway) plus a Traefik IP
   allowlist admitting only the SDOC network and VPN ranges. Public traffic
   arrives either with its own address or the gateway's, neither of which is
   on the list, and VPN users already resolve the name to 10.5.23.138.
5. **Backups** from day one: nightly `pg_dump` and a copy of the data volume,
   kept off the VM. One restore drill before real code is pushed.
6. **Switch git on for SDOC.** By hand in Forgejo: create the `sdoc`
   organization and the `sdoc-bot` user as its owner, make a token. In the
   tracker, a super-admin opens the organization, chooses Enable git and pastes
   the forge URL, organization name and token. The tracker checks the token
   owns that organization, creates the organization webhook with its own
   secret, and stores both encrypted. The tracker never holds a site-admin
   token.

Check: an SDOC person signs in to the forge with their tracker account and
pushes to a repo; a person with no SDOC membership or SDOC project team is
refused; a deactivated person is refused; a super-admin arrives as site admin.

### Phase 1: projects link repos, tasks show code

- **Code tab** on the project (only in git-enabled organizations, only for
  people who can view code): each repo with HTTPS and SSH clone addresses,
  default branch, last push and open pull requests. Managers and office admins
  get Link repository (search the forge organization's repos not linked
  anywhere) and Unlink (keeps the repo, stops tracking).
- **Webhook intake**: one route per connection, outside session auth and CSRF,
  size-capped. Verify `X-Forgejo-Signature` (HMAC-SHA256 of the raw body,
  `hash_equals`), store the delivery by its UUID (duplicates ignored), answer
  2xx at once, process on the queue. Events used: `push`, `create`, `delete`,
  `pull_request`, `repository`. Events for repos not linked to a project are
  dropped.
- **Linking**: keys match case-insensitively in branch names and in uppercase
  only in commit messages and PR titles and bodies; skip text inside code spans
  and URLs; resolve within the repo's organization (keys and aliases); ignore
  unknown keys. Several keys in one message link to several tasks.
- **Task panel**: a Code section in the task Peek listing pull requests (open,
  merged, closed), branches and the latest commits, each linking to the forge,
  plus a Copy branch name button (`sg-12-short-title`). The board card shows a
  small pull-request mark when a task has one.
- **Backfill** when a repo is linked: open and recent pull requests and the
  last 90 days of the default branch (capped), linked without any status
  change.
- **Nightly reconcile**: Forgejo never retries webhooks, so a scheduled
  `git:reconcile` reads recent commits and pull requests of every linked repo
  and fills gaps. Commits that vanished after a force-push are marked
  unreachable, not deleted.
- **Forge link back**: on link, set the repo's external tracker to
  `https://tasks.sdoc.gov.np/workspace/go/{user}/{index}`, style
  `alphanumeric`, so `SG-12` in Forgejo opens the task. `{user}` is the forge
  organization, which maps to the tracker organization. The route resolves the
  key and redirects (404 if the viewer can't see it).
- **Deactivation**: when a member is deactivated, remove them from the forge
  organization at once (the bot can, as owner), so they lose private repos even
  with a live forge session or SSH key.
- **Activity**: linking and unlinking a repo, and a pull request being opened
  or merged for a task, are written to `ProjectActivity` by observers. Single
  commits are not.

- **Forge owners**: office admins are kept in step as owners of their forge
  organization (added and removed by the bot).

Repos are created, and access to them granted, by hand in Forgejo by office
admins in this phase.

### Phase 2: the tracker manages repos and access

- **New repository** from the Code tab: name prefixed with the key
  (`sg-api`), optionally from a template repo in the organization; linked on
  creation and marked managed. Archive from the tracker; never delete.
- **Teams**: each project gets its two forge teams; the tracker pushes changes
  the moment the project team changes (the `TeamMembershipObserver` already
  fires on every add, removal and manager change) and an hourly reconciler
  repairs drift on managed repos, logging what it changed. Repos linked as
  unmanaged are display only.
- People who have never signed in to the forge have no forge user yet; their
  team adds wait until their first OIDC sign-in.

### Phase 3: optional, each on its own say-so

- Per-project switch (off by default): pushing a keyed branch or opening a PR,
  by someone on the project team, moves `not_started`/`unclear` to
  `in_progress`. Never moves backwards, never touches `done` or `failed`.
- On merge into the default branch, notify the task owner and manager ("PR #4
  merged; mark SG-12 done?"). Never sets done.
- CI: one Forgejo runner on the VM with no Docker socket, unprivileged, 1 to 2
  jobs at a time, capped memory and CPU per job, short artifact retention. Move
  to its own VM when image builds become routine or the disk gets tight.
- Moving existing repos in (mirror or migrate), depending on where SDOC's code
  lives today.

## Tracker changes in detail

**Tables** (jsonb for every JSON column):

- `git_connections`: organization (unique), base URL, forge organization name,
  bot token and webhook secret (encrypted casts), webhook id, enabled by/at,
  last reconciled at.
- `repositories`: connection, project, forge id (unique per connection), full
  name, URLs, default branch, `is_managed`, archived at.
- `git_branches`, `git_commits` (unique per repo and SHA; `reachable` flag),
  `git_pull_requests` (unique per repo and number; state, draft, base and head
  branch, merged at; a forge `updated_at` so an older event never overwrites a
  newer one).
- `task_git_links`: task, morph to branch / commit / pull request, where the
  key was found.
- `git_deliveries`: delivery UUID (unique), event, payload, processed / failed
  at, error; pruned after 30 days.
- `project_key_aliases`, and the new `projects.key`, `tasks.number`,
  `users.git_username`.

New polymorphic subjects go in the morph map.

**Access** (`WorkspaceAccess`, with happy, failure and privilege-escalation
tests for each):

- `canManageGit(user, organization)`: super-admin only (enable, disable, change
  the token).
- `canViewProjectCode(user, project)`: the project's team, its organization's
  office admins, super-admins. Not other viewers of a public project.
- `canManageProjectRepositories(user, project)`: the project's manager, office
  admins, super-admins, and only when the organization has git on.
- A repo can be linked only if it belongs to the connection's forge
  organization and isn't linked elsewhere.

**Services and surfaces**: `EnableGitService`, `LinkRepositoryService`,
`UnlinkRepositoryService`, (phase 2) `CreateRepositoryService` and
`SyncProjectForgeTeamsService`, each with web and API controller twins and
FormRequests, following `Api\SubtaskController`. A `ForgejoClient` built on
Laravel's HTTP client (no third-party SDK; the Gitea and Forgejo APIs have
drifted, so use only endpoints Forgejo documents). `ShareWorkspaceData` and
`Api\AuthController::userPayload` gain `gitEnabled`.

**Guide and docs**: the Guide gains a Code section, shown only in git-enabled
organizations (link a repo, put the key in your branch name, what the marks
mean). `CLAUDE.md`, `docs/api.md` and the README are updated in the same change.

## Security notes

- Tokens and secrets are encrypted at rest, never logged, never sent to the
  browser.
- The webhook route trusts nothing until the signature checks; payloads only
  ever create links to tasks in the repo's own organization.
- Commit and PR text is shown as text, never as HTML.
- The OIDC provider issues tokens only to the one registered client, with an
  exact redirect URI, and only to active members of git-enabled organizations.
- Forgejo's SSH port is reachable only inside the SDOC network and VPN, and
  its web pages too if open question 2 goes that way. Upgrades follow LTS
  point releases promptly; security advisories are watched.

## Left to the builder

Exact table and column names, job and command names, how the Code tab and
Peek section look within the existing theme rules, the backfill caps, the
parser's edge cases beyond those listed, how the queue worker is configured,
and how keys are resolved when the migration finds collisions (as long as the
office admin can review them before git goes live).

## Open questions for the owner

1. Where does each project's code live today (GitHub, a vendor's GitLab,
   laptops, nowhere yet), and will developers push to the forge directly? If
   they keep working elsewhere and the forge only mirrors, pull requests never
   reach it and phase 1 shows commits only.
2. Should the forge's web pages be reachable from the internet like the
   tracker, or only inside the SDOC network and VPN? Recommendation: network
   and VPN only (see phase 0, item 4). The cost is that developers outside the
   office need the VPN to use git at all.
3. Where can off-VM backups of the code go (another SDOC server, government
   data centre storage)? Phase 0 can't finish its restore drill without one.

Answers go here once settled.
