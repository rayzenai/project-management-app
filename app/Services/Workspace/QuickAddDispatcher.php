<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Support\QuickAddParser;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Turns a single line of natural language into a task, shared by the web and API
 * quick-add controllers so the parsing/resolution rules live in exactly one place.
 *
 * The title is parsed for #project / @assignee / !priority / date tokens; explicit
 * picker fields win over parsed values, resolved tokens are stripped from the title,
 * and unresolvable tokens stay in it so nothing typed is silently lost. `@name`
 * resolves against the project's assignable members, so the project is resolved first.
 *
 * @phpstan-import-type Token from QuickAddParser
 */
final readonly class QuickAddDispatcher
{
    public function __construct(private QuickAddTaskService $service) {}

    /**
     * @param  list<int>  $explicitAssigneeIds  Picker-selected members; win over parsed @tokens.
     */
    public function dispatch(
        string $rawTitle,
        ?int $projectId,
        array $explicitAssigneeIds,
        ?string $priority,
        ?string $deadline,
        Authenticatable $user,
    ): ServiceResult {
        $tokens = QuickAddParser::parse($rawTitle);
        $consumed = [];

        $project = $this->resolveProject($tokens, $consumed)
            ?? Project::query()->active()->findOrFail($projectId);

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
        );
    }

    /**
     * @param  list<Token>  $tokens
     * @param  list<Token>  $consumed
     */
    private function resolveProject(array $tokens, array &$consumed): ?Project
    {
        foreach ($tokens as $token) {
            if ($token['type'] !== 'project') {
                continue;
            }

            $needle = mb_strtolower($token['value']);

            $project = Project::query()->active()->whereRaw('LOWER(slug) = ?', [$needle])->first()
                ?? Project::query()->active()->whereRaw('LOWER(slug) LIKE ?', [$needle.'%'])->orderBy('title')->first()
                ?? Project::query()->active()->whereRaw('LOWER(title) LIKE ?', [$needle.'%'])->orderBy('title')->first()
                ?? Project::query()->active()->whereRaw('LOWER(slug) LIKE ?', ['%'.$needle.'%'])->orderBy('title')->first()
                ?? Project::query()->active()->whereRaw('LOWER(title) LIKE ?', ['%'.$needle.'%'])->orderBy('title')->first();

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
