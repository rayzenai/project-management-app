<?php

namespace App\Console\Commands;

use App\Models\OAuthClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Makes the forge's OIDC client: one confidential authorization-code client
 * named Forgejo, allowed to send people back to exactly one redirect URI.
 * Run again to move it to a new URI; it keeps its id and gets a new secret
 * (secrets are stored hashed, so the old one cannot be shown again).
 */
#[Signature('git:oidc-client
    {redirect_uri : The forge callback, e.g. https://git.sdoc.gov.np/user/oauth2/sdoc-tasks/callback}')]
#[Description('Create (or reuse) the Forgejo OIDC client and print its id and secret')]
class CreateForgeOidcClient extends Command
{
    public const NAME = 'Forgejo';

    public function handle(ClientRepository $clients): int
    {
        $redirectUri = (string) $this->argument('redirect_uri');

        if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($redirectUri, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('The redirect URI must be a full http(s) URL.');

            return self::FAILURE;
        }

        $client = OAuthClient::query()->where('name', self::NAME)->where('revoked', false)->first();

        if ($client === null) {
            $client = $clients->createAuthorizationCodeGrantClient(self::NAME, [$redirectUri]);
            $this->info('Created the '.self::NAME.' client.');
        } else {
            $client->forceFill(['redirect_uris' => [$redirectUri], 'secret' => Str::random(40)])->save();
            $this->info('Reused the '.self::NAME.' client with a new secret; update the forge with it.');
        }

        $this->line('Redirect URI: '.$redirectUri);
        $this->line('Client ID: '.$client->getKey());
        $this->line('Client secret: '.$client->plainSecret);
        $this->warn('Copy the secret now: it is stored hashed and cannot be shown again.');

        return self::SUCCESS;
    }
}
