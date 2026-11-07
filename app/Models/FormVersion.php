<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form's questions as published, never changed once written. A response
 * keeps the version it was filled in on.
 *
 * @property int $id
 * @property int $form_id
 * @property int $number
 * @property array<string, mixed> $schema
 * @property int|null $published_by
 * @property CarbonImmutable $published_at
 * @property-read Form $form
 */
class FormVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['form_id', 'number', 'schema', 'published_by', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class)->withTrashed();
    }
}
