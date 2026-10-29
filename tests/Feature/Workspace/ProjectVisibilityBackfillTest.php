<?php

use App\Models\Project;
use Database\Factories\OrganizationFactory;
use Illuminate\Support\Facades\DB;

it('the backfill migration marks pre-existing projects public', function () {
    DB::table('projects')->insert([
        'organization_id' => OrganizationFactory::defaultId(),
        'slug' => 'legacy',
        'key' => 'L',
        'title' => 'Legacy',
        'is_public' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_06_17_000001_make_existing_projects_public.php');
    $migration->up();

    expect(Project::query()->where('slug', 'legacy')->first()->is_public)->toBeTrue();
});
