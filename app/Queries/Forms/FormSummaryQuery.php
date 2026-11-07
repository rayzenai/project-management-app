<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Support\Forms\FormSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Summary tab: for every choice, yes/no and scale question of the live
 * version, how many chose each answer, and responses per day. Test
 * responses aren't counted.
 */
class FormSummaryQuery
{
    /**
     * @return array{total: int, per_day: list<array{day: string, count: int}>, questions: list<array<string, mixed>>}
     */
    public function for(Form $form): array
    {
        $responses = FormResponse::query()->where('form_id', $form->id)->where('is_test', false)->get(['id', 'answers', 'submitted_at']);
        $questions = [];

        foreach (FormSchema::questions($form->liveSchema() ?? $form->draft) as $id => $question) {
            $type = (string) $question['type'];

            if (! in_array($type, ['choice', 'choices', 'yes_no', 'scale'], true)) {
                continue;
            }

            $answers = $responses->map(fn (FormResponse $r): mixed => $r->answers[$id] ?? null)->filter(fn (mixed $v): bool => $v !== null);
            $choices = match ($type) {
                'yes_no' => [['id' => 'yes', 'label' => ['en' => 'Yes', 'ne' => 'हो'], 'value' => true], ['id' => 'no', 'label' => ['en' => 'No', 'ne' => 'होइन'], 'value' => false]],
                'scale' => array_map(fn (int $n): array => ['id' => (string) $n, 'label' => ['en' => (string) $n, 'ne' => (string) $n], 'value' => $n], range(1, (int) ($question['rules']['max'] ?? 5))),
                default => array_map(fn (array $o): array => ['id' => $o['id'], 'label' => $o['label'], 'value' => $o['id']], $question['options'] ?? []),
            };

            $questions[] = [
                'id' => $id,
                'label' => $question['label'],
                'type' => $type,
                'answered' => $answers->count(),
                'counts' => array_map(fn (array $choice): array => [
                    'id' => $choice['id'],
                    'label' => $choice['label'],
                    'count' => $answers->filter(fn (mixed $v): bool => $type === 'choices' ? is_array($v) && in_array($choice['value'], $v, true) : $v === $choice['value'])->count(),
                ], $choices),
            ];
        }

        $perDay = $responses->groupBy(fn (FormResponse $r): string => CarbonImmutable::parse($r->submitted_at)->toDateString())
            ->map(fn (Collection $group, string $day): array => ['day' => $day, 'count' => $group->count()])
            ->sortKeys()->values()->all();
        $perDay = array_values($perDay);

        return ['total' => $responses->count(), 'per_day' => $perDay, 'questions' => $questions];
    }
}
