<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

it('folds existing teams into one team per project, each with at most one leader', function () {
    $migration = require database_path('migrations/2026_10_29_000000_give_each_project_one_team.php');
    $migration->down();

    $org = Organization::factory()->create();
    $shared = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Shared']);
    $own = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Own']);
    $second = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Second', 'is_cross_organization' => true]);
    $idle = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Idle']);
    [$a, $b, $c, $d] = Member::factory()->count(4)->create(['organization_id' => $org->id])->all();
    $shared->members()->attach([$a->id => ['role' => 'leader'], $b->id => ['role' => 'leader']]);
    $own->members()->attach([$c->id => ['role' => 'leader']]);
    $second->members()->attach([$d->id => ['role' => 'member'], $a->id => ['role' => 'member']]);

    $solo = Project::factory()->create(['organization_id' => $org->id, 'title' => 'Solo']);
    $solo->teams()->attach($own->id);
    $merged = Project::factory()->create(['organization_id' => $org->id, 'title' => 'Merged']);
    $merged->teams()->attach([$shared->id, $second->id]);
    $sharer = Project::factory()->create(['organization_id' => $org->id, 'title' => 'Sharer']);
    $sharer->teams()->attach($shared->id);
    $empty = Project::factory()->create(['organization_id' => $org->id, 'title' => 'Empty']);

    $migration->up();

    $teamOf = fn (Project $p): Team => Project::query()->findOrFail($p->id)->team();
    $rosterOf = fn (Team $t): array => $t->members()->orderBy('members.id')->get()
        ->mapWithKeys(fn (Member $m) => [$m->id => $m->getRelation('pivot')->role])->all();

    // Its only, unshared team stays, named after it.
    expect($teamOf($solo)->id)->toBe($own->id)
        ->and($teamOf($solo)->name)->toBe('Solo')
        ->and($rosterOf($teamOf($solo)))->toBe([$c->id => 'leader']);

    // Several teams, or a shared one: a new team with everyone; the first leader leads.
    expect($teamOf($merged)->name)->toBe('Merged')
        ->and($teamOf($merged)->is_cross_organization)->toBeTrue()
        ->and($rosterOf($teamOf($merged)))->toBe([$a->id => 'leader', $b->id => 'member', $d->id => 'member'])
        ->and($rosterOf($teamOf($sharer)))->toBe([$a->id => 'leader', $b->id => 'member'])
        ->and($teamOf($empty)->members()->count())->toBe(0);

    // Teams serving no project are retired; nothing else is.
    expect(Team::query()->whereKey([$shared->id, $second->id, $idle->id])->count())->toBe(0)
        ->and(Team::withTrashed()->whereKey([$shared->id, $second->id, $idle->id])->count())->toBe(3)
        ->and(Member::query()->count())->toBe(4);

    expect(DB::table('project_team')->count())->toBe(4)
        ->and(DB::table('member_team')->where('role', 'leader')->selectRaw('team_id, count(*) as n')->groupBy('team_id')->havingRaw('count(*) > 1')->count())->toBe(0);
});
