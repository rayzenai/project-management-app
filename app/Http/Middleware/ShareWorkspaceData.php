<?php

namespace App\Http\Middleware;

use App\Http\Resources\NoteResource;
use App\Http\Resources\WorkspaceNoteResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\WorkspaceNote;
use App\Services\ResolveThemeTokens;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares request-independent workspace data as Inertia props on every
 * workspace page:
 *
 * - `statuses` — the ordered task workflow from config/project-management.php, with
 *   completion flags, so status chips, board columns, and pickers all render
 *   from one definition.
 * - `workspaceNotes` — the authenticated user's personal sticky notes
 *   (newest-updated first) so the top-bar notes icon, its count badge, and
 *   the slide-over drawer render instantly everywhere without a fetch.
 * - `taskNotes` — recent task-anchored notes (`project_notes`) relevant to the
 *   user: notes they authored, plus notes on any task assigned to them (even when
 *   authored by a teammate). Includes task + project context for deep-linking.
 *   These are read-only in the notes panel/board (kept separate from the editable,
 *   draggable `workspaceNotes` stickies) so every note relevant to the user is
 *   visible in one place.
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
            ])
            ->values()
            ->all());

        Inertia::share('completeStatus', fn (): string => (string) config('project-management.complete_status', 'done'));

        Inertia::share('quickAddContext', function () use ($request): ?array {
            if (! $request->user()) {
                return null;
            }

            $activeMemberIds = Member::query()->active()->pluck('id')->all();

            return [
                'projects' => Project::query()->visibleTo($request->user())->active()->orderBy('title')
                    ->with(['teams.members' => fn ($q) => $q->where('is_active', true)])
                    ->get(['id', 'slug', 'title'])
                    ->map(fn (Project $p): array => [
                        'id' => $p->id,
                        'slug' => $p->slug,
                        'title' => $p->title,
                        // Members assignable within this project: its teams' active members,
                        // or every active member when the project has no teams yet. Mirrors
                        // Member::scopeAssignableFor so the picker only offers valid assignees.
                        'member_ids' => $p->teams->isEmpty()
                            ? $activeMemberIds
                            : $p->teams->flatMap(fn ($t) => $t->members->pluck('id'))->unique()->values()->all(),
                    ])
                    ->all(),
                'team' => Member::query()->active()->orderBy('name')
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
            if (! $user) {
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
                'email_notifications' => $request->user()?->appearance()['email_notifications'] ?? true,
                'configured' => $request->user()?->preferences()->exists() ?? false,
            ];
        });

        Inertia::share('themeCatalogue', fn (): array => [
            'themes' => (array) config('themes.themes'),
            'fontAllowList' => (array) config('themes.font_allow_list'),
        ]);

        Inertia::share('unreadNotifications', fn (): int => $request->user()?->unreadNotifications()->count() ?? 0);

        Inertia::share('flash', fn (): ?array => $request->session()->get('workspace_flash'));

        Inertia::share('isSuperAdmin', fn (): bool => $request->user() !== null
            && WorkspaceAccess::isSuperAdmin($request->user()));

        Inertia::share('ledTeamIds', fn (): array => $request->user() !== null
            ? WorkspaceAccess::ledTeamIds($request->user())
            : []);

        return $next($request);
    }
}
