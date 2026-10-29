# Testing

Pest 4 (+ pest-plugin-laravel). CI gate is `composer test`
(config:clear + pint check + PHPStan level 7 + Pest).

## Layout

- `tests/Feature/Workspace/**` — web-surface feature tests (visibility,
  permissions, quick-add, notifications, soft-delete/restore, reminders,
  seeder, plan tracker, appearance, login-detail email, organizations,
  project progress, task dependencies, multi-tenant isolation, stable task codes,
  Git connections, OIDC sign-in and forge offboarding).
- `tests/Feature/Workspace/Api/**` — API-surface twins (`AuthApiTest`,
  `ProjectApiTest`, `TaskApiTest`, `TaskCommentApiTest`, `MemberApiTest`,
  `TeamRosterApiTest`, `NotificationApiTest`, `RestoreApiTest`).
- `tests/Feature/` root — cross-cutting (WorkspaceAccessTest, theme tokens,
  user preferences, api-token command, seeders).
- `tests/Unit/` — parsers (MentionParser).
- `ProjectProgressTest` covers both surfaces, access gates and legacy web
  redirects; `TaskDependencyTest` covers web/API links, cycle/scope validation,
  audit logging, quick-add and graph props. `OneTeamPerProjectMigrationTest`
  covers folding existing teams into project-owned teams with one leader.
  `ProjectCreateAccessTest`/`ProjectUpdateAccessTest` and `TenancyTest` cover
  manager appointment, roster protections and reserved super-admin emails.
- `OverdueRuleTest` checks the model scope/attribute and project/Home counts;
  `DeadlineReminderTest` also excludes failed (closed) work. `TeamActivityTest`
  covers roster/manager audit entries, team notifications, actor suppression,
  project-feed visibility on web/API and the publication capability.
  `GuidePageTest` checks signed-in access and guest redirects;
  `SdocProjectsSeederTest` checks project/team creation, safe reruns and
  isolation from another organization's same-named project, plus agreed-key
  migration with aliases and preservation of admin-chosen keys.
- `ProjectKeyTest` covers suggestion/backfill, tenant uniqueness, reserved aliases
  and web/API key-change permissions; `ProjectCodeTest` covers resource/query
  code loading. `TaskNumberTest` covers backfill, counter non-reuse after purge,
  number immutability on web/API, quick-add, code/alias search and legacy bounds.
- `GitConnectionTest` covers web/API super-admin gates, forge ownership checks,
  connection failures, URL validation/normalization, office uniqueness and secret
  redaction. `ForgeOffboardingTest` covers deactivation/deletion across all
  connections, partial failures, token masking and completion feedback.
- `OidcProviderTest` covers discovery/JWKS, code-to-token claims, access/tenant
  gates (including cross-organization teams), scope filtering, redirect/consent
  handling, SPA login redirect, sessionless throttled token route, persistent
  Forgejo-safe usernames, purge schedule and client command, with temporary
  signing keys generated in the test.
- `OptionalFeaturesTest` covers default-off notes/contacts, web/API preference
  updates, preservation of other settings, boolean validation, shared task-note
  feed gating and API user feature context. `TaskNotesInWorkspaceFeedTest`
  explicitly enables task notes before testing feed contents.

## Running

```bash
composer test                                        # full gate
php artisan test --compact tests/Feature/Workspace   # one directory
php artisan test --compact --filter=ProjectProgress  # one test/group
```

Output is JSON-rewritten by `laravel/pao`; empty JSON means a PHP fatal —
rerun `php -d display_errors=stderr vendor/bin/pest > file 2>&1`.

## Expectations

- Any change to `WorkspaceAccess` or an action service needs happy-path +
  failure-path + privilege-escalation tests.
- Tenant changes must prove both isolation and the deliberate
  cross-organization-team exception across super-admin, office-admin, leader
  and member roles (`TenancyTest` is the broad regression suite).
- New features touch both surfaces, so test both (web dir + Api dir).
- Frontend gate: `npm run lint && npm run format && npm run types:check &&
  npm run build` (no JS test suite).
