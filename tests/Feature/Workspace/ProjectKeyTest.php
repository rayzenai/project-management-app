<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKeyAlias;
use App\Models\User;
use App\Services\Workspace\CreateProjectService;
use App\Services\Workspace\UpdateProjectService;
use App\Support\ProjectKeys;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

/**
 * @return array{0: User, 1: Project}
 */
function keyedProject(string $title = 'eGP v2', bool $admin = true): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['title' => $title]);
    Member::factory()->linkedTo($user)->create(['organization_id' => $project->organization_id, 'is_office_admin' => $admin]);

    return [$user, $project];
}

it('suggests a key from the first ASCII letter of up to four words', function (string $title, string $expected) {
    expect(ProjectKeys::suggest($title))->toBe($expected);
})->with([
    'sms gateway' => ['SMS Gateway', 'SG'],
    'digits are skipped' => ['eGP v2', 'EV'],
    'a number word has no letter' => ['Government 100-Day Plan', 'GDP'],
    'capped at four words' => ['One Two Three Four Five', 'OTTF'],
    'nepali only' => ['सडक विभाग', 'P'],
    'dash only' => ['—', 'P'],
]);

it('gives the next project in an organization the next free key, and another organization its own', function () {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $create = app(CreateProjectService::class);

    $first = $create->execute(['organization_id' => $org->id, 'title' => 'SuTRA'])->data;
    $second = $create->execute(['organization_id' => $org->id, 'title' => 'Sarkari Portal'])->data;
    $elsewhere = $create->execute(['organization_id' => $other->id, 'title' => 'SuTRA'])->data;

    expect($first->key)->toBe('S')
        ->and($second->key)->toBe('SP')
        ->and($elsewhere->key)->toBe('S');
});

it('runs through B..Z and then BA when a key keeps being taken', function () {
    $org = Organization::factory()->create();
    $create = app(CreateProjectService::class);

    $keys = collect(range(1, 27))->map(fn () => $create->execute(['organization_id' => $org->id, 'title' => '—'])->data->key);

    expect($keys->first())->toBe('P')
        ->and($keys[1])->toBe('PB')
        ->and($keys[24])->toBe('PY')
        ->and($keys[25])->toBe('PZ')
        ->and($keys[26])->toBe('PBA')
        ->and($keys->unique()->count())->toBe(27);
});

it('backfills existing projects with a unique key from the title, in id order', function () {
    $migration = require database_path('migrations/2026_10_30_000001_give_projects_a_stable_key.php');
    $migration->down();

    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $now = now();
    foreach ([[$org, 'SuTRA', 'a'], [$org, 'Sarkari Portal', 'b'], [$org, 'Sunil', 'c'], [$other, 'SuTRA', 'd'], [$org, 'सडक', 'e']] as [$o, $title, $slug]) {
        DB::table('projects')->insert(['organization_id' => $o->id, 'title' => $title, 'slug' => $slug, 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    $migration->up();

    expect(DB::table('projects')->orderBy('id')->pluck('key', 'slug')->all())
        ->toBe(['a' => 'S', 'b' => 'SP', 'c' => 'SB', 'd' => 'S', 'e' => 'P']);
});

it('lets an office admin rename a key, keeping the old one as an alias', function () {
    [$user, $project] = keyedProject();
    expect($project->key)->toBe('EV');

    $this->actingAs($user)->patch("/workspace/projects/{$project->slug}", ['key' => 'egp'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($project->fresh()->key)->toBe('EGP')
        ->and(ProjectKeyAlias::query()->where('project_id', $project->id)->pluck('key')->all())->toBe(['EV'])
        ->and(ProjectKeys::resolve($project->organization_id, 'ev')?->id)->toBe($project->id)
        ->and(ProjectKeys::resolve($project->organization_id, 'egp')?->id)->toBe($project->id);
});

it('silently drops a key from the project manager', function () {
    $managerUser = User::factory()->create();
    $manager = Member::factory()->linkedTo($managerUser)->create();
    $project = Project::factory()->create(['title' => 'eGP v2']);
    $project->ensureTeam()->members()->attach($manager->id, ['role' => 'leader']);

    $this->actingAs($managerUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'eGP v3', 'key' => 'NEW'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($project->fresh()->key)->toBe('EV')
        ->and($project->fresh()->title)->toBe('eGP v3')
        ->and(ProjectKeyAlias::query()->count())->toBe(0);
});

it('rejects a malformed key or one already used in the organization', function () {
    [$user, $project] = keyedProject();
    $taken = Project::factory()->create(['organization_id' => $project->organization_id, 'key' => 'TAKEN']);
    ProjectKeyAlias::create(['project_id' => $taken->id, 'organization_id' => $project->organization_id, 'key' => 'OLD']);

    $this->actingAs($user)->from('/back')->patch("/workspace/projects/{$project->slug}", ['key' => 'S1'])
        ->assertSessionHasErrors('key');
    $this->actingAs($user)->from('/back')->patch("/workspace/projects/{$project->slug}", ['key' => 'taken'])
        ->assertSessionHasErrors(['key' => 'That key is already used in this organization.']);
    $this->actingAs($user)->from('/back')->patch("/workspace/projects/{$project->slug}", ['key' => 'old'])
        ->assertSessionHasErrors(['key' => 'That key is already used in this organization.']);

    expect($project->fresh()->key)->toBe('EV');
});

it('lets a project go back to a key it had before, retiring that alias', function () {
    [$user, $project] = keyedProject();

    $this->actingAs($user)->patch("/workspace/projects/{$project->slug}", ['key' => 'EGP'])->assertSessionHasNoErrors();
    $this->actingAs($user)->patch("/workspace/projects/{$project->slug}", ['key' => 'EV'])->assertSessionHasNoErrors();

    expect($project->fresh()->key)->toBe('EV')
        ->and(ProjectKeyAlias::query()->where('project_id', $project->id)->pluck('key')->all())->toBe(['EGP']);
});

it('obeys the same rules through the API', function () {
    [$user, $project] = keyedProject();
    $taken = Project::factory()->create(['organization_id' => $project->organization_id, 'key' => 'TAKEN']);
    Sanctum::actingAs($user, ['*']);

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => 'egp'])
        ->assertOk()->assertJsonPath('data.key', 'EGP')->assertJsonPath('data.can_change_key', true);
    expect(ProjectKeys::resolve($project->organization_id, 'EV')?->id)->toBe($project->id);

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => 'S1'])->assertUnprocessable()->assertJsonValidationErrors('key');
    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => $taken->key])
        ->assertUnprocessable()->assertJsonValidationErrors(['key' => 'That key is already used in this organization.']);

    $managerUser = User::factory()->create();
    $manager = Member::factory()->linkedTo($managerUser)->create();
    $project->ensureTeam()->members()->attach($manager->id, ['role' => 'leader']);
    Sanctum::actingAs($managerUser, ['*']);

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => 'NOPE', 'title' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.key', 'EGP')->assertJsonPath('data.can_change_key', false);
});

