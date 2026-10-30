# Git phase 2: repositories, access and code reports

Status: approved by the owner in chat on 2026-10-30 ("ultrathink and build
this then"), built the same day while they were away. Calls marked **(my
call)** were made without them and are listed for review. Builds on
[`2026-10-29-git-integration-design.md`](2026-10-29-git-integration-design.md)
(phases 0 and 1) and replaces that design's phase 2 team model.

## Why

SDOC starts using the forge at full scale on 2026-10-31. Its people: an
Executive Director, a Joint Secretary and a Chief Technology Expert (CTE) at
the top; PMs and engineering heads under the CTE; senior, mid and junior
developers under the engineering heads. Today only organizational admins
(forge Owners) and super-admins (site admins) can reach a repository; nobody
else is even in the forge organization, so developers cannot push. The owner
also wants to see, in the tracker, who committed what and how much of it was
code.

## Goals

1. **Create a repository** from a project's Code tab, linked on creation.
2. **Individual access per repository**, granted in the tracker, never on
   the forge: only people given access to a repository can pull or push it.
3. **`main` protected** on repositories the tracker creates: changes reach it
   through pull requests, except for the repository's maintainers.
4. **Code reports**: per person, commits, lines changed and how much of it is
   code rather than comments or blank lines, activity over time, and a
   side-by-side comparison of developers.

## Non-goals

- Forge teams per project (the old phase 2 idea). Access is individual.
- Deleting or archiving repositories from the tracker.
- Changing who sees code inside the tracker: `canViewProjectCode` and its
  query twin stay as they are. Forge access and tracker visibility are
  separate questions.
- Protecting `main` on repositories that were linked rather than created.
- Productivity scores. The report shows numbers side by side; it never ranks
  people by a single figure.

## Roles (the owner's mapping)

| At SDOC | Tracker role | On the forge |
| --- | --- | --- |
| Executive Director, CTE | Organizational admin | Owner of the organization's forge organization: every repository (already in place) |
| Engineering head | Program manager of a program holding the projects they head | Maintainer of those projects' repositories |
| PM | The project's manager | Maintainer of the project's repositories |
| Developers | Members of project teams | Read or write on the repositories they are given |
| Super-admin | Super-admin | Site admin (already in place) |

## 1. Repository access

### Who has access

A linked repository's forge access has three sources:

1. **Organization-wide, unchanged:** the forge organization's Owners (active
   organizational admins, kept by `ForgeMembership`) and site admins
   (super-admins).
2. **Maintainers, automatic:** the project's manager (its team's leader) and,
   when the project is in a program, the program's manager (under the same
   conditions as `WorkspaceAccess::managesProgramOf`). Both active. They are
   collaborators with **admin** permission. **(my call:** the program
   manager is a maintainer, because the tracker already gives program
   managers everything a project manager has on code.)
3. **Grants, explicit:** a person and a permission, `read` or `write`, per
   repository, stored in the tracker (`git_repository_grants`).

A grant counts (is *effective*) while the person is active and either belongs
to the project's organization or is on the project's team (a
cross-organization team's outsiders). Leaving the project's team deletes that
person's grants on the project's repositories, whoever made them. **(my
call:** one simple rule; an organizational admin can grant again.)

### Who may grant, and to whom

- **May manage a repository's access:** exactly `canManageProjectRepositories`
  (the project's manager, its program's manager, its organization's
  organizational admins, super-admins; git on).
- **May grant to:**
  - a super-admin or an organizational admin of the project's organization
    (whoever `administers` it): any active person of the project's
    organization, or anyone on the project's team (the owner's addition);
  - the project's manager or program manager: only people on the project's
    team.
- Never to someone inactive, to a super-admin's own member (no organization),
  or to the repository's maintainers (they already manage it).
- The same people change a grant's permission and remove grants. A
  maintainer's own access is not editable; it follows the role.

### On the forge

Each person becomes an individual collaborator on that one repository (PUT
`/repos/{owner}/{repo}/collaborators/{login}` with `read`, `write` or
`admin`). Verified on a real Forgejo: an organization repository takes
individual collaborators who are not members of the organization, the PUT
updates an existing collaborator's permission, adding a login the forge does
not have answers 422 ("user does not exist"), removing an absent one answers
204, and the collaborator list carries no permissions.

