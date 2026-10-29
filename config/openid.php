<?php

use App\Support\Git\Oidc\IdentityRepository;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/*
 * The tracker as the OpenID Connect provider for the forge (Forgejo), its
 * only client. Every claim travels in the id_token; the userinfo endpoint
 * stays off. See App\Support\Git\Oidc\IdentityEntity for the claims.
 */
return [
    'passport' => [

        /**
         * Place your Passport and OpenID Connect scopes here.
         * To receive an `id_token, you should at least provide the openid scope.
         * Forgejo asks for `openid profile email git`.
         */
        'tokens_can' => [
            'openid' => 'Enable OpenID Connect',
            'profile' => 'Information about your profile',
            'email' => 'Information about your email address',
            'git' => 'The forge organizations you may enter, and whether you administer the forge',
        ],
    ],

    /**
     * Place your custom claim sets here.
     */
    'custom_claim_sets' => [
        'git' => [
            'git_orgs',
            'groups',
        ],
    ],

    /**
     * You can override the repositories below.
     */
    'repositories' => [
        'identity' => IdentityRepository::class,
    ],

    'routes' => [
        /**
         * When set to true, this package will expose the OpenID Connect Discovery endpoint.
         *  - /.well-known/openid-configuration
         */
        'discovery' => true,
        /**
         * When set to true, this package will expose the JSON Web Key Set endpoint.
         */
        'jwks' => true,
        /**
         * Optional URL to change the JWKS path to align with your custom Passport routes.
         * Defaults to /oauth/jwks
         */
        'jwks_url' => '/oauth/jwks',
        /**
         * Off: every claim travels in the id_token.
         */
        'userinfo' => false,
    ],

    /**
     * Settings for the discovery endpoint
     */
    'discovery' => [
        /**
         * Hide scopes that aren't from the OpenID Core spec from the Discovery,
         * default = false (all scopes are listed)
         */
        'hide_scopes' => false,
    ],

    /**
     * The signer to be used
     */
    'signer' => Sha256::class,

    /**
     * Optional associative array that will be used to set headers on the JWT
     */
    'token_headers' => [],

    /**
     * By default, microseconds are included.
     */
    'use_microseconds' => true,

    /**
     * The issuer (`iss`, and `issuer` in discovery) is the app URL, so it
     * stays the same behind the proxy whatever host the request names. Same
     * env and default as config('app.url').
     */
    'issuedBy' => env('APP_URL', 'http://localhost'),

    /**
     * Discovery forces https on its endpoint URLs when the app is served
     * over https (production); a plain-http local app keeps http.
     */
    'forceHttps' => str_starts_with((string) env('APP_URL', 'http://localhost'), 'https://'),
];
