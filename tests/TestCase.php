<?php

namespace Tests;

use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
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

    /**
     * A test's session lives across its requests, but in production another
     * person only signs in on a fresh session (logout ends it; the login
     * routes are for guests). So acting as someone never keeps the password
     * hash AuthenticateSession stored for the previous person, which it
     * would read as a changed password and sign them out.
     *
     * @param  string|null  $guard
     * @return $this
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $this->app['session']->forget('password_hash_web');

        return parent::be($user, $guard);
    }
}
