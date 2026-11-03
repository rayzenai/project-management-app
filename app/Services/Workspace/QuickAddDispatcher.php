<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Support\QuickAddParser;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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
 * may start one, "General", and the task is filed there (both or neither); or,
 * asked for ("Just for me"), the item is the caller's own private to-do,
 * unless it names someone else, which needs a project.
 *
 * @phpstan-import-type Token from QuickAddParser
 */
final readonly class QuickAddDispatcher
{
    public function __construct(
        private QuickAddTaskService $service,
        private StartGeneralProjectService $general,
        private CreatePersonalTodoService $todos,
    ) {}

    /**
     * @param  list<int>  $explicitAssigneeIds  Picker-selected members; win over parsed @tokens.
     * @param  list<int>  $dependsOnTaskIds  Tasks the new one waits for (same project only).
     * @param  bool  $startGeneral  With no project chosen: start the organization's "General" for the task.
     * @param  bool  $personal  With no project chosen: a private to-do of the caller's ("Just for me").
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
        bool $personal = false,
    ): ServiceResult {
        $tokens = QuickAddParser::parse($rawTitle);
        $consumed = [];

        // A missing or archived project is a message, not a 404: the picker can
        // hold a stale id, and findOrFail would drop the whole modal.
        $project = $this->resolveProject($tokens, $consumed, $user)
            ?? ($projectId === null ? null : Project::query()->active()->find($projectId));

        // A #token naming a project they may add to is their latest word
        // and wins over "Just for me".
        if ($project === null && $projectId === null && $personal) {
            return $this->personalTodo($rawTitle, $tokens, $explicitAssigneeIds, $deadline, $user);
        }

        if ($project === null && $projectId === null && $startGeneral) {
            return $this->inGeneral($user, fn (Project $general): ServiceResult => $this->dispatch(
                $rawTitle, $general->id, $explicitAssigneeIds, $priority, $deadline, $user, $status, $description, $dependsOnTaskIds,
            ));
        }

        $project = $this->fileable($project, $user);

        if ($project instanceof ServiceResult) {
            return $project;
        }

        $assignees = $explicitAssigneeIds;
        if ($assignees === []) {
            $assignees = $this->resolveAssignees($project, $tokens, $consumed);
        }
        if ($assignees === []) {
            $assignees = $this->self($project, $user);
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
     * Files a title already written, a private to-do moving into a project,
     * as dispatch() files a task: the same refusals and the caller on it when
     * assignable there. Its words are not read for tokens again.
     */
    public function fileTitle(int $projectId, string $title, ?string $deadline, Authenticatable $user): ServiceResult
    {
        $project = $this->fileable(Project::query()->active()->find($projectId), $user);

        if ($project instanceof ServiceResult) {
            return $project;
        }

        return $this->service->execute(
            project: $project,
            title: $title,
            assigneeMemberIds: $this->self($project, $user),
            deadline: $deadline,
            priority: 'medium',
            authorUserId: $user->getAuthIdentifier(),
            // What the add field sends: a task just written has not started.
            status: 'not_started',
            actor: $user,
        );
    }

    /**
     * The project a task may be filed in, or the answer why not: a missing
     * or archived one asks for a project, one they cannot see is refused.
     */
    private function fileable(?Project $project, Authenticatable $user): Project|ServiceResult
    {
        if ($project === null) {
            return ServiceResult::failure('Pick a project.', 422);
        }

        if (! WorkspaceAccess::canViewProject($user, $project)) {
            return ServiceResult::failure('That project is not available to you.', 403);
        }

        return $project;
    }

    /**
     * The caller, when they are assignable on the project (on its team, or
     * any active person of its organization for a public one with no team);
     * else nobody, and the task waits unassigned rather than refused.
     *
     * @return list<int>
     */
    private function self(Project $project, Authenticatable $user): array
    {
        $self = Member::forUser($user)->id;

        return Member::assignableFor($project)->whereKey($self)->exists() ? [$self] : [];
    }

    /**
     * The caller's own private to-do: date words set its date (a picked one
     * wins) and priority words are dropped. Naming anyone else needs a
     * project: a picked person, or a name after the at sign that fits any
     * active person the caller can see but them. A name that fits only the
     * caller is taken out, and one that fits nobody stays in the title.
     *
     * @param  list<Token>  $tokens
     * @param  list<int>  $explicitAssigneeIds
     */
    private function personalTodo(string $rawTitle, array $tokens, array $explicitAssigneeIds, ?string $deadline, Authenticatable $user): ServiceResult
    {
        $member = WorkspaceAccess::memberFor($user);
        $consumed = [];
        $someoneElse = array_diff($explicitAssigneeIds, [$member?->id]) !== [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'date') {
                $deadline ??= $token['value'];
                $consumed[] = $token;
            } elseif ($token['type'] === 'priority') {
                $consumed[] = $token;
            } elseif ($token['type'] === 'assignee') {
                $needle = mb_strtolower($token['value']);
                $fitsOthers = Member::query()->visibleTo($user)->active()
                    ->when($member !== null, fn (Builder $q) => $q->whereKeyNot($member?->id))
                    ->whereRaw('LOWER(name) LIKE ?', [$needle.'%'])
                    ->exists();

                if ($fitsOthers) {
                    $someoneElse = true;
                } elseif ($member !== null && str_starts_with(mb_strtolower($member->name), $needle)) {
                    $consumed[] = $token;
                }
            }
        }

        if ($someoneElse) {
            $message = 'Pick a project to give it to someone.';

            return ServiceResult::failure($message, 422, ['project_id' => $message]);
        }

        return $this->todos->execute($user, QuickAddParser::strip($rawTitle, $consumed), $deadline);
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
