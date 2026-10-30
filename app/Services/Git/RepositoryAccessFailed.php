<?php

namespace App\Services\Git;

/**
 * A forge call of the access sync failed, saying which one: `put` or
 * `delete` of a collaborator (with their login) or `patch` of the `main`
 * rule. It keeps the status and cause of the ForgejoException it stands
 * for, and never carries the token.
 */
class RepositoryAccessFailed extends ForgejoException
{
    public function __construct(ForgejoException $e, public readonly string $operation, public readonly ?string $login = null)
    {
        parent::__construct(
            rtrim($e->getMessage(), '.')." ({$operation}".($login !== null ? " {$login}" : '').').',
            $e->status,
            $e->getPrevious(),
        );
    }

    /**
     * For a failure log: the operation and, for a collaborator, the login.
     *
     * @return array{operation: string, login?: string}
     */
    public function context(): array
    {
        return $this->login === null
            ? ['operation' => $this->operation]
            : ['operation' => $this->operation, 'login' => $this->login];
    }
}
