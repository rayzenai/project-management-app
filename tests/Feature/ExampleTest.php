<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_my_work(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/workspace/my');
    }
}
