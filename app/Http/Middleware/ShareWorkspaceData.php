<?php

namespace App\Http\Middleware;

use App\Http\Resources\NoteResource;
use App\Http\Resources\WorkspaceNoteResource;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\WorkspaceNote;
use App\Queries\Attendance\MyAttendanceQuery;
use App\Queries\PersonalTodosQuery;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PersonRef;
use App\Services\ResolveThemeTokens;
use App\Support\Git\GitAccess;
use App\Support\OfficeLinks;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares request-independent workspace data as Inertia props on every
 * workspace page:
 *
 * - `statuses` — the ordered task workflow from config/project-management.php, with
 *   its completion, closed and started flags, so status chips, board columns,
 *   pickers and My work's circle all render from one definition.
 * - `workspaceNotes` — the authenticated user's personal sticky notes
 *   (newest-updated first) so the top-bar notes icon, its count badge, and
 *   the slide-over drawer render instantly everywhere without a fetch.
 * - `taskNotes` — recent task-anchored notes (`project_notes`) relevant to the
 *   user: notes they authored, plus notes on any task assigned to them (even when
 *   authored by a teammate). Includes task + project context for deep-linking.
 *   These are read-only in the notes panel/board (kept separate from the editable,
 *   draggable `workspaceNotes` stickies) so every note relevant to the user is
 *   visible in one place. Empty while the user has task notes turned off.
 * - `features` — the optional features (task notes, contacts) the user turned
 *   on in Settings. Screens hide what is off.
 * - `todoCount` — the user's open private to-dos (To do and Doing), the
 *   number beside the top bar's to-do button (PersonalTodosQuery::openCount).
 * - `gitEnabled` — whether the user may use git anywhere (GitAccess), so the
 *   Guide shows its Code section only to them.
 * - `clock` — the user's day for the top bar's clock button
 *   (MyAttendanceQuery); null when they can't punch. Not `attendance`: the
 *   work pages carry an `attendance` of their own, which would hide it.
 * - `office` — which office pages (organogram, attendance, leave and kaaj,
 *   work reports) the sidebar offers (OfficeLinks).
 * - `managedProgramIds` / `managedPrograms` — the programs the user manages
 *   (ids for role checks, `{slug, name}` for the sidebar); empty for most.
 * - `themeCatalogue` — the theme + font catalogue from `config/themes.php`, so
 *   the appearance onboarding/settings render their cards without fetching the
 *   Sanctum-protected `GET /api/v1/themes` endpoint (which 401s in the session
 *   context the web UI runs in).
 */
