<?php

namespace App\Support\Git\Oidc;

use App\Models\User;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;

/**
 * Resolves the user an access token was issued to, for the id_token.
 * Registered as `openid.repositories.identity`.
 */
class IdentityRepository implements IdentityRepositoryInterface
{
    public function getByIdentifier(string $identifier): IdentityEntityInterface
    {
        return new IdentityEntity(User::query()->findOrFail($identifier));
    }
}
