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
    public const EVENTS = ['push', 'create', 'delete', 'pull_request', 'repository'];

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
