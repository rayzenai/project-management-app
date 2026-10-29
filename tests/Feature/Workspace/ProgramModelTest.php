<?php

use App\Models\Member;
use App\Models\Program;
use App\Models\Project;

it('slugs a program from its name and keeps slugs unique', function () {
    $first = Program::factory()->create(['name' => 'Digital Services']);
    $second = Program::factory()->create(['name' => 'Digital Services']);

    expect($first->slug)->toBe('digital-services')
        ->and($second->slug)->toBe('digital-services-2');
});

it('collects projects', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'organization_id' => $program->organization_id,
        'program_id' => $program->getKey(),
    ]);

    expect($program->projects->pluck('id')->all())->toBe([$project->getKey()])
        ->and($project->program->is($program))->toBeTrue()
        ->and($program->organization->programs->first()->is($program))->toBeTrue()
        ->and($program->manager->organization_id)->toBe($program->organization_id);
});

it('lets its projects go when the program is deleted', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'organization_id' => $program->organization_id,
        'program_id' => $program->getKey(),
    ]);

    $program->delete();

    expect($project->fresh())->not->toBeNull()
        ->and($project->fresh()->program_id)->toBeNull();
});

it('keeps the program when its manager is pruned', function () {
    $program = Program::factory()->create();
    $manager = Member::query()->findOrFail($program->manager_id);

    $manager->delete();
    $manager->forceDelete();

    expect($program->fresh())->not->toBeNull()
        ->and($program->fresh()->manager_id)->toBeNull();
});
