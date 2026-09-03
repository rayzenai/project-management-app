<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lets another system drive the /api/v1 workspace API without going through
 * the email + password login: mint a long-lived Sanctum token for the user
 * (typically a dedicated service account) it should act as.
 */
#[Signature('workspace:api-token
    {email : Email of the user the token acts as}
    {--name=integration : Token name, shown in personal_access_tokens}
    {--expires-days= : Days until the token expires (never when omitted)}
    {--revoke : Revoke every token with this name for the user instead of issuing one}')]
#[Description('Issue or revoke a Sanctum API token so an external system can use the workspace API')]
class IssueApiToken extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $revoked = $user->tokens()->where('name', $name)->delete();

            $this->info("Revoked {$revoked} token(s) named [{$name}] for {$email}.");

            return self::SUCCESS;
        }

        $expiresAt = $this->expiresAt();

        $token = $user->createToken($name, ['*'], $expiresAt)->plainTextToken;

        $this->info("API token [{$name}] issued for {$email}"
            .($expiresAt === null ? ' (never expires).' : ", expires {$expiresAt->toDateString()}."));
        $this->line('Send it as: Authorization: Bearer <token>');
        $this->newLine();
        $this->line($token);

        return self::SUCCESS;
    }

    private function expiresAt(): ?CarbonInterface
    {
        $days = $this->option('expires-days');

        if ($days === null || $days === '') {
            return null;
        }

        return now()->addDays((int) $days);
    }
}
