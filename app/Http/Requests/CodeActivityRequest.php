<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Queries\CodeActivityQuery;
use App\Support\Git\GitAccess;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The code activity report's filters, shared by the report and a person's
 * commits: a period (today, this week from the viewer's week start, last
 * week, this month, or a custom range of whole days, the last 30 ending
 * today unless given, at most 366; a range without a period is a custom
 * one), a project or program by slug, a designation, the figure people are
 * ranked by, and up to four people (user ids) to compare. For anyone the
 * tracker admits to git (GitAccess, the web's `gitEnabled`). A project or
 * program outside the viewer's whole repositories is a 403, as is a slug
 * nothing has: a filter never confirms what lies outside the scope. A
 * designation outside the organizations in scope, or none, is the same 422.
 */
class CodeActivityRequest extends FormRequest
{
    use ReadsDayRange {
        range as customRange;
    }

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    public const MAX_MEMBERS = 4;

    private ?Project $projectFilter = null;

    private ?Program $programFilter = null;

    public function authorize(CodeActivityQuery $query): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! GitAccess::anyFor($user)) {
            return false;
        }

        $project = $this->input('project');

        if (is_string($project) && $project !== '') {
            $this->projectFilter = Project::query()->where('slug', $project)->first();

            if ($this->projectFilter === null || ! $query->coversProject($user, $this->projectFilter)) {
                return false;
            }
        }

        $program = $this->input('program');

        if (is_string($program) && $program !== '') {
            $this->programFilter = Program::query()->where('slug', $program)->first();

            if ($this->programFilter === null || ! $query->coversProgram($user, $this->programFilter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->rangeRules(),
            'project' => ['nullable', 'string'],
            'program' => ['nullable', 'string'],
            'members' => ['nullable', 'array', 'max:'.self::MAX_MEMBERS],
            'members.*' => ['integer', 'min:1'],
            'period' => ['nullable', Rule::in(array_keys(CodeActivityQuery::PERIODS))],
            'designation' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(CodeActivityQuery::SORTS)],
        ];
    }

    /**
     * A designation must be one of an organization in the viewer's scope:
     * one elsewhere and one that doesn't exist get the same answer.
     *
     * @return list<callable(Validator): void>
     */
    public function after(CodeActivityQuery $query): array
    {
        return [function (Validator $validator) use ($query): void {
            if ($validator->errors()->has('designation') || $this->designation() === null) {
                return;
            }

            if (! $query->coversDesignation($this->user(), $this->designation())) {
                $validator->errors()->add('designation', 'Choose one of the designations listed.');
            }
        }];
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, project: ?Project, program: ?Program, members: list<int>, designation: ?int, sort: string}
     */
    public function filters(): array
    {
        [$from, $to] = $this->range();

        return [
            'from' => $from,
            'to' => $to,
            'project' => $this->projectFilter,
            'program' => $this->programFilter,
            'members' => $this->members(),
            'designation' => $this->designation(),
            'sort' => $this->sort(),
        ];
    }

    /**
     * The filters as applied, for the page to show and send back.
     *
     * @return array{period: string, from: string, to: string, project: ?string, program: ?string, designation: ?int, sort: string, members: list<int>}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return [
            'period' => $this->period(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'project' => $this->projectFilter?->slug,
            'program' => $this->programFilter?->slug,
            'designation' => $this->designation(),
            'sort' => $this->sort(),
            'members' => $this->members(),
        ];
    }

    /**
     * The period's whole days, by the viewer's today: this week from their
     * week start (WorkingDays), last week the seven days before it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $period = $this->period();
        $today = CarbonImmutable::today();

        if ($period === 'custom') {
            return $this->customRange();
        }

        if ($period === 'today') {
            return [$today, $today];
        }

        if ($period === 'month') {
            return [$today->startOfMonth(), $today];
        }

        $weekStart = WorkingDays::forUser($this->user())->weekStart($today);

        return $period === 'week' ? [$weekStart, $today] : [$weekStart->subDays(7), $weekStart->subDay()];
    }

    /**
     * The period asked for; without one, a range given is a custom one
     * (links from before periods), else this week.
     */
    public function period(): string
    {
        $period = $this->input('period');

        if (is_string($period) && array_key_exists($period, CodeActivityQuery::PERIODS)) {
            return $period;
        }

        return $this->filled('from') || $this->filled('to') ? 'custom' : 'week';
    }

    private function designation(): ?int
    {
        $designation = $this->input('designation');

        return is_numeric($designation) && (int) $designation > 0 ? (int) $designation : null;
    }

    private function sort(): string
    {
        $sort = $this->input('sort');

        return is_string($sort) && in_array($sort, CodeActivityQuery::SORTS, true) ? $sort : 'commits';
    }

    protected function defaultFrom(CarbonImmutable $to): CarbonImmutable
    {
        return $to->subDays(self::DEFAULT_DAYS - 1);
    }

    protected function maxRangeDays(): int
    {
        return self::MAX_DAYS;
    }

    /**
     * @return list<int>
     */
    private function members(): array
    {
        $members = $this->validated('members') ?? [];

        return array_values(array_unique(array_map(intval(...), is_array($members) ? $members : [])));
    }
}
