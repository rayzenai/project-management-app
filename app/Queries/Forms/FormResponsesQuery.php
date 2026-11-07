<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Models\FormResponseEvent;
use App\Models\FormVersion;
use App\Support\Forms\FormSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * A form's responses for its side: the table (filters in the URL, newest
 * first, 50 at a time), one response in full with its history, and the
 * questions every version asked, so a removed question's answers still
 * show under "Earlier questions". Test responses are never here.
 */
class FormResponsesQuery
{
    public const PER_PAGE = 50;

    /**
     * The questions of the live version (else the draft), then any only
     * earlier versions asked, each with `earlier`.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(Form $form): array
    {
        $current = FormSchema::questions($form->liveSchema() ?? $form->draft);
        $questions = array_map(fn (array $q): array => $q + ['earlier' => false], array_values($current));

        foreach (FormVersion::query()->where('form_id', $form->id)->orderByDesc('number')->pluck('schema') as $schema) {
            foreach (FormSchema::questions(is_array($schema) ? $schema : []) as $id => $question) {
                if (! isset($current[$id])) {
                    $current[$id] = $question;
                    $questions[] = $question + ['earlier' => true];
                }
            }
        }

        return $questions;
    }

    /**
     * @param  array{stage?: string|null, organization?: int|null, q?: string|null, question?: string|null, answer?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<FormResponse>
     */
    public function filtered(Form $form, array $filters): Builder
    {
        $query = FormResponse::query()->where('form_id', $form->id)->where('is_test', false);

        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        if (! empty($filters['organization'])) {
            $query->where('organization_id', (int) $filters['organization']);
        }

        if (! empty($filters['q'])) {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], '', trim((string) $filters['q']))).'%';
            $query->where(fn (Builder $q): Builder => $q
                ->whereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereHas('member', fn (Builder $m): Builder => $m->whereRaw('LOWER(name) LIKE ?', [$like])));
        }

        if (! empty($filters['question']) && isset($filters['answer']) && $filters['answer'] !== '') {
            $question = collect($this->questions($form))->firstWhere('id', $filters['question']);
            $path = 'answers->'.preg_replace('/[^a-z0-9_]/', '', (string) $filters['question']);

            if ($question !== null) {
                match ($question['type']) {
                    'choices' => $query->whereJsonContains($path, (string) $filters['answer']),
                    'yes_no' => $query->where($path, $filters['answer'] === 'yes'),
                    default => $query->where($path, (string) $filters['answer']),
                };
            }
        }

        if (! empty($filters['from'])) {
            $query->where('submitted_at', '>=', $filters['from'].' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('submitted_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null, total: int}
     */
    public function page(Form $form, array $filters, ?string $cursor): array
    {
        $query = $this->filtered($form, $filters);
        $total = (clone $query)->count();
        $page = $query->with(['member.designation:id,name', 'organization:id,name', 'task:id,slug,project_id,status,title', 'task.project:id,slug'])
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $cursor);

        return [
            'rows' => array_values(collect($page->items())->map(fn (FormResponse $response): array => $this->row($response))->all()),
            'next_cursor' => $page->nextCursor()?->encode(),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function row(FormResponse $response): array
    {
        return [
            'id' => $response->id,
            'reference' => $response->reference,
            'sender' => $response->sender,
            'organization_id' => $response->organization_id,
            'stage' => $response->stage,
            'answers' => (object) $response->answers,
            'version' => $response->form_version_id,
            'submitted_at' => $response->submitted_at->toIso8601String(),
            'updated_at' => $response->updated_at?->toIso8601String(),
            'task' => $response->task === null ? null : [
                'title' => $response->task->title,
                'status' => $response->task->status,
                'url' => "/workspace/projects/{$response->task->project?->slug}/tasks/{$response->task->slug}",
                'trashed' => $response->task->trashed(),
            ],
        ];
    }

    /**
     * One response in full: its answers (with the version it was filled in
     * on), files and history.
     *
     * @return array<string, mixed>
     */
    public function show(FormResponse $response): array
    {
        $response->loadMissing(['version', 'events.user:id,name', 'task.project:id,slug']);
        $schema = $response->version->schema;
        $names = array_column($response->form->stages(), 'name', 'id');

        return [
            ...$this->row($response),
            'schema' => $schema,
            'version_number' => $response->version->number,
            'files' => (object) FormFileRefs::byIds($response->form_id, array_values($response->files()->pluck('id')->map(fn ($id): int => (int) $id)->all()), FormFile::PURPOSE_ANSWER),
            'history' => $response->events->map(fn (FormResponseEvent $event): array => [
                'kind' => $event->kind,
                'by' => $event->user->name ?? (is_array($event->data['by'] ?? null) ? (string) ($event->data['by']['name'] ?? '') : null),
                'at' => $event->created_at->toIso8601String(),
                'text' => match ($event->kind) {
                    'submitted' => 'Sent the response',
                    'edited' => 'Changed the answers',
                    'stage' => 'Moved from '.($names[$event->data['from'] ?? ''] ?? 'none').' to '.($names[$event->data['to'] ?? ''] ?? 'a stage that is gone'),
                    'note' => 'Note',
                    'task' => 'Made a task from it',
                    default => '',
                },
                'note' => Arr::get($event->data, $event->kind === 'note' ? 'text' : 'note'),
            ])->values()->all(),
        ];
    }
}
