<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A summary email a person got on a day, one per kind and day (unique).
 * SendMorningEmails inserts it before the email goes, as the day's claim,
 * and deletes it again when the send fails.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property CarbonImmutable $sent_on
 * @property CarbonImmutable $sent_at
 * @property-read User $user
 */
class EmailDigest extends Model
{
    public const string MORNING = 'morning';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'kind',
        'sent_on',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
