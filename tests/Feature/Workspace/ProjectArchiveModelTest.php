<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;

it('archives and restores a project and scopes by state', function () {
    $active = Project::factory()->create();
    $archived = Project::factory()->create();

    $archived->archive();

    expect($archived->fresh()->is_archived)->toBeTrue()
        ->and($archived->fresh()->archived_at)->not->toBeNull()
        ->and(Project::active()->pluck('id')->all())->toBe([$active->id])
        ->and(Project::archived()->pluck('id')->all())->toBe([$archived->id]);

    $archived->restore();

    expect($archived->fresh()->is_archived)->toBeFalse()
        ->and(Project::active()->count())->toBe(2);
});

it('exposes team leaders and a member led-teams relation', function () {
    $team = Team::factory()->create();
    $leader = Member::factory()->create();
    $plain = Member::factory()->create();

    $team->members()->attach($leader->id, ['role' => 'leader']);
    $team->members()->attach($plain->id);

    expect($team->leaders()->pluck('members.id')->all())->toBe([$leader->id])
        ->and($leader->ledTeams()->pluck('teams.id')->all())->toBe([$team->id])
        ->and($plain->ledTeams()->count())->toBe(0);
});