**The sync** (`RepositoryAccess::sync`), for one repository:

1. Wanted: maintainers as `admin`, effective grants at their permission (the
   higher one wins if someone is both), keyed by forge username
   (`users.git_username`); people without one are *waiting* (they have
   never signed in to the forge).
2. PUT every wanted login with its permission. A 422 marks that person
   *waiting* for now; anything else from the forge fails the run.
3. Remove every current collaborator who is someone the tracker knows (a
   `users.git_username`, compared lowercased) and is not wanted. Never touch
   `{owner}-bot`, `forge-admin` or logins the tracker does not know (the
   same rule as `ForgeMembership`). Each change logs at `warning`.
4. For a repository whose `main` the tracker protects, rewrite the rule's
   push allow-list to the maintainers who are now collaborators (after step
   2: the forge silently drops allow-listed users who lack write access, and
   drops them again when they are removed).

**When it runs:**

- at once (a job after the response, `App\Support\Background`) when a grant
  is made, changed or removed; when a project's team changes (add, removal,
  role change); when a program's manager changes; when a project moves
  between programs; after a repository is linked or created;
- every ten minutes for every linked repository (`git:sync-access`), which
  picks up people whose first forge sign-in came after their grant, and
  anything an event missed;
- in the nightly `git:reconcile`, one step per repository.

**Deactivation and deletion** (`RemoveFromForgeService`, which already takes
a person out of every connected forge organization) also removes them as a
collaborator from every repository of each connected forge organization
where the forge lists them, linked or not (an unlinked repository keeps the
collaborators the tracker gave it), best effort with the same "Couldn't
remove" message when any call fails. Only real removals are logged.

**Linking a repository someone made on the forge** first imports its current
collaborators as grants, for people the tracker knows who could be granted
by an organizational admin (forge `admin` and `write` become `write`, `read`
stays `read`; maintainers are skipped), so linking never silently takes
access away. **(my call)**

### Audit

Grants write project activity through an observer, as everything else does:
"gave Asha write access to sdoc/egp-api", "changed Asha's access to
sdoc/egp-api to read", "removed Asha's access to sdoc/egp-api". The grant
model joins the morph map as `git-repository-grant`.

## 2. Creating a repository

- In the Code tab's Link repository popover: **New repository**, shown first
  when nothing is left to link.
- **Name:** the project key lowercased (`egp`), or that followed by a hyphen
  and more (`egp-api`), as the old design said. Lowercase letters and
  digits, single `.`, `_` or `-` between them, at most 100 characters, not
  ending in `.git`, `.wiki`, `.rss` or `.atom`. Prefilled with the key when
  the project has no repository named so, otherwise `key-`.
- **Description:** optional, at most 255 characters.
- **On the forge** (the connection's bot, whose token already carries
  `write:organization` and `write:repository`): POST `/orgs/{owner}/repos`
  with `private: true`, `auto_init: false`, `default_branch: main`. A 409 is
  "A repository named {name} already exists in {owner}. Link it instead."
- **Protect `main`** (POST `/repos/{owner}/{repo}/branch_protections`):
  `rule_name` and `branch_name` `main`, `enable_push: true`,
  `enable_push_whitelist: true`, `push_whitelist_usernames: []` (filled by
  the sync), `push_whitelist_teams: ["Owners"]`, `required_approvals: 1`,
  `dismiss_stale_approvals: true`, `block_on_rejected_reviews: true`.
  Verified on a real Forgejo: a rule on a still-empty repository is
  accepted; `apply_to_admins` does **not** let an admin collaborator push,
  hence the allow-list; allow-listed maintainers and Owners push, write
  collaborators are refused and use branches and pull requests. The
  repository is marked `protects_main`. If the rule is later deleted on the
  forge (404 when the sync rewrites it), the tracker clears `protects_main`
  and leaves it deleted: a maintainer chose that.
- Then the ordinary link (`LinkRepositoryService`), then the access sync. If
  protecting fails, the repository is still linked and the message says to
  protect `main` on the forge; if linking fails after the forge made it, the
  message says it exists and can be linked.
- Who: `canManageProjectRepositories`. Web and API twins.

## 3. Code reports

### Data

Each `git_commits` row gains:

