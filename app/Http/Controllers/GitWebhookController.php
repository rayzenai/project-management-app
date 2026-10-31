<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGitDelivery;
use App\Models\GitConnection;
use App\Models\GitWebhookDelivery;
use App\Support\Background;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The forge's organization webhook, server to server: no session, no CSRF,
 * no route binding. A delivery counts only when its body is signed with the
 * connection's webhook secret; it is stored once by its delivery UUID and
 * applied by ProcessGitDelivery. Nothing here echoes the signature or the
 * secret.
 */
class GitWebhookController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __invoke(Request $request, string $connection): JsonResponse
    {
        // The cast keeps an absurdly long id inside bigint.
        $gitConnection = GitConnection::query()->find((int) $connection);

        if ($gitConnection === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ((int) $request->headers->get('Content-Length') > self::MAX_BYTES || strlen($body = $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $secret = (string) $gitConnection->webhook_secret;
        $signature = $request->headers->get('X-Forgejo-Signature') ?? $request->headers->get('X-Gitea-Signature');

        if ($secret === '' || $signature === null || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            // Signed, but not with the stored secret: the nightly reconcile
            // replaces the hook (anyone could send one, which costs only that).
            if ($secret !== '' && $signature !== null) {
                Cache::put(GitWebhookDelivery::badSignatureKey($gitConnection->id), true, now()->addDays(GitWebhookDelivery::BAD_SIGNATURE_DAYS));
            }

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $uuid = (string) $request->headers->get('X-Forgejo-Delivery');
        $payload = json_decode($body, true);

        if (! Str::isUuid($uuid) || ! is_array($payload)) {
            return response()->json(['message' => 'Not a forge delivery.'], 400);
        }

        $event = (string) $request->headers->get('X-Forgejo-Event');

        $delivery = GitWebhookDelivery::query()->createOrFirst(['delivery' => $uuid], [
            'git_connection_id' => $gitConnection->id,
            'event' => $event,
            'payload' => $payload,
            'received_at' => now(),
            'processed_at' => in_array($event, GitWebhookDelivery::EVENTS, true) ? null : now(),
        ]);

        // A redelivery of one still unprocessed (its run failed, or the queue
        // was down) is queued again; the job applies a delivery only once. A
        // failed run is on the delivery; with the queue down, the delivery
        // waits for a redelivery or the reconcile.
        if ($delivery->processed_at === null) {
            Background::dispatch(new ProcessGitDelivery($delivery));
        }

        return $delivery->wasRecentlyCreated
            ? response()->json(['message' => 'Received.'], 202)
            : response()->json(['message' => 'Already received.']);
    }
}
