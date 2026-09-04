# Conventions

Full binding rules live in `CLAUDE.md` (and `~/rayzen/CLAUDE.md`). Summary map:

## Commands

```bash
composer test              # config:clear + pint check + phpstan (level 7) + Pest
composer types:check       # PHPStan over app/ config/ database/ routes/
vendor/bin/pint --dirty
npm run lint && npm run format && npm run types:check && npm run build
php artisan test --compact tests/Feature/Workspace   # scoped test runs
php artisan db:seed --class=WorkspaceDemoSeeder      # demo data (non-prod)
```

`laravel/pao` rewrites pint/phpstan/pest output to JSON; on a PHP fatal the
JSON is empty — rerun `php -d display_errors=stderr vendor/bin/pest > file 2>&1`.

## PHP

- PHP 8.5, constructor promotion, explicit return types, curly braces always,
  PHPDoc array shapes. Models carry `@property` blocks matching casts.
- Dates are `CarbonImmutable` app-wide; accept `CarbonInterface` in signatures.
- Services never throw on expected failure — return `ServiceResult::failure()`.
- All role/permission checks route through `App\Support\WorkspaceAccess`; never
  re-implement inline. Changes to it require happy + failure +
  privilege-escalation tests.
- Audit trail written only by observers via `ProjectActivityRecorder`.
- New polymorphic subjects must be added to the morph map in
  `AppServiceProvider` (strict morph map: `user`, `task`, `task-comment`, ...).
- Slugs in routes, never numeric ids, for projects/tasks.
- Never hardcode task status strings — derive from config
  (`Task::completeStatuses()`); lateness is derived, not a status.

## Frontend

- Svelte 5 runes; `SvelteSet`/`SvelteMap` for reactive collections; no `{@html}`.
- Semantic theme utilities only (`bg-surface`, `text-fg-muted`, ...): never raw
  palette colors, `dark:` variants (exception: `lib/noteColors.ts` stickies),
  colored status pills, `rounded-xl`/`shadow-*` outside `.popover`, unicode
  glyph icons (Lucide only), em-dashes in copy, or mono-uppercase eyebrows.

## Adding a feature (the pattern)

Write once: FormRequest (authorize + validate) → action Service (ServiceResult)
→ JsonResource. Then add a thin method to **both** controllers
(`Workspace\...` and `Api\...`) and a route in **both** route files.
`Api\SubtaskController` is the canonical reference.