- `user_id` (nullable, `nullOnDelete`): the tracker user whose
  `git_username` equals the commit's `author_username`, lowercased. Forgejo
  fills the author's username when the commit's author email belongs to a
  forge account (its primary email, which is the tracker email, or a verified
  extra one). Unmatched commits are reported as "Not matched to anyone",
  and the Guide tells developers to add their git email under SDOC Git →
  Settings → Emails.
- `is_merge`, `files_changed`, `additions`, `deletions` (the forge's own
  totals, every file), and the classified counts `code_added`,
  `code_removed`, `comment_added`, `comment_removed`, `blank_added`,
  `blank_removed`, `docs_added`, `docs_removed`, `generated_lines` (added
  plus removed lines of skipped files), `stats_status` (`ok`, `merge`,
  `too_large`, `missing`) and `stats_computed_at`.

### Counting

`git:commit-stats` (every five minutes, without overlapping, at most 300
commits a run) takes commits with no `stats_computed_at`, oldest first:

1. GET `/repos/{owner}/{repo}/git/commits/{sha}?stat=true&verification=false&files=false`:
   more than one parent is a merge (`is_merge`, status `merge`, no line
   counts); the forge's `stats` give `additions` and `deletions`.
2. Otherwise GET `/repos/{owner}/{repo}/git/commits/{sha}.diff` and classify
   it (`App\Support\Git\DiffStats`). A diff over 5 MB is not read: status
   `too_large`, forge totals only.
3. A 404 is status `missing`. An unreachable forge or a 5xx leaves the
   commit for the next run and stops the run for that connection.

**Classifying** each added and removed line, per file:

- **Generated, skipped** (counted only in `generated_lines`): lock files
  (`package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `composer.lock`,
  `Gemfile.lock`, `Cargo.lock`, `poetry.lock`, `go.sum`, `pubspec.lock`,
  `Podfile.lock`, `mix.lock`), anything under `vendor/`, `node_modules/`,
  `dist/`, `build/`, `public/build/`, `.next/`, `coverage/`, minified files
  (`.min.js`, `.min.css`), source maps (`.map`), and binary files.
- **Docs:** `.md`, `.markdown`, `.rst`, `.txt`, `.adoc`.
- **Code, comment or blank** for everything else: blank is whitespace only;
  comment is a line inside or opening a comment of the file's language
  (line markers such as `//`, `#`, `--`, `;`; block markers such as `/* */`,
  `<!-- -->`, `{{-- --}}`, Python docstrings); everything else is code,
  including files whose language has no known comment syntax. Block state
  is tracked through each hunk on both sides (context lines advance both,
  removed lines the old side, added lines the new side). It is a heuristic,
  as `cloc` is; the Guide says so.

### The report

**Code activity** in the sidebar (shown when the user has git), at
`/workspace/reports/code`, and `GET /api/v1/workspace/reports/code`.

- **Filters:** a date range (7, 30 or 90 days, or from and to; at most 366
  days; default the last 30), a project or a program, and up to four people
  to compare.
- **Counts only** non-merge commits that are still reachable
  (`unreachable_at` null: a rebased branch's old commits would count twice),
  by `committed_at`.
- **Who sees what:**
  - super-admins: every connected organization;
  - an organizational admin: their organization's repositories;
  - a program manager: the repositories of their programs' projects;
  - a project manager: the repositories of the projects they manage;
  - everyone: their own commits, wherever they are.
- **Per person:** commits, active days, repositories, files changed, code
  lines added and removed, comment lines added and removed, blank and docs
  lines, pull requests opened and merged (`git_pull_requests` by author,
  in range), and tasks their commits are linked to.
- **Compare:** up to four people side by side, with a daily chart of code
  lines changed.
- **One person:** their latest commits in range (repository, first line of
  the message, code and comment counts, date, link to the forge).
- Commits not matched to anyone are one row for those who see whole
  repositories.

## Constraints

- PHP 8.5, Laravel 13, Pest, PHPStan level 7, Pint; Svelte 5 runes; the
  repository's CLAUDE.md (controller, FormRequest, service, resource; web
  and API twins; every role decision in `WorkspaceAccess`; semantic UI
  classes only; no em-dashes in copy; Lucide icons).
- jsonb for any JSON column; no test depends on JSON key order.
- Tokens and secrets never in a resource, flash, exception or log.
- Forge calls only through `ForgejoClient`; only endpoints Forgejo 15
  documents (all used here are in 15.0.11's swagger).
