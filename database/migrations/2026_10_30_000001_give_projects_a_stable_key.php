<?php

use App\Support\ProjectKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task codes (SG-12) end up in commit messages, which never change, so the
 * project half of the code becomes a stored key instead of being derived from
 * the title each time. Existing projects get the key their title used to
 * give them, made unique within their organization in id order. A key that
 * is renamed away stays behind as an alias so old commits keep resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('key', 10)->nullable()->after('slug');
        });

        $taken = [];

        foreach (DB::table('projects')->orderBy('id')->get(['id', 'title', 'organization_id']) as $project) {
            $used = &$taken[(int) $project->organization_id];
            $used ??= [];

            $base = ProjectKeys::suggest((string) $project->title);
            $key = $base;

            for ($n = 0; in_array($key, $used, true); $n++) {
                $key = ProjectKeys::variant($base, $n);
            }

            $used[] = $key;
            unset($used);

            DB::table('projects')->where('id', $project->id)->update(['key' => $key]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('key', 10)->nullable(false)->change();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('project_key_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 10);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_key_aliases');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'key']);
            $table->dropColumn('key');
        });
    }
};
