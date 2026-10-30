<?php

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKeyAlias;

function runSdocKeysMigration(): void
{
    (require base_path('database/migrations/2026_10_31_140000_give_sdoc_projects_their_agreed_keys.php'))->up();
}

function sdocProject(Organization $org, string $title, string $key): Project
{
    return Project::factory()->create(['organization_id' => $org->id, 'title' => $title, 'key' => $key]);
}

it('gives SDOC projects their agreed keys and keeps the old ones as aliases', function () {
    $sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $egp = sdocProject($sdoc, 'eGP v2', 'EV');
    $hs = sdocProject($sdoc, 'Hello Sarkar', 'HS');

    runSdocKeysMigration();

    expect($egp->fresh()->key)->toBe('EGP')
        ->and(ProjectKeyAlias::query()->where('project_id', $egp->id)->pluck('key')->all())->toBe(['EV'])
        ->and($hs->fresh()->key)->toBe('HS')
        ->and(ProjectKeyAlias::query()->where('project_id', $hs->id)->count())->toBe(0);
});

it('leaves a hand-chosen key alone', function () {
    $sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $egp = sdocProject($sdoc, 'eGP v2', 'PROC');

    runSdocKeysMigration();

    expect($egp->fresh()->key)->toBe('PROC')
        ->and(ProjectKeyAlias::query()->count())->toBe(0);
});

it('skips a project whose agreed key is taken', function () {
    $sdoc = Organization::factory()->create(['name' => 'SDOC']);
    sdocProject($sdoc, 'Something Else', 'EGP');
    $egp = sdocProject($sdoc, 'eGP v2', 'EV');
    $giwms = sdocProject($sdoc, 'GIWMS v2', 'GV');

    runSdocKeysMigration();

    expect($egp->fresh()->key)->toBe('EV')
        ->and($giwms->fresh()->key)->toBe('GIWMS');
});

it('does nothing without SDOC', function () {
    $other = Organization::factory()->create(['name' => 'Elsewhere']);
    $egp = sdocProject($other, 'eGP v2', 'EV');

    runSdocKeysMigration();

    expect($egp->fresh()->key)->toBe('EV');
});

it('keeps its own copy of the agreed keys, so a seeder edit cannot change it', function () {
    $source = (string) file_get_contents(base_path('database/migrations/2026_10_31_140000_give_sdoc_projects_their_agreed_keys.php'));

    expect($source)->not->toContain('SdocProjectsSeeder::');

    $sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $agreed = [
        'SMS Gateway' => 'SG',
        'eGP v2' => 'EGP',
        'GIWMS v2' => 'GIWMS',
        'SuTRA' => 'SUTRA',
        'GIOMS' => 'GIOMS',
        'Hello Sarkar' => 'HS',
        'Nagarik App' => 'NAGARIK',
        'Open Source OS with Office Packages' => 'OSOP',
    ];
    $projects = collect($agreed)->map(fn (string $key, string $title): Project => Project::factory()->create([
        'organization_id' => $sdoc->id,
        'title' => $title,
    ]));

    runSdocKeysMigration();

    expect($projects->map(fn (Project $project): ?string => $project->fresh()->key)->all())->toBe($agreed);
});
