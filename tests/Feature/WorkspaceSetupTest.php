<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_workspace_uses_the_published_svelte_login(): void
    {
        $this->get('/workspace')
            ->assertRedirect(route('workspace.login'));

        $this->get(route('workspace.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->component('Auth/Login'));
    }

    public function test_authenticated_user_can_open_the_workspace_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Dashboard')
                ->has('statuses')
                ->has('themeCatalogue'));
    }

    public function test_sanctum_api_login_issues_a_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson(route('api.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'PMOPM test',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_user_can_save_workspace_appearance_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('workspace.preferences.update'), [
                'theme' => 'dark',
                'email_notifications' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => 'dark',
            'email_notifications' => false,
        ]);
    }
}
