<?php

namespace App\Services\Workspace;

use App\Http\Requests\Concerns\ChecksMemberRoles;
use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Support\ServiceResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sets a person's roles from the person form: the programs and projects they
 * manage and the project teams they are on. Each set is the complete wanted
 * one; a set left out is not touched. A program stays with its manager until
 * someone else is chosen. A project they no longer manage is left with no
 * manager and keeps them on its team as a member, and nobody is taken off a
 * team they still manage. Who may hand out or take away which program,
 * project or team is the FormRequests' check ({@see ChecksMemberRoles}).
 */
class UpdateMemberRolesService
{
    public function __construct(
        private AppointProjectManagerService $appoint,
        private UpdateTeamRoleService $role,
        private RemoveTeamMemberService $remove,
    ) {}

    /**
     * @param  array{managed_program_ids?: list<int>, managed_project_ids?: list<int>, team_ids?: list<int>}  $roles
     */
    public function execute(Member $member, array $roles): ServiceResult
    {
        // The person as stored: just saved by the caller, or just created.
        $member->refresh();

        $newPrograms = new Collection;
        $newProjects = new Collection;
        $droppedProjects = new Collection;

        if (array_key_exists('managed_program_ids', $roles)) {
            $held = Program::query()->where('manager_id', $member->id)->orderBy('name')->get();
            $dropped = $held->reject(fn (Program $program): bool => in_array($program->id, $roles['managed_program_ids'], true));

            if ($dropped->isNotEmpty()) {
                return $this->refuse('managed_program_ids', 'Choose another manager for the '.$dropped->pluck('name')->implode(', ').' '.Str::plural('program', $dropped->count()).' first.');
            }

            $newPrograms = Program::query()->whereKey($roles['managed_program_ids'])->whereKeyNot($held->modelKeys())->get();
        }

        if (array_key_exists('managed_project_ids', $roles)) {
            $held = Project::query()
                ->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $member->ledTeams()->select('teams.id')))
                ->with('program:id,name')
                ->orderBy('title')
                ->get();
            $droppedProjects = $held->reject(fn (Project $project): bool => in_array($project->id, $roles['managed_project_ids'], true));
            $newProjects = Project::query()->whereKey($roles['managed_project_ids'])->whereKeyNot($held->modelKeys())->get();
        }

        if (! $member->is_active && $newPrograms->isNotEmpty()) {
            return $this->refuse('managed_program_ids', 'Only an active person can manage.');
        }

        if (! $member->is_active && $newProjects->isNotEmpty()) {
            return $this->refuse('managed_project_ids', 'Only an active person can manage.');
        }

        try {
            DB::transaction(function () use ($member, $roles, $newPrograms, $newProjects, $droppedProjects): void {
                foreach ($droppedProjects as $project) {
                    $this->ensureDone($this->role->execute($project->ensureTeam(), $member, 'member'));
                }

                foreach ($newPrograms as $program) {
                    $program->manager_id = $member->id;
                    $program->save();
                }

                foreach ($newProjects as $project) {
                    $this->ensureDone($this->appoint->execute($project->ensureTeam(), $member));
                }

                if (array_key_exists('team_ids', $roles)) {
                    $this->placeOnTeams($member, $roles['team_ids']);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }

        return ServiceResult::success($member, 'Roles saved.');
    }

    /**
     * Joins the wanted teams as a member and leaves the others, except a
     * team they manage (the manager role wins).
     *
     * @param  list<int>  $wanted
     */
    private function placeOnTeams(Member $member, array $wanted): void
    {
        $current = $member->teams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_diff($wanted, $current) as $teamId) {
            $member->teams()->attach($teamId, ['role' => 'member']);
        }

        $leaving = array_diff($current, $wanted, $member->ledTeams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all());

        foreach (Team::query()->whereKey($leaving)->get() as $team) {
            $this->ensureDone($this->remove->execute($team, $member));
        }
    }

    /** A failed step undoes the whole save. */
    private function ensureDone(ServiceResult $result): void
    {
        if ($result->failed()) {
            throw new RuntimeException((string) $result->message);
        }
    }

    private function refuse(string $field, string $message): ServiceResult
    {
        return ServiceResult::failure($message, 422, [$field => $message]);
    }
}
