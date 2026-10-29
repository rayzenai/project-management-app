<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows the guide to anyone signed in', function () {
    $this->actingAs(User::factory()->withMember()->create())
        ->get('/workspace/guide')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Guide'));
});

it('sends visitors who are not signed in to the login page', function () {
    $this->get('/workspace/guide')->assertRedirect();
});
