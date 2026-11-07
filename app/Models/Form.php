<?php

namespace App\Models;

use App\Support\Forms\FormCodes;
use App\Support\Forms\FormSchema;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A form an office built, in one of its projects
 * (docs/superpowers/specs/2026-11-07-forms-design.md). Its questions are the
 * `draft` (FormSchema) and the versions published from it; `settings` holds
 * the rest (editing after Submit, the review page, stages, tasks). Who may
 * build it and see its responses is {@see WorkspaceAccess::formAccess()},
 * who may fill it in {@see WorkspaceAccess::canAnswerForm()}.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by
 * @property string $code
 * @property string $title
 * @property string $status
 * @property CarbonImmutable|null $opens_at
 * @property CarbonImmutable|null $closes_at
 * @property int|null $response_limit
 * @property string $respondents
 * @property string $answering
 * @property array<string, mixed> $settings
 * @property array<string, mixed> $draft
 * @property int|null $published_version_id
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read FormVersion|null $publishedVersion
 */
class Form extends Model
{
    use SoftDeletes;

    public const TITLE_MAX = 200;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** Access levels, also a share's permission. */
    public const ACCESS_EDIT = 'edit';

    public const ACCESS_RESPONSES = 'responses';

    public const ACCESSES = [self::ACCESS_RESPONSES, self::ACCESS_EDIT];

    /** Who fills it in, besides the other organizations chosen. */
    public const RESPONDENTS_EVERYONE = 'everyone';

    public const RESPONDENTS_TEAM = 'team';

    public const RESPONDENTS_PEOPLE = 'people';

    public const RESPONDENTS = [self::RESPONDENTS_EVERYONE, self::RESPONDENTS_TEAM, self::RESPONDENTS_PEOPLE];

    /** One response per person, or one per organization (a data call). */
    public const ANSWERING_PERSON = 'person';

    public const ANSWERING_ORGANIZATION = 'organization';

    /** The stages a new form starts with. */
    public const DEFAULT_STAGES = [
        ['id' => 'new', 'name' => 'New'],
        ['id' => 'review', 'name' => 'In review'],
        ['id' => 'done', 'name' => 'Done'],
    ];

