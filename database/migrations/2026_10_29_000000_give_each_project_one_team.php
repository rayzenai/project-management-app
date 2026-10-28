<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every project has exactly one team of its own, and a team has at most one
 * leader: the project's manager. Existing data is folded in:
 *
 * - a project whose only team serves no other project keeps it;
 * - any other project gets a new team holding everyone from the teams it had
 *   (the first leader found leads it);
 * - teams left serving no project are retired (soft-deleted);
 * - a team with several leaders keeps the first one appointed.
 *
 * Tasks, assignments and people are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            // Links to retired teams go; such teams can no longer come back.
            DB::table('project_team')
                ->whereIn('team_id', DB::table('teams')->whereNotNull('deleted_at')->select('id'))
                ->delete();

            $uses = DB::table('project_team')
                ->selectRaw('team_id, count(*) as uses')
                ->groupBy('team_id')
                ->pluck('uses', 'team_id');

            foreach (DB::table('projects')->orderBy('id')->get(['id', 'title', 'organization_id']) as $project) {
                $teamIds = DB::table('project_team')->where('project_id', $project->id)->pluck('team_id');

                if ($teamIds->count() === 1 && (int) $uses[$teamIds->first()] === 1) {
                    DB::table('teams')->where('id', $teamIds->first())->update(['name' => $project->title, 'updated_at' => $now]);

                    continue;
                }

                $teamId = DB::table('teams')->insertGetId([
                    'name' => $project->title,
                    'slug' => $this->uniqueSlug($project->title),
                    'organization_id' => $project->organization_id,
                    'is_cross_organization' => DB::table('teams')->whereIn('id', $teamIds)->where('is_cross_organization', true)->exists(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $leaderId = null;
                $memberIds = [];

                foreach (DB::table('member_team')->whereIn('team_id', $teamIds)->orderBy('id')->get(['member_id', 'role']) as $row) {
                    $memberIds[$row->member_id] = true;

                    if ($leaderId === null && $row->role === 'leader') {
                        $leaderId = $row->member_id;
                    }
                }

                foreach (array_keys($memberIds) as $memberId) {
                    DB::table('member_team')->insert([
                        'member_id' => $memberId,
                        'team_id' => $teamId,
                        'role' => $memberId === $leaderId ? 'leader' : 'member',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('project_team')->where('project_id', $project->id)->delete();
                DB::table('project_team')->insert(['project_id' => $project->id, 'team_id' => $teamId, 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('teams')
                ->whereNull('deleted_at')
                ->whereNotIn('id', DB::table('project_team')->select('team_id'))
                ->update(['deleted_at' => $now]);

            $firstLeaders = DB::table('member_team')->where('role', 'leader')->selectRaw('min(id)')->groupBy('team_id');

            DB::table('member_team')
                ->where('role', 'leader')
                ->whereNotIn('id', $firstLeaders)
                ->update(['role' => 'member', 'updated_at' => $now]);
        });

        Schema::table('project_team', function (Blueprint $table): void {
            $table->unique('project_id');
            $table->unique('team_id');
        });

        DB::statement("CREATE UNIQUE INDEX member_team_one_leader ON member_team (team_id) WHERE role = 'leader'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX member_team_one_leader');

        Schema::table('project_team', function (Blueprint $table): void {
            $table->dropUnique(['project_id']);
            $table->dropUnique(['team_id']);
        });
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'team';
        $slug = $base;
        $i = 2;

        while (DB::table('teams')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
};
