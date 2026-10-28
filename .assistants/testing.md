# Testing

Pest 4 (+ pest-plugin-laravel). CI gate is `composer test`
(config:clear + pint check + PHPStan level 7 + Pest).

## Layout

- `tests/Feature/Workspace/**` — web-surface feature tests (visibility,
  permissions, quick-add, notifications, soft-delete/restore, reminders,
  seeder, plan tracker, appearance, login-detail email, organizations,
  team progress, multi-tenant isolation).
- `tests/Feature/Workspace/Api/**` — API-surface twins (`AuthApiTest`,
  `ProjectApiTest`, `TaskApiTest`, `TaskCommentApiTest`, `MemberApiTest`,
  `TeamRosterApiTest`, `NotificationApiTest`, `RestoreApiTest`).
- `tests/Feature/` root — cross-cutting (WorkspaceAccessTest, theme tokens,
  user preferences, api-token command, seeders).
- `tests/Unit/` — parsers (MentionParser).

## Running

```bash
composer test                                        # full gate
php artisan test --compact tests/Feature/Workspace   # one directory
php artisan test --compact --filter=TeamPermissions  # one test/group
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
