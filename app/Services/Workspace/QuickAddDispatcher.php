<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Support\QuickAddParser;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a single line of natural language into a task, shared by the web and API
 * quick-add controllers so the parsing/resolution rules live in exactly one place.
 *
 * The title is parsed for #project / @assignee / !priority / date tokens; explicit
 * picker fields win over parsed values, resolved tokens are stripped from the title,
 * and unresolvable tokens stay in it so nothing typed is silently lost. `@name`
 * resolves against the project's assignable members, so the project is resolved first.
 * With no project at all, an organizational admin whose organization has none yet
 * may start one, "General", and the task is filed there (both or neither).
 *
 * @phpstan-import-type Token from QuickAddParser
 */
final readonly class QuickAddDispatcher
{
    public function __construct(
        private QuickAddTaskService $service,
        private StartGeneralProjectService $general,
    ) {}

    /**
     * @param  list<int>  $explicitAssigneeIds  Picker-selected members; win over parsed @tokens.
     * @param  list<int>  $dependsOnTaskIds  Tasks the new one waits for (same project only).
     * @param  bool  $startGeneral  With no project chosen: start the organization's "General" for the task.
     */
    public function dispatch(
        string $rawTitle,
        ?int $projectId,
        array $explicitAssigneeIds,
        ?string $priority,
        ?string $deadline,
        Authenticatable $user,
        ?string $status = null,
        ?string $description = null,
        array $dependsOnTaskIds = [],
        bool $startGeneral = false,
    ): ServiceResult {
        $tokens = QuickAddParser::parse($rawTitle);
        $consumed = [];

        // A missing or archived project is a message, not a 404: the picker can
        // hold a stale id, and findOrFail would drop the whole modal.
        $project = $this->resolveProject($tokens, $consumed, $user)
            ?? ($projectId === null ? null : Project::query()->active()->find($projectId));

        if ($project === null && $projectId === null && $startGeneral) {
            return $this->inGeneral($user, fn (Project $general): ServiceResult => $this->dispatch(
                $rawTitle, $general->id, $explicitAssigneeIds, $priority, $deadline, $user, $status, $description, $dependsOnTaskIds,
            ));
        }

        if ($project === null) {
            return ServiceResult::failure('Pick a project.', 422);
        }

        if (! WorkspaceAccess::canViewProject($user, $project)) {
            return ServiceResult::failure('That project is not available to you.', 403);
        }

        $assignees = $explicitAssigneeIds;
        if ($assignees === []) {
            $assignees = $this->resolveAssignees($project, $tokens, $consumed);
        }
        // Default to self only when the acting user is actually assignable on this
        // project (on one of its teams, or the project has no teams). Otherwise the
        // task is created unassigned — to be assigned later — rather than rejected.
        if ($assignees === []) {
            $self = Member::forUser($user)->id;
            if (Member::assignableFor($project)->whereKey($self)->exists()) {
                $assignees = [$self];
            }
        }

        foreach ($tokens as $token) {
            if ($token['type'] === 'priority') {
                $priority ??= $token['value'];
                $consumed[] = $token;
            }

            if ($token['type'] === 'date') {
                $deadline ??= $token['value'];
                $consumed[] = $token;
            }
        }

        return $this->service->execute(
            project: $project,
            title: QuickAddParser::strip($rawTitle, $consumed),
            assigneeMemberIds: $assignees,
            deadline: $deadline,
            priority: $priority ?? 'medium',
            authorUserId: $user->getAuthIdentifier(),
            status: $status,
            description: $description,
            dependsOnTaskIds: $dependsOnTaskIds,
            actor: $user,
        );
    }

    /**
     * Starts "General" and files the task there in one transaction: when the
     * task is refused, General goes too.
     *
     * @param  callable(Project): ServiceResult  $file
     */
    private function inGeneral(Authenticatable $user, callable $file): ServiceResult
    {
        DB::beginTransaction();

        try {
            $started = $this->general->execute($user);
            $result = $started->success && $started->data instanceof Project ? $file($started->data) : $started;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($result->success) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $result;
    }

    /**
     * @param  list<Token>  $tokens
     * @param  list<Token>  $consumed
     */
    private function resolveProject(array $tokens, array &$consumed, Authenticatable $user): ?Project
    {
        foreach ($tokens as $token) {
            if ($token['type'] !== 'project') {
                continue;
            }

            $needle = mb_strtolower($token['value']);

            // Only projects the user can see, so a #token never matches (or
            // confirms the existence of) another organization's project.
            $candidates = fn () => Project::query()->visibleTo($user)->active();

            $project = $candidates()->whereRaw('LOWER(slug) = ?', [$needle])->first()
                ?? $candidates()->whereRaw('LOWER(slug) LIKE ?', [$needle.'%'])->orderBy('title')->first()
                ?? $candidates()->whereRaw('LOWER(title) LIKE ?', [$needle.'%'])->orderBy('title')->first()
                ?? $candidates()->whereRaw('LOWER(slug) LIKE ?', ['%'.$needle.'%'])->orderBy('title')->first()
                ?? $candidates()->whereRaw('LOWER(title) LIKE ?', ['%'.$needle.'%'])->orderBy('title')->first();

            if ($project) {
                $consumed[] = $token;

                return $project;
            }
        }

        return null;
    }

    /**
     * @param  list<Token>  $tokens
     * @param  list<Token>  $consumed
     * @return list<int>
     */
    private function resolveAssignees(Project $project, array $tokens, array &$consumed): array
    {
        $ids = [];

        foreach ($tokens as $token) {
            if ($token['type'] !== 'assignee') {
                continue;
            }

            $needle = mb_strtolower($token['value']);

            $member = Member::assignableFor($project)
                ->whereRaw('LOWER(name) LIKE ?', [$needle.'%'])
                ->first();

            if ($member) {
                $ids[] = (int) $member->getKey();
                $consumed[] = $token;
            }
        }

        return array_values(array_unique($ids));
    }
}