    protected $fillable = ['title'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'draft' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'response_limit' => 'integer',
            'published_version_id' => 'integer',
        ];
    }

    /**
     * A new form's settings.
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return ['allow_edit' => true, 'review_page' => true, 'notify' => true, 'stages' => self::DEFAULT_STAGES, 'tasks' => null];
    }

    /** A code no form has had, for its link. */
    public static function newCode(): string
    {
        do {
            $code = FormCodes::make(8);
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<FormVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderBy('number');
    }

    /**
     * @return BelongsTo<FormVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'published_version_id');
    }

    /**
     * @return HasMany<FormShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(FormShare::class);
    }

    /**
     * The chosen people, when it is for chosen people.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function respondentMembers(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'form_respondent_members');
    }

    /**
     * Other organizations it is sent to, whoever else fills it in.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function respondentOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'form_respondent_organizations');
    }

    /**
     * @return HasMany<FormResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    /**
     * @return HasMany<FormDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(FormDraft::class);
    }

    /**
     * @return HasMany<FormFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(FormFile::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings ?? []) ? $this->settings[$key] : $default;
    }

    /**
     * The stages responses move through, in order.
     *
     * @return list<array{id: string, name: string}>
     */
    public function stages(): array
    {
        $stages = $this->setting('stages');

        return is_array($stages) && $stages !== [] ? array_values($stages) : self::DEFAULT_STAGES;
    }

    /**
     * The task settings, while tasks are on.
     *
     * @return array{project_id: int, assignee_id: int, due_days: int|null, title_question: string|null, copy_questions: list<string>}|null
     */
    public function taskSettings(): ?array
    {
        $tasks = $this->setting('tasks');

        return is_array($tasks) && isset($tasks['project_id'], $tasks['assignee_id']) ? [
            'project_id' => (int) $tasks['project_id'],
            'assignee_id' => (int) $tasks['assignee_id'],
            'due_days' => isset($tasks['due_days']) ? (int) $tasks['due_days'] : null,
            'title_question' => is_string($tasks['title_question'] ?? null) ? $tasks['title_question'] : null,
            'copy_questions' => array_values(array_filter(is_array($tasks['copy_questions'] ?? null) ? $tasks['copy_questions'] : [], 'is_string')),
        ] : null;
    }

    /** One response per organization (a data call) rather than per person. */
    public function answersByOrganization(): bool
    {
        return $this->answering === self::ANSWERING_ORGANIZATION;
    }

    /**
     * The live questions, or null before the first publish.
     *
     * @return array<string, mixed>|null
     */
    public function liveSchema(): ?array
    {
        return $this->publishedVersion?->schema;
    }

    /**
     * Open for answers now: published as open, inside its dates, in a
     * project that isn't archived (archiving a project closes its forms).
     * The response limit is the services' to check (it needs a count).
     */
    public function isOpenNow(): bool
    {
        $now = CarbonImmutable::now();

        return $this->status === self::STATUS_OPEN
            && $this->published_version_id !== null
            && $this->project->archived_at === null
            && ($this->opens_at === null || $this->opens_at->lte($now))
            && ($this->closes_at === null || $this->closes_at->gt($now));
    }

    /**
     * The status as the lists show it: Draft, Scheduled (published, not
     * opened yet), Open or Closed (closed by hand or past its close date).
     */
    public function phase(): string
    {
        if ($this->status === self::STATUS_DRAFT || $this->published_version_id === null) {
            return 'draft';
        }

        if ($this->status === self::STATUS_CLOSED || $this->project->archived_at !== null || ($this->closes_at !== null && $this->closes_at->lte(CarbonImmutable::now()))) {
            return 'closed';
        }

        return $this->opens_at !== null && $this->opens_at->gt(CarbonImmutable::now()) ? 'scheduled' : 'open';
    }

    /** The responses that count: not the builder's tests. */
    public function countedResponses(): int
    {
        return $this->responses()->where('is_test', false)->count();
    }

    /**
     * Whose response a person's answer is: theirs (`m:12`) or, for a data
     * call, their organization's (`o:3`).
     */
    public function respondentKeyFor(Member $member): string
    {
        return $this->answersByOrganization() ? 'o:'.(int) $member->organization_id : 'm:'.$member->id;
    }

    /**
     * The languages of the live questions, else of the draft.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        return FormSchema::languages($this->liveSchema() ?? $this->draft);
    }

    /**
     * The forms the user may build or whose responses they may see: exactly
     * those where WorkspaceAccess::formAccess is not null (its twin; keep
     * them in step).
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopeManagedBy(Builder $query, ?Authenticatable $user): Builder
    {
        if (! config('forms.enabled') || $user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $member = WorkspaceAccess::memberFor($user);

        if ($member === null || ! $member->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($user);
        $programIds = WorkspaceAccess::managedProgramIds($user);
        $ledTeamIds = WorkspaceAccess::ledTeamIds($user);
        $userId = (int) $user->getAuthIdentifier();

        return $query->where(function (Builder $q) use ($member, $adminOrganizationId, $programIds, $ledTeamIds, $userId): void {
            // Whoever runs the project (canManageProjectAccess).
            $q->whereHas('project', function (Builder $project) use ($member, $adminOrganizationId, $programIds, $ledTeamIds): void {
                $project->where(function (Builder $runs) use ($member, $adminOrganizationId, $programIds, $ledTeamIds): void {
                    $runs->whereRaw('1 = 0');

                    if ($adminOrganizationId !== null) {
                        $runs->orWhere('projects.organization_id', $adminOrganizationId);
                    }

                    if ($programIds !== []) {
                        $runs->orWhere(fn (Builder $program): Builder => Project::inPrograms($program, $programIds, $member));
                    }

                    if ($ledTeamIds !== []) {
                        $runs->orWhereHas('teams', fn (Builder $team): Builder => $team->whereIn('teams.id', $ledTeamIds));
                    }
                });
            });

            // Its maker, while they work on the project.
            $q->orWhere(fn (Builder $made): Builder => $made
                ->where('forms.created_by', $userId)
                ->whereHas('project', fn (Builder $project): Builder => $project->whereHas('teams.members', fn (Builder $m): Builder => $m->where('members.id', $member->getKey()))));

            // Shared with them, inside the project's organization.
            if ($member->organization_id !== null) {
                $q->orWhere(fn (Builder $shared): Builder => $shared
                    ->whereHas('shares', fn (Builder $share): Builder => $share->where('form_shares.member_id', $member->getKey()))
                    ->whereHas('project', fn (Builder $project): Builder => $project->where('projects.organization_id', $member->organization_id)));
            }
        });
    }

    /**
     * The forms the user is among the respondents of, whatever their status:
     * exactly those where WorkspaceAccess::isFormRespondent holds (its twin;
     * keep them in step).
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopeAnswerableBy(Builder $query, ?Authenticatable $user): Builder
    {
        $member = WorkspaceAccess::memberFor($user);

        if (! config('forms.enabled') || $user === null || $member === null || ! $member->is_active || $member->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $organizationId = (int) $member->organization_id;
        $memberId = $member->getKey();

        return $query->where(function (Builder $q) use ($organizationId, $memberId): void {
            $q->where(fn (Builder $own): Builder => $own
                ->whereHas('project', fn (Builder $project): Builder => $project->where('projects.organization_id', $organizationId))
                ->where(fn (Builder $who): Builder => $who
                    ->where('forms.respondents', self::RESPONDENTS_EVERYONE)
                    ->orWhere(fn (Builder $team): Builder => $team
                        ->where('forms.respondents', self::RESPONDENTS_TEAM)
                        ->whereHas('project.teams.members', fn (Builder $m): Builder => $m->where('members.id', $memberId)))
                    ->orWhere(fn (Builder $people): Builder => $people
                        ->where('forms.respondents', self::RESPONDENTS_PEOPLE)
                        ->whereHas('respondentMembers', fn (Builder $m): Builder => $m->where('members.id', $memberId)))));

            $q->orWhereHas('respondentOrganizations', fn (Builder $o): Builder => $o->where('organizations.id', $organizationId));
        });
    }
}
