<?php

namespace App\Services\Git;

use App\Models\GitConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small Forgejo API client on Laravel's HTTP client.
 */
class ForgejoClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public static function for(GitConnection $connection): self
    {
        return new self($connection->base_url, $connection->token);
    }

    /**
     * @return array{id: int, login: string}
     */
    public function currentUser(): array
    {
        $data = $this->get('/user');

        // A 200 that is not a Forgejo user (an HTML page, another app's JSON).
        if (! is_string($data['login'] ?? null) || $data['login'] === '') {
            throw new ForgejoException('The address did not answer like a Forgejo forge.', 404);
        }

        return ['id' => (int) ($data['id'] ?? 0), 'login' => $data['login']];
    }

    /**
     * @return array{is_owner: bool}
     */
    public function organizationPermissions(string $org, string $username): array
    {
        $data = $this->get('/users/'.rawurlencode($username).'/orgs/'.rawurlencode($org).'/permissions');

        return ['is_owner' => (bool) ($data['is_owner'] ?? false)];
    }

    /**
     * Takes the person out of the forge organization (and its teams). 204 and
     * 404 (already not a member, or no such user) both mean it is done.
     */
    public function removeOrganizationMember(string $org, string $username): void
    {
        try {
            $response = $this->request()->delete('/orgs/'.rawurlencode($org).'/members/'.rawurlencode($username));
        } catch (ConnectionException $e) {
            throw new ForgejoException('The forge could not be reached.', 0, $e);
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new ForgejoException('The forge answered '.$response->status().'.', $response->status());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->request()->get($path);
        } catch (ConnectionException $e) {
            throw new ForgejoException('The forge could not be reached.', 0, $e);
        }

        if ($response->failed()) {
            throw new ForgejoException('The forge answered '.$response->status().'.', $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/v1')
            ->withHeaders(['Authorization' => 'token '.$this->token])
            ->acceptJson()
            ->timeout(10);
    }
}
