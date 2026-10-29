<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * The OAuth client model (Passport::useClientModel). The only client is the
 * forge, made by `git:oidc-client`; Passport's client-management routes are
 * not registered, so nobody can add another.
 */
class OAuthClient extends Client
{
    /**
     * Our own forge: no consent screen.
     *
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
