<?php

use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The migration that turns people's free-text titles into designations of
 * their organization, then drops the column. Run here against a column put
 * back for the purpose.
 */

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_31_170000_replace_member_titles_with_designations.php');
    $this->migration->down();

    $this->opmcm = Organization::factory()->create(['name' => 'OPMCM']);
    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $person = fn (Organization $organization, ?string $title, array $attributes = []) => tap(
        Member::factory()->create(['organization_id' => $organization->id, ...$attributes]),
        fn (Member $member) => DB::table('members')->where('id', $member->id)->update(['title' => $title]),
    );

    $this->director = Designation::factory()->create(['organization_id' => $this->sdoc->id, 'name' => 'Director', 'rank' => 10]);
    $this->advisor = $person($this->opmcm, 'Advisor');
    $this->sachib = $person($this->opmcm, 'Niji Sachib');
    $this->secondAdvisor = $person($this->opmcm, ' advisor ');
    $this->sdocAdvisor = $person($this->sdoc, 'Advisor');
    $this->sdocDirector = $person($this->sdoc, 'director');
    $this->designated = $person($this->sdoc, 'Engineer', ['designation_id' => $this->director->id]);
    $this->untitled = $person($this->sdoc, null);
    $this->blank = $person($this->sdoc, '  ');
    $this->outside = $person($this->sdoc, 'Executive Director', ['organization_id' => null]);
});

it('makes the title of someone with no designation a designation of their organization, and theirs', function () {
    $this->migration->up();

    $named = fn (Organization $organization) => Designation::query()->where('organization_id', $organization->id)->orderBy('name')->pluck('rank', 'name')->all();
    $designationOf = fn (Member $member): ?string => $member->fresh()->designation?->name;

    expect(Schema::hasColumn('members', 'title'))->toBeFalse()
        ->and($named($this->opmcm))->toEqual(['Advisor' => 100, 'Niji Sachib' => 100])
        ->and($named($this->sdoc))->toEqual(['Advisor' => 100, 'Director' => 10])
        ->and($designationOf($this->advisor))->toBe('Advisor')
        ->and($designationOf($this->sachib))->toBe('Niji Sachib')
        ->and($designationOf($this->secondAdvisor))->toBe('Advisor')
        ->and($designationOf($this->sdocAdvisor))->toBe('Advisor')
        ->and($designationOf($this->sdocDirector))->toBe('Director')
        ->and($designationOf($this->designated))->toBe('Director')
        ->and($designationOf($this->untitled))->toBeNull()
        ->and($designationOf($this->blank))->toBeNull()
        ->and($designationOf($this->outside))->toBeNull();
});
