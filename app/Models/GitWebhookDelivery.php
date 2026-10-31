<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed webhook delivery from a connection's forge, kept once by its
 * delivery UUID. ProcessGitDelivery applies it and sets `processed_at`;
 * events the tracker does not handle are stored already processed.
 *
 * @property int $id
 * @property int $git_connection_id
 * @property string $delivery
 * @property string $event
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $error
 * @property-read GitConnection $gitConnection
 */
class GitWebhookDelivery extends Model
{
    /** The events the organization webhook subscribes to and the tracker applies. */
    public const EVENTS = ['push', 'create', 'delete', 'pull_request', 'repository', 'action_run_success', 'action_run_failure'];

    /** How long a delivery that failed its signature marks the connection's hook for replacing. */
    public const BAD_SIGNATURE_DAYS = 3;

    /**
     * The cache key set when a delivery to the connection came with a wrong
     * signature: a secret changed on the forge, which the forge never shows
     * and its hook edit can't write back, so the reconcile replaces the hook.
     */
    public static function badSignatureKey(int $connectionId): string
    {
        return "git-webhook-bad-signature:{$connectionId}";
    }

    public $timestamps = false;

    protected $fillable = [
        'git_connection_id',
        'delivery',
        'event',
        'payload',
        'received_at',
        'processed_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }
}
