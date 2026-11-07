<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A respondent's answers before Submit, saved as they type (`respondent_key`
 * as on FormResponse, or `t:{member}` for a builder's test). Only the
 * respondent sees it; for a data call, everyone of that organization does.
 *
 * @property int $id
 * @property int $form_id
 * @property string $respondent_key
 * @property int|null $form_version_id
 * @property array<string, mixed> $answers
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $updater
 */
class FormDraft extends Model
{
    protected $fillable = ['form_id', 'respondent_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
