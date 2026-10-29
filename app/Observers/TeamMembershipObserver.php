<?php

namespace App\Observers;

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\MadeProjectManager;
use App\Services\ProjectActivityRecorder;
use Illuminate\Notifications\Notification;

/**
 * Who joins or leaves a project's team, and who manages it, goes into the
 * project's activity; the person joining, or made manager, is told (unless
 * they did it themselves).
 */
class TeamMembershipObserver
{
    public function created(TeamMembership $membership): void
    {
        $context = $this->context($membership);

        if ($context === null) {
            return;
        }

        [$team, $project, $member] = $context;
        $managing = $membership->role === 'leader';

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $team,
            action: ProjectActivity::ACTION_CREATED,
            description: $managing
                ? "{$member->name} manages {$project->title}"
                : "Added {$member->name} to the {$project->title} team",
            changes: ['member_id' => $member->id, 'role' => $managing ? 'leader' : 'member'],
        );

        $this->tell($member, $managing
            ? new MadeProjectManager($project, $this->actorName())
            : new AddedToProjectTeam($project, $this->actorName()));
    }

    public function updated(TeamMembership $membership): void
    {
        if (! $membership->wasChanged('role')) {
            return;
        }

        $context = $this->context($membership);

        if ($context === null) {
            return;
        }

        [$team, $project, $member] = $context;
        $managing = $membership->role === 'leader';

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $team,
            action: ProjectActivity::ACTION_UPDATED,
            description: $managing
                ? "Made {$member->name} manager of {$project->title}"
                : "{$member->name} no longer manages {$project->title}",
            changes: ['member_id' => $member->id, 'from' => $membership->getOriginal('role'), 'to' => $membership->role],
        );

        if ($managing) {
            $this->tell($member, new MadeProjectManager($project, $this->actorName()));
        }
    }

    public function deleted(TeamMembership $membership): void
    {
        $context = $this->context($membership);

        if ($context === null) {
            return;
        }

        [$team, $project, $member] = $context;

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $team,
            action: ProjectActivity::ACTION_DELETED,
            description: "Removed {$member->name} from the {$project->title} team",
            changes: ['member_id' => $member->id],
        );
    }

    /**
     * @return array{0: Team, 1: Project, 2: Member}|null
     */
    private function context(TeamMembership $membership): ?array
    {
        $team = Team::withTrashed()->find($membership->team_id);
        $project = $team?->project();
        $member = Member::withTrashed()->find($membership->member_id);

        return $team && $project && $member ? [$team, $project, $member] : null;
    }

    private function tell(Member $member, Notification $notification): void
    {
        $user = $member->user;

        if ($user !== null && $user->getKey() !== auth()->id()) {
            $user->notify($notification);
        }
    }

    private function actorName(): string
    {
        return auth()->user()->name ?? 'Someone';
    }
}
