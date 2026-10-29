<?php

use App\Models\Organization;
use App\Models\Project;
use Database\Seeders\SdocProjectsSeeder;

it('creates SDOC and its projects, each with its own team and no manager yet', function () {
    $this->seed(SdocProjectsSeeder::class);

    $sdoc = Organization::query()->where('name', 'SDOC')->firstOrFail();
    $projects = Project::query()->where('organization_id', $sdoc->id)->with('teams')->orderBy('id')->get();

    expect($projects->pluck('title')->all())->toBe(SdocProjectsSeeder::PROJECTS)
        ->and($projects->every(fn (Project $p): bool => $p->team() !== null && $p->team()->name === $p->title && $p->team()->organization_id === $sdoc->id))->toBeTrue()
        ->and($projects->every(fn (Project $p): bool => $p->manager() === null && ! $p->is_public))->toBeTrue()
        ->and($projects->pluck('slug')->all())->toContain('sms-gateway', 'egp-v2', 'sutra', 'open-source-os-with-office-packages');
});

it('can run again without duplicating or touching anything', function () {
    $this->seed(SdocProjectsSeeder::class);
    $sdoc = Organization::query()->where('name', 'SDOC')->firstOrFail();
    $renamed = Project::query()->where('title', 'SuTRA')->firstOrFail();
    $renamed->update(['description' => 'Kept as is']);

    $this->seed(SdocProjectsSeeder::class);

    expect(Organization::query()->where('name', 'SDOC')->count())->toBe(1)
        ->and(Project::query()->where('organization_id', $sdoc->id)->count())->toBe(8)
        ->and($renamed->fresh()->description)->toBe('Kept as is');
});

it('leaves another organization\'s project with the same name alone', function () {
    $elsewhere = Project::factory()->create(['title' => 'SMS Gateway', 'slug' => 'sms-gateway']);

    $this->seed(SdocProjectsSeeder::class);

    $sdocs = Project::query()->where('title', 'SMS Gateway')->where('id', '!=', $elsewhere->id)->firstOrFail();

    expect($elsewhere->fresh()->organization_id)->not->toBe($sdocs->organization_id)
        ->and($sdocs->slug)->toBe('sms-gateway-2');
});
