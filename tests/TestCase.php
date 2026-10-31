<?php

namespace Tests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The memos live for one request in production; a test is one too.
        // Ids repeat across tests, so a memo keyed by id must not survive.
        WorkspaceAccess::flush();
    }
}
