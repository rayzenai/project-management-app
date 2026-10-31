<?php

namespace App\Services\Structure\Concerns;

use App\Models\Designation;
use App\Models\Organization;

/**
 * A designation's name is unique in its organization whatever its case
 * ("Director" and "director" are one designation). Checked under a lock on
 * the organization row, so two people adding the same name at once cannot
 * both succeed.
 */
trait NamesDesignations
{
    protected function lockOrganization(int $organizationId): void
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
    }

    protected function nameTaken(int $organizationId, string $name, ?Designation $except = null): bool
    {
        return Designation::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->exists();
    }

    /**
     * @return array{name: string}
     */
    protected function takenError(string $name): array
    {
        return ['name' => "There is already a designation called {$name}."];
    }
}
