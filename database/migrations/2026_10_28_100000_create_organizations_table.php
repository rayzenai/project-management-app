<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-tenancy: every team and project belongs to an organization (an office
 * or ministry), and every member does too except super-admins. Existing data
 * moves into one organization named after the app (APP_NAME), so a running
 * install keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_office_admin')->default(false);
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_cross_organization')->default(false);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
        });

        $this->backfill();

        foreach (['teams', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('is_cross_organization');
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('is_office_admin');
        });

        Schema::dropIfExists('organizations');
    }

    private function backfill(): void
    {
        $hasData = DB::table('members')->exists()
            || DB::table('teams')->exists()
            || DB::table('projects')->exists();

        if (! $hasData) {
            return;
        }

        $name = (string) config('app.name', 'Organization');
        $now = now();

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'organization',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('teams')->update(['organization_id' => $organizationId]);
        DB::table('projects')->update(['organization_id' => $organizationId]);

        // Super-admins belong to no organization; everyone else joins this one.
        $superAdminUserIds = DB::table('users')
            ->whereIn('email', (array) config('project-management.super_admins', []))
            ->pluck('id')
            ->all();

        DB::table('members')
            ->where(fn ($query) => $query->whereNull('user_id')->orWhereNotIn('user_id', $superAdminUserIds))
            ->update(['organization_id' => $organizationId]);
    }
};
