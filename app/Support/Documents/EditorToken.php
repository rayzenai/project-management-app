<?php

namespace App\Support\Documents;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Throwable;

/**
 * The JWTs the tracker and ONLYOFFICE exchange, signed with the secret they
 * share (HS256, config documents.jwt_secret). The tracker signs each editor
 * config; the editor signs every request it makes to the tracker, putting
 * the request in the token's `payload` and naming it in `operation`
 * (`download` for a file, `callback` for a save, since 10.0).
 */
final class EditorToken
{
    public const OPERATION_DOWNLOAD = 'download';

    public const OPERATION_CALLBACK = 'callback';

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $claims): string
    {
        return JWT::encode($claims, self::secret(), 'HS256');
    }

    /**
     * The `payload` of a request the editor made for this operation, from its
     * `Authorization: Bearer` header; null when the token is missing, signed
     * with another secret, expired, or for another operation.
     *
     * @return array<string, mixed>|null
     */
    public static function payloadFrom(Request $request, string $operation): ?array
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '' || ! config('documents.enabled')) {
            return null;
        }

        $leeway = JWT::$leeway;
        JWT::$leeway = 60;

        try {
            $claims = json_decode((string) json_encode(JWT::decode($token, new Key(self::secret(), 'HS256'))), true);
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$leeway = $leeway;
        }

        if (! is_array($claims) || ($claims['operation'] ?? null) !== $operation || ! is_array($claims['payload'] ?? null)) {
            return null;
        }

        return $claims['payload'];
    }

    private static function secret(): string
    {
        return (string) config('documents.jwt_secret');
    }
}