it('uses a key chosen by an admin when creating a project, and keeps it unique', function () {
    [$user, $project] = keyedProject('Something');

    $this->actingAs($user)->post('/workspace/projects', [
        'title' => 'Other thing',
        'key' => 'sm',
        'manager_id' => Member::factory()->create(['organization_id' => $project->organization_id])->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::query()->where('title', 'Other thing')->value('key'))->toBe('SM');

    $this->actingAs($user)->from('/back')->post('/workspace/projects', [
        'title' => 'Third',
        'key' => 'SM',
        'manager_id' => Member::factory()->create(['organization_id' => $project->organization_id])->id,
    ])->assertSessionHasErrors('key');
});

it('refuses, in the service, a key another project holds or held', function () {
    [, $project] = keyedProject();
    $other = Project::factory()->create(['organization_id' => $project->organization_id, 'key' => 'TAKEN']);
    ProjectKeyAlias::create(['project_id' => $other->id, 'organization_id' => $project->organization_id, 'key' => 'OLD']);
    $update = app(UpdateProjectService::class);

    foreach (['TAKEN', 'old'] as $key) {
        $result = $update->execute($project, ['key' => $key, 'title' => 'Changed']);

        expect($result->success)->toBeFalse()
            ->and($result->message)->toBe('That key is already used in this organization.');
    }

    expect($project->fresh()->key)->toBe('EV')
        ->and($project->fresh()->title)->not->toBe('Changed')
        ->and(ProjectKeys::resolve($project->organization_id, 'OLD')?->id)->toBe($other->id);
});

it('lets a super-admin, but not another office\'s admin, change a project\'s key', function () {
    [, $project] = keyedProject();
    $outsiderUser = User::factory()->create();
    Member::factory()->linkedTo($outsiderUser)->create(['organization_id' => Organization::factory()->create()->id, 'is_office_admin' => true]);

    $this->actingAs($outsiderUser)->patch("/workspace/projects/{$project->slug}", ['key' => 'HACK'])->assertForbidden();
    Sanctum::actingAs($outsiderUser, ['*']);
    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => 'HACK'])->assertForbidden();

    expect($project->fresh()->key)->toBe('EV')
        ->and(ProjectKeyAlias::query()->count())->toBe(0);

    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->actingAs($boss)->patch("/workspace/projects/{$project->slug}", ['key' => 'WEB'])->assertSessionHasNoErrors();
    expect($project->fresh()->key)->toBe('WEB');

    Sanctum::actingAs($boss, ['*']);
    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['key' => 'API'])
        ->assertOk()->assertJsonPath('data.key', 'API');
    expect($project->fresh()->key)->toBe('API')
        ->and(ProjectKeyAlias::query()->where('project_id', $project->id)->count())->toBe(2);
});
