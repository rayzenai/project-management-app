<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property string $slug
 * @property string|null $short_title
 * @property string $title
 * @property string|null $description
 * @property CarbonImmutable|null $deadline_at
 * @property string $status
 * @property string $priority
 * @property string|null $status_note
 * @property string|null $source_url
 * @property array<int, mixed>|null $source_links
 * @property CarbonImmutable|null $status_updated_at
 * @property CarbonImmutable|null $completed_at
 * @property int $progress
 * @property array<string, mixed>|null $metadata
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = [
        'project_id',
        'slug',
        'title',
        'short_title',
        'description',
        'deadline_at',
        'status',
        'priority',
        'progress',
        'status_note',
        'source_url',
        'source_links',
        'status_updated_at',
        'completed_at',
        'metadata',
        // Plan-specific fields now stored in `metadata` but kept fillable for
        // back-compat with factories, seeders, and form requests. Mass-assigning
        // them routes through the Attribute mutators below.
        'item_number',
        'title_np',
        'description_np',
        'category',
        'deadline_type',
        'responsible_ministry',
    ];

    protected $appends = ['category_label', 'category_color', 'status_label', 'status_color', 'deadline_label', 'days_relative_label', 'freshness', 'is_late'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline_at' => 'date',
            'status_updated_at' => 'datetime',
            'completed_at' => 'datetime',
            'progress' => 'integer',
            'source_links' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $item) {
            if (empty($item->slug)) {
                $item->slug = $item->item_number.'-'.Str::slug($item->title);
            }

            if (empty($item->deadline_at) && $item->deadline_type) {
                $days = config("government.deadline_types.{$item->deadline_type}.days");
                if (is_int($days)) {
                    $item->deadline_at = CarbonImmutable::parse(config('government.oath_date'))->addDays($days);
                }
            }

            if ($item->isDirty('status')) {
                if ($item->isComplete() && $item->completed_at === null) {
                    $item->completed_at = now();
                } elseif (! $item->isComplete()) {
                    $item->completed_at = null;
                }
            }
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ProjectAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    /**
     * @return HasMany<ProjectNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class);
    }

    /**
     * @return HasMany<ProjectContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ProjectContact::class);
    }

    /**
     * @return HasMany<Subtask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class);
    }

    /**
     * @return HasMany<ProjectActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }

    /**
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * Tasks that are "mine" — either explicitly assigned to the member behind
     * the given login, or in one of that member's coordinator categories.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeMine(Builder $query, mixed $user): Builder
    {
        $member = Member::forUser($user);
        $categories = $member->coordinator_categories ?? [];

        return $query->where(function (Builder $q) use ($member, $categories) {
            $q->whereHas('assignments', fn (Builder $a) => $a->where('member_id', $member->id));

            if (! empty($categories)) {
                $q->orWhereIn(DB::raw("metadata->>'category'"), $categories);
            }
        });
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Statuses flagged `is_complete` in config/project-management.php — the single
     * source of truth for "this task is finished".
     *
     * @return list<string>
     */
    public static function completeStatuses(): array
    {
        $statuses = collect((array) config('project-management.statuses'))
            ->filter(fn (array $meta): bool => (bool) ($meta['is_complete'] ?? false))
            ->keys()
            ->map(fn (int|string $status): string => (string) $status);

        return array_values($statuses->all());
    }

    public function isComplete(): bool
    {
        return in_array($this->status, self::completeStatuses(), true);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeComplete(Builder $query): Builder
    {
        return $query->whereIn('status', self::completeStatuses());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::completeStatuses());
    }

    /**
     * Restrict to tasks whose project is not archived. Used by every active-work
     * surface (My Workspace, dashboard, search) so archived projects go dormant.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForActiveProjects(Builder $query): Builder
    {
        return $query->whereHas('project', fn (Builder $q) => $q->whereNull('archived_at'));
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->whereRaw("metadata->>'category' = ?", [$category]);
    }

    /**
     * Order by the JSON-stored item_number numerically.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOrderByItemNumber(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return $query->orderByRaw("CAST(metadata->>'item_number' AS INTEGER) {$direction} NULLS LAST");
    }

    /**
     * Read a single metadata key.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * @return Attribute<int|null, mixed>
     */
    protected function itemNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->metadata['item_number']) ? (int) $this->metadata['item_number'] : null,
            set: fn ($value) => $this->writeMetadata('item_number', $value === null ? null : (int) $value),
        );
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function category(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['category'] ?? null,
            set: fn ($value) => $this->writeMetadata('category', $value),
        );
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function deadlineType(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['deadline_type'] ?? null,
            set: fn ($value) => $this->writeMetadata('deadline_type', $value),
        );
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function responsibleMinistry(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['responsible_ministry'] ?? null,
            set: fn ($value) => $this->writeMetadata('responsible_ministry', $value),
        );
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function titleNp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['title_np'] ?? null,
            set: fn ($value) => $this->writeMetadata('title_np', $value),
        );
    }

    /**
     * @return Attribute<string|null, mixed>
     */
    protected function descriptionNp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['description_np'] ?? null,
            set: fn ($value) => $this->writeMetadata('description_np', $value),
        );
    }

    /**
     * Write a single key into the metadata JSON, preserving the other keys.
     * Returns the raw column value (JSON text) for Eloquent to merge into the
     * attribute bag; the `array` cast decodes it on read.
     *
     * @return array{metadata: string|false|null}
     */
    private function writeMetadata(string $key, mixed $value): array
    {
        $current = $this->attributes['metadata'] ?? null;
        $decoded = is_string($current) ? (json_decode($current, true) ?: []) : ($current ?? []);

        if ($value === null) {
            unset($decoded[$key]);
        } else {
            $decoded[$key] = $value;
        }

        return [
            'metadata' => $decoded === [] ? null : json_encode((object) $decoded),
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function categoryLabel(): Attribute
    {
        return Attribute::get(fn () => config("government.categories.{$this->category}.label", ucfirst((string) $this->category)));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function categoryColor(): Attribute
    {
        return Attribute::get(fn () => config("government.categories.{$this->category}.color", '#6B7280'));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => config("project-management.statuses.{$this->status}.label", ucfirst((string) $this->status)));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => config("project-management.statuses.{$this->status}.color", '#9CA3AF'));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function deadlineLabel(): Attribute
    {
        return Attribute::get(fn () => config("government.deadline_types.{$this->deadline_type}.label", $this->deadline_type));
    }

    /**
     * Freshness of the latest status update.
     *
     * @return Attribute<array{label: ?string, days_ago: ?int, bucket: string}, never>
     */
    protected function freshness(): Attribute
    {
        return Attribute::make(get: fn () => $this->resolveFreshness());
    }

    /**
     * @return array{label: ?string, days_ago: ?int, bucket: string}
     */
    private function resolveFreshness(): array
    {
        if (! $this->status_updated_at) {
            return [
                'label' => null,
                'days_ago' => null,
                'bucket' => 'cold',
            ];
        }

        $now = Carbon::now();
        $hours = (int) $this->status_updated_at->diffInHours($now, false);
        $hours = abs($hours);
        $days = (int) floor($hours / 24);

        if ($hours <= 48) {
            $bucket = 'moved';
            $label = 'moved';
        } elseif ($days <= 7) {
            $bucket = 'fresh';
            $label = null;
        } elseif ($days > 30) {
            $bucket = 'cold';
            $label = 'cold';
        } elseif ($days > 14) {
            $bucket = 'stalled';
            $label = 'stalled';
        } else {
            $bucket = 'fresh';
            $label = null;
        }

        return [
            'label' => $label,
            'days_ago' => $days,
            'bucket' => $bucket,
        ];
    }

    /**
     * Human-readable "due in 30d" / "30d overdue" / "due today" / "rolling".
     *
     * @return Attribute<string, never>
     */
    protected function daysRelativeLabel(): Attribute
    {
        return Attribute::get(fn () => $this->resolveDaysRelativeLabel());
    }

    private function resolveDaysRelativeLabel(): string
    {
        if (! $this->deadline_at) {
            return $this->deadline_type === 'rolling' ? 'rolling' : 'no date';
        }

        $today = Carbon::today();
        $diff = (int) $today->diffInDays($this->deadline_at, false);

        if ($diff === 0) {
            return 'due today';
        }
        if ($diff > 0) {
            return 'due in '.$diff.'d';
        }

        return abs($diff).'d overdue';
    }

    /**
     * Whether the task is late: an incomplete task past its deadline, or a
     * completed task finished after its deadline. Derived — never a status.
     *
     * @return Attribute<bool, never>
     */
    protected function isLate(): Attribute
    {
        return Attribute::get(function (): bool {
            if (! $this->deadline_at) {
                return false;
            }

            if ($this->isComplete()) {
                return $this->completed_at !== null
                    && $this->completed_at->startOfDay()->gt($this->deadline_at->startOfDay());
            }

            return $this->deadline_at->lt(Carbon::today());
        });
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
