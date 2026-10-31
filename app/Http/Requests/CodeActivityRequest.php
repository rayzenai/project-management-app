<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Queries\CodeActivityQuery;
use App\Support\Git\GitAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The code activity report's filters, shared by the report and a person's
 * commits: a range of whole days (the last 30 ending today unless given, at
 * most 366), a project or program by slug, and up to four people (user ids)
 * to compare. For anyone the tracker admits to git (GitAccess, the web's
 * `gitEnabled`). A project or program outside the viewer's whole
 * repositories is a 403, as is a slug nothing has: a filter never confirms
 * what lies outside the scope.
 */
class CodeActivityRequest extends FormRequest
{
    use ReadsDayRange;

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
        ];
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, project: ?Project, program: ?Program, members: list<int>}
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
        ];
    }

    /**
     * The filters as applied, for the page to show and send back.
     *
     * @return array{from: string, to: string, project: ?string, program: ?string, members: list<int>}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'project' => $this->projectFilter?->slug,
            'program' => $this->programFilter?->slug,
            'members' => $this->members(),
        ];
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
