<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person's free-text title gives way to their organization's designations.
 * The title of someone with no designation yet becomes theirs: a designation
 * of their organization (one per name, whatever its case, ranked last). Then
 * the column goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $titled = DB::table('members')
            ->whereNotNull('organization_id')
            ->whereNull('designation_id')
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'title']);

        foreach ($titled as $member) {
            $name = mb_substr(trim((string) $member->title), 0, 255);

            if ($name === '') {
                continue;
            }

            $designationId = DB::table('designations')
                ->where('organization_id', $member->organization_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->value('id');

            if ($designationId === null) {
                $designationId = DB::table('designations')->insertGetId([
                    'organization_id' => $member->organization_id,
                    'name' => $name,
                    'rank' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('members')->where('id', $member->id)->update(['designation_id' => $designationId]);
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('title')->nullable();
        });

        DB::table('members')
            ->whereNotNull('designation_id')
            ->update(['title' => DB::table('designations')->select('name')->whereColumn('designations.id', 'members.designation_id')]);
    }
};
