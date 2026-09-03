<?php

namespace Tests\Feature\Workspace;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the edit surfaces exposed on the project show page and the team panel.
 * The update routes already existed; these assert the props the UI gates on are
 * actually present, and that every field the forms submit round-trips.
 */
class EditAffordancesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function superAdmin(): User
    {
        $email = 'super@example.test';
        config()->set('project-management.super_admins', [$email]);

        return User::factory()->create(['email' => $email]);
    }

    public function test_project_show_exposes_the_flag_the_edit_button_is_gated_on(): void
    {
        $project = Project::factory()->create(['title' => 'IDMC Plan']);

        $this->actingAs($this->superAdmin())
            ->get(route('workspace.projects.show', ['project' => $project->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Projects/Show')
                ->where('project.can_manage_access', true)
            );
    }

    public function test_super_admin_can_update_every_editable_project_field(): void
    {
        $project = Project::factory()->create(['title' => 'IDMC Plan']);

        $this->actingAs($this->superAdmin())
            ->patch(route('workspace.projects.update', ['project' => $project->slug]), [
                'title' => 'IDMC Plan 2027',
                'title_np' => 'आईडीएमसी योजना',
                'description' => 'Updated description.',
                'description_np' => 'अद्यावधिक विवरण।',
                'is_public' => true,
            ])
            ->assertRedirect();

        $project->refresh();

        $this->assertSame('IDMC Plan 2027', $project->title);
        $this->assertSame('आईडीएमसी योजना', $project->title_np);
        $this->assertSame('Updated description.', $project->description);
        $this->assertSame('अद्यावधिक विवरण।', $project->description_np);
        $this->assertTrue((bool) $project->is_public);
    }

    public function test_a_user_who_cannot_manage_the_project_is_not_offered_the_edit_button(): void
    {
        $project = Project::factory()->create(['title' => 'IDMC Plan', 'is_public' => true]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('workspace.projects.show', ['project' => $project->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('project.can_manage_access', false)
            );

        $this->actingAs($outsider)
            ->patch(route('workspace.projects.update', ['project' => $project->slug]), ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('IDMC Plan', $project->refresh()->title);
    }

    public function test_super_admin_can_update_team_name_and_description(): void
    {
        $team = Team::create(['name' => 'IT', 'description' => 'Original.']);

        $this->actingAs($this->superAdmin())
            ->patch(route('workspace.teams.update', ['team' => $team->id]), [
                'name' => 'IT & Digital',
                'description' => 'Runs the systems.',
            ])
            ->assertRedirect();

        $team->refresh();

        $this->assertSame('IT & Digital', $team->name);
        $this->assertSame('Runs the systems.', $team->description);
    }

    public function test_non_super_admin_cannot_update_a_team(): void
    {
        $team = Team::create(['name' => 'IT']);

        $this->actingAs(User::factory()->create())
            ->patch(route('workspace.teams.update', ['team' => $team->id]), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('IT', $team->refresh()->name);
    }
}