class ShareWorkspaceData
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('statuses', fn (): array => collect((array) config('project-management.statuses'))
            ->map(fn (array $meta, string $value): array => [
                'value' => $value,
                'label' => $meta['label'] ?? ucfirst($value),
                'color' => $meta['color'] ?? '#9CA3AF',
                'is_complete' => (bool) ($meta['is_complete'] ?? false),
                'is_closed' => (bool) ($meta['is_closed'] ?? $meta['is_complete'] ?? false),
                'is_started' => (bool) ($meta['is_started'] ?? false),
            ])
            ->values()
            ->all());

        Inertia::share('completeStatus', fn (): string => (string) config('project-management.complete_status', 'done'));

        Inertia::share('quickAddContext', function () use ($request): ?array {
            if (! $request->user()) {
                return null;
            }

            /** @var array<int, list<int>> $organizationMemberIds */
            $organizationMemberIds = [];
            $user = $request->user();
            $organizationId = WorkspaceAccess::memberFor($user)?->organization_id;
            $startGeneral = WorkspaceAccess::canStartGeneralProject($user);

            $projects = Project::query()->visibleTo($user)->active()->orderBy('title')
                ->with(['program:id,name', 'teams.members' => fn ($q) => $q->where('is_active', true)])
                ->get(['id', 'slug', 'title', 'organization_id', 'program_id'])
                ->map(function (Project $p) use (&$organizationMemberIds, $user): array {
                    // Members assignable within this project: its teams' active members,
                    // or its organization's when a public project has no teams. Mirrors
                    // Member::scopeAssignableFor so the picker only offers valid assignees.
                    if ($p->teams->isEmpty()) {
                        $organizationMemberIds[$p->organization_id] ??= Member::query()->active()
                            ->where('organization_id', $p->organization_id)
                            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                    }

                    return [
                        'id' => $p->id,
                        'slug' => $p->slug,
                        'title' => $p->title,
                        'organization_id' => $p->organization_id,
                        'program' => $p->program?->name,
                        'member_ids' => $p->teams->isEmpty()
                            ? $organizationMemberIds[$p->organization_id]
                            : $p->teams->flatMap(fn ($t) => $t->members->pluck('id'))->unique()->values()->all(),
                        // Whoever runs its team may also assign the people
                        // of its organization, who join the team.
                        'can_add_people' => WorkspaceAccess::canManageRosterOfProject($user, $p),
                    ];
                });

            // Everyone the picker may offer beyond a project's team: the active
            // people of the viewer's organization, for whoever runs a team in
            // it (or starts its General). The server checks each pick
            // (Assignees); people of another organization never join this way.
            $addsPeople = $organizationId !== null && ($startGeneral || $projects->contains(
                fn (array $p): bool => $p['can_add_people'] && (int) $p['organization_id'] === (int) $organizationId,
            ));

            // The project picker's dropdown: the organizations of those
            // projects, by name, in one query.
            $organizationIds = $projects->pluck('organization_id')->unique()->values();

            return [
                'projects' => $projects->all(),
                'organizations' => $organizationIds->isEmpty() ? [] : Organization::query()
                    ->whereKey($organizationIds)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Organization $organization): array => ['id' => $organization->id, 'name' => $organization->name])
                    ->all(),
                'organizationPeople' => $addsPeople
                    ? Member::query()->active()->where('organization_id', $organizationId)->orderBy('name')
                        ->with('designation:id,name')
                        ->get(['id', 'name', 'designation_id'])
                        ->map(PersonRef::of(...))
                        ->all()
                    : [],
                'startGeneral' => $startGeneral,
                // Nothing to add a task to and no General to start: the add
                // field starts on "Just for me", a private to-do.
                'startPersonal' => $projects->isEmpty() && ! $startGeneral,
                // The add field starts where the person last added a task or
                // was last added to a team, among the projects it offers them.
                'lastProjectId' => $this->lastProjectId($user, $projects->pluck('id')),
                'team' => Member::query()->visibleTo($request->user())->active()->orderBy('name')
                    ->get(['id', 'name', 'email', 'user_id'])
                    ->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'user_id' => $m->user_id])
                    ->all(),
                'currentMemberId' => Member::forUser($request->user())->id,
            ];
        });

        Inertia::share('workspaceNotes', function () use ($request): array {
            $user = $request->user();
            if (! $user) {
                return [];
            }

            return WorkspaceNoteResource::collection(
                WorkspaceNote::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('updated_at')
                    ->get()
            )->resolve();
        });

        Inertia::share('taskNotes', function () use ($request): array {
            $user = $request->user();
            if (! $user || ! $user->features()['task_notes']) {
                return [];
            }

            $memberId = Member::query()->where('user_id', $user->id)->value('id');

            return NoteResource::collection(
                ProjectNote::query()
                    ->where(function (Builder $query) use ($user, $memberId): void {
                        $query->where('user_id', $user->id);

                        if ($memberId !== null) {
                            $query->orWhereHas(
                                'task.assignments',
                                fn (Builder $assignment): Builder => $assignment->where('member_id', $memberId),
                            );
                        }
                    })
                    ->with(['user', 'task.project'])
                    ->latest()
                    ->limit(50)
                    ->get()
            )->resolve();
        });

        Inertia::share('appearance', function () use ($request): array {
            $theme = $request->user()?->appearance()['theme'] ?? config('themes.default', 'system');
            $fontOverride = $request->user()?->appearance()['font_override'] ?? null;
            $themes = (array) config('themes.themes');

            return [
                'theme' => $theme,
                'mode' => $themes[$theme]['mode'] ?? null,
                'tokens' => app(ResolveThemeTokens::class)->resolved($theme, $fontOverride),
                'font_override' => $fontOverride,
                'configured' => $request->user()?->preferences()->exists() ?? false,
            ];
        });

        Inertia::share('features', fn (): array => $request->user()?->features()
            ?? ['task_notes' => false, 'contacts' => false]);

        Inertia::share('themeCatalogue', fn (): array => [
            'themes' => (array) config('themes.themes'),
            'fontAllowList' => (array) config('themes.font_allow_list'),
        ]);

        Inertia::share('unreadNotifications', fn (): int => $request->user()?->unreadNotifications()->count() ?? 0);

        Inertia::share('todoCount', fn (): int => $request->user() instanceof User ? PersonalTodosQuery::openCount($request->user()) : 0);

        Inertia::share('flash', fn (): ?array => $request->session()->get('workspace_flash'));

        Inertia::share('isSuperAdmin', fn (): bool => $request->user() !== null
            && WorkspaceAccess::isSuperAdmin($request->user()));

        Inertia::share('ledTeamIds', fn (): array => $request->user() !== null
            ? WorkspaceAccess::ledTeamIds($request->user())
            : []);

        Inertia::share('isOfficeAdmin', fn (): bool => WorkspaceAccess::officeAdminOrganizationId($request->user()) !== null);

        Inertia::share('managedProgramIds', fn (): array => WorkspaceAccess::managedProgramIds($request->user()));

        // The sidebar's Programs group: each program the user manages, by name.
        Inertia::share('managedPrograms', function () use ($request): array {
            $ids = WorkspaceAccess::managedProgramIds($request->user());

            return $ids === [] ? [] : Program::query()->whereKey($ids)->orderBy('name')->get(['slug', 'name'])
                ->map(fn (Program $program): array => ['slug' => $program->slug, 'name' => $program->name])
                ->all();
        });

        // Whether the user may use git anywhere: the Guide shows its Code section then.
        Inertia::share('gitEnabled', fn (): bool => $request->user() instanceof User && GitAccess::anyFor($request->user()));

        // Whether documents are on (the editor is configured): the Guide's Documents section.
        Inertia::share('documentsEnabled', fn (): bool => (bool) config('documents.enabled'));

        // The top bar's clock: today's punches and the next step, or null.
        Inertia::share('clock', fn (): ?array => app(MyAttendanceQuery::class)->get($request->user()));

        // The office pages the sidebar offers.
        Inertia::share('office', fn (): array => OfficeLinks::for($request->user()));

        // A super-admin's one organization switch (WorkspaceOrganization).
        Inertia::share('organizationSwitch', fn (): ?array => app(OrganizationChoicesQuery::class)->switcher($request->user()));

        Inertia::share('organization', function () use ($request): ?array {
            $organization = WorkspaceAccess::memberFor($request->user())?->organization;

            return $organization === null ? null : ['id' => $organization->id, 'name' => $organization->name];
        });

        return $next($request);
    }

    /**
     * The project of the newer of the newest task the user created and the
     * newest team their member joined, among `$projectIds`; a tie goes to
     * the task. Two queries, whatever the number of projects.
     *
     * @param  SupportCollection<int, mixed>  $projectIds
     */
    private function lastProjectId(User $user, SupportCollection $projectIds): ?int
    {
        $task = Task::query()
            ->where('created_by', $user->id)
            ->whereIn('project_id', $projectIds)
            ->latest()->latest('id')
            ->first(['project_id', 'created_at']);
        $member = WorkspaceAccess::memberFor($user);
        $joined = $member === null ? null : TeamMembership::query()
            ->join('project_team', 'project_team.team_id', '=', 'member_team.team_id')
            ->where('member_team.member_id', $member->id)
            ->whereIn('project_team.project_id', $projectIds)
            // A membership with no date (rows from before the column was
            // filled) says nothing about when; Postgres would sort it first.
            ->whereNotNull('member_team.created_at')
            ->orderByDesc('member_team.created_at')->orderByDesc('member_team.id')
            ->first(['project_team.project_id', 'member_team.created_at']);

        if ($joined !== null && ($task === null || $task->created_at < CarbonImmutable::parse($joined->getAttribute('created_at')))) {
            return (int) $joined->getAttribute('project_id');
        }

        return $task?->project_id;
    }
}
