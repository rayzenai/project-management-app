<?php

namespace App\Support\Git\Oidc;

use App\Models\Organization;
use App\Models\User;
use App\Support\Git\GitAccess;
use App\Support\Git\GitUsernames;
use App\Support\WorkspaceAccess;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

/**
 * The person an id_token is about. `sub` is the user id (the package sets it
 * from the identifier); the openid package keeps only the claims whose scope
 * was granted: profile (name, preferred_username), email (email,
 * email_verified) and git (git_orgs, groups; see config/openid.php).
 */
class IdentityEntity implements IdentityEntityInterface
{
    use EntityTrait;

    public function __construct(private readonly User $user)
    {
        $this->setIdentifier((string) $user->id);
    }

    /**
     * `git_orgs` is left out when the person may enter no forge organization
     * (say, deactivated between the code and the token, or on a refresh), so
     * the forge's required-claim check refuses them.
     *
     * @param  string[]  $scopes
     * @return array{email: string, email_verified: true, name: string, preferred_username: string, groups: list<string>, git_orgs?: non-empty-list<string>}
     */
    public function getClaims(array $scopes = []): array
    {
        $claims = [
            'email' => $this->user->email,
            'email_verified' => true,
            'name' => $this->user->name,
            'preferred_username' => GitUsernames::for($this->user),
            'groups' => WorkspaceAccess::isSuperAdmin($this->user) ? ['forge-admins'] : [],
        ];

        $gitOrgs = array_values(GitAccess::organizationsFor($this->user)
            ->map(fn (Organization $organization): ?string => $organization->gitConnection?->owner)
            ->filter()
            ->all());

        if ($gitOrgs !== []) {
            $claims['git_orgs'] = $gitOrgs;
        }

        return $claims;
    }
}
