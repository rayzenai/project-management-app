<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Forms (docs/superpowers/specs/2026-11-07-forms-design.md). A form belongs
 * to a project; its questions are a draft (`forms.draft`) and the versions
 * published from it, which never change. A response is one person's (or, for
 * a data call, one organization's): `respondent_key` is `m:{member}` or
 * `o:{organization}`, unique per form, and null for the builder's test
 * responses. Nothing here is deleted in this round: a response's files and
 * history stay with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 12)->unique();
            $table->string('title', 200);
            $table->string('status', 8)->default('draft');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('response_limit')->nullable();
            $table->string('respondents', 10)->default('everyone');
            $table->string('answering', 14)->default('person');
            $table->jsonb('settings');
            $table->jsonb('draft');
            // The live version (form_versions, never deleted); no foreign key,
            // as the two tables point at each other.
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'deleted_at']);
            $table->index(['status', 'closes_at']);
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->jsonb('schema');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');

            $table->unique(['form_id', 'number']);
        });

        Schema::create('form_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 10);
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'member_id']);
            $table->index('member_id');
        });

        Schema::create('form_respondent_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->unique(['form_id', 'member_id']);
            $table->index('member_id');
        });

        Schema::create('form_respondent_organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->unique(['form_id', 'organization_id']);
            $table->index('organization_id');
        });

        Schema::create('form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->string('reference', 12)->unique();
            $table->string('respondent_key', 24)->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('sender');
            $table->jsonb('answers');
            $table->string('stage', 40)->nullable();
            $table->boolean('is_test')->default(false);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['form_id', 'respondent_key']);
            $table->index(['form_id', 'is_test', 'submitted_at']);
            $table->index(['form_id', 'stage']);
            $table->index('member_id');
        });

        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('respondent_key', 24);
            $table->foreignId('form_version_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('answers');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'respondent_key']);
        });

        Schema::create('form_response_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_response_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('data');
            $table->timestamp('created_at');

            $table->index(['form_response_id', 'created_at']);
        });

        Schema::create('form_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 8);
            $table->string('block_id', 24);
            $table->string('respondent_key', 24)->nullable();
            $table->foreignId('form_response_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500);
            $table->string('name', 255);
            $table->unsignedBigInteger('size');
            $table->string('mime', 120);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['form_id', 'respondent_key']);
            $table->index('form_response_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX form_responses_answers_gin ON form_responses USING gin (answers)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_files');
        Schema::dropIfExists('form_response_events');
        Schema::dropIfExists('form_drafts');
        Schema::dropIfExists('form_responses');
        Schema::dropIfExists('form_respondent_organizations');
        Schema::dropIfExists('form_respondent_members');
        Schema::dropIfExists('form_shares');

        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
