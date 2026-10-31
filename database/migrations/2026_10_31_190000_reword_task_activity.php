<?php

use App\Support\ActivityText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewords the task activity written before ActivityText, in people's words:
 * statuses by their labels instead of their keys, "Created the task" for
 * `Plan #1 — "…" created`, dates for timestamps, a note's type by its label,
 * and no empty "()" after an assignee whose title is gone. A row is rebuilt from its own `changes`; a
 * row without them keeps its words. The old words are not kept, so there is
 * nothing to undo.
 */
return new class extends Migration
{
    private const FIELD_LABELS = [
        'status_note' => 'Status note',
        'responsible_ministry' => 'Responsible ministry',
        'deadline_at' => 'Deadline',
    ];

    public function up(): void
    {
        $this->rebuild('task', 'status_changed', fn (array $changes): ?string => array_key_exists('to', $changes)
            ? ActivityText::status($changes['from'] ?? null, $changes['to'])
            : null);

        $this->rebuild('task', 'updated', fn (array $changes): ?string => isset(self::FIELD_LABELS[$changes['field'] ?? ''])
            ? ActivityText::change(self::FIELD_LABELS[$changes['field']], $changes['from'] ?? null, $changes['to'] ?? null)
            : null);

        $this->rebuild('project-assignment', 'updated', fn (array $changes, string $description): ?string => str_starts_with($description, 'Priority:')
            ? ActivityText::priority($changes['from'] ?? null, $changes['to'] ?? null)
            : null);

        foreach (['created' => 'Created the task', 'restored' => 'Restored the task'] as $action => $words) {
            DB::table('project_activities')->where('subject_type', 'task')->where('action', $action)
                ->where('description', 'like', 'Plan #%')->update(['description' => $words]);
        }

        DB::table('project_activities')->where('subject_type', 'task')->where('description', 'Plan marked complete')->update(['description' => 'Marked complete']);
        DB::table('project_activities')->where('subject_type', 'task')->where('description', 'Plan reopened')->update(['description' => 'Reopened']);

        DB::table('project_activities')->where('subject_type', 'project-note')->where('action', 'created')
            ->where('description', 'like', 'Note added: %')->orderBy('id')->lazyById()
            ->each(function (object $row): void {
                if (preg_match('/^Note added: (\w+) — "(.*)"$/s', (string) $row->description, $m) === 1) {
                    DB::table('project_activities')->where('id', $row->id)->update(['description' => ActivityText::noteAdded($m[1], $m[2])]);
                }
            });

        DB::table('project_activities')->where('subject_type', 'project-assignment')->where('description', 'like', '% ()')
            ->orderBy('id')->lazyById()
            ->each(fn (object $row) => DB::table('project_activities')->where('id', $row->id)
                ->update(['description' => substr((string) $row->description, 0, -3)]));
    }

    public function down(): void
    {
        // The old words are not kept.
    }

    /**
     * @param  Closure(array<string, mixed>, string): ?string  $words  the new description, or null to leave the row
     */
    private function rebuild(string $subjectType, string $action, Closure $words): void
    {
        DB::table('project_activities')->where('subject_type', $subjectType)->where('action', $action)->whereNotNull('changes')
            ->orderBy('id')->lazyById()
            ->each(function (object $row) use ($words): void {
                $changes = json_decode((string) $row->changes, true);
                $description = is_array($changes) ? $words($changes, (string) $row->description) : null;

                if ($description !== null && $description !== $row->description) {
                    DB::table('project_activities')->where('id', $row->id)->update(['description' => $description]);
                }
            });
    }
};
