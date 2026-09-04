# .assistants — PMOPM project map

- **generated-from:** `bd40a8c386a25e923369776bff627494f8ce8359`

Agent-readable map of this repo. Read alongside `CLAUDE.md` (binding rules);
these files are orientation, not rules.

**What this app is:** PMOPM — a standalone Laravel 13 + Inertia + Svelte 5
project/task tracking workspace (projects → tasks → subtasks, teams, members,
notes, contacts, activity log, in-app notifications, weekly digest, 100-point
plan tracker) with a parallel Sanctum JSON API for external systems. The former
`rayzenai/project-management` package is retired; all code lives under `App\`.

## Modules

- [struct.md](struct.md) — directory layout and where each concern lives.
- [conventions.md](conventions.md) — coding conventions, commands, CI gates.
- [backend.md](backend.md) — Laravel architecture: request flow, authorization
  model, domain model, notifications, scheduled commands.
- [frontend.md](frontend.md) — Svelte 5 + Inertia pages/components, the
  semantic theming layer, UI rules.
- [testing.md](testing.md) — test layout, how to run, what coverage is expected.
