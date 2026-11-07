<?php

namespace App\Models;

use App\Support\Forms\FormCodes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's response to a form or, for a data call, one organization's
 * (`respondent_key` `m:{member}` or `o:{organization}`, unique per form;
 * null for the builder's test responses). `sender` is who sent it as they
 * were then (name, designation, organization); `answers` are by question
 * id, as FormAnswers::check kept them. Never deleted in this round.
 *
 * @property int $id
 * @property int $form_id
 * @property int $form_version_id
 * @property string $reference
 * @property string|null $respondent_key
 * @property int|null $member_id
 * @property int|null $organization_id
 * @property array{name: string, designation: string|null, organization: string|null} $sender
 * @property array<string, mixed> $answers
 * @property string|null $stage
 * @property bool $is_test
 * @property int|null $task_id
 * @property int|null $submitted_by
 * @property int|null $updated_by
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Form $form
 * @property-read FormVersion $version
 * @property-read Member|null $member
 * @property-read Organization|null $organization
 * @property-read Task|null $task
 */
class FormResponse extends Model
{
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender' => 'array',
            'answers' => 'array',
            'is_test' => 'boolean',
            'submitted_at' => 'datetime',
            'task_id' => 'integer',
        ];
    }

    /** A reference no response has had. */
    public static function newReference(): string
    {
        do {
            $reference = FormCodes::make(8);
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class)->withTrashed();
    }

    /**
     * @return BelongsTo<FormVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Newest first.
     *
     * @return HasMany<FormResponseEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FormResponseEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<FormFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(FormFile::class);
    }
}
