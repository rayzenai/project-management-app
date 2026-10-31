<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance, per organization and off until a super-admin turns it on:
 * working days and hours (with seasons such as winter hours), the office's
 * places and network ranges that judge where a punch came from, holidays,
 * the punches themselves (never edited), and leave and kaaj.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->jsonb('workdays'); // ISO weekdays, 1 = Monday
            $table->string('start_time', 5)->default('10:00');
            $table->string('end_time', 5)->default('17:00');
            $table->jsonb('seasons'); // [{name, from: MM-DD, to: MM-DD, start: HH:MM, end: HH:MM}]
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->timestamps();
        });

        Schema::create('attendance_places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(150);
            $table->timestamps();
        });

        Schema::create('attendance_networks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('cidr', 49);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['organization_id', 'date']);
        });

        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('kind', 12); // in, lunch_start, lunch_end, out
            $table->timestamp('punched_at');
            $table->string('source', 8); // web, app, device
            $table->string('device_ref', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->string('place', 16); // office_network, near_office, outside, unknown
            $table->foreignId('attendance_place_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'work_date', 'kind']);
            $table->index(['organization_id', 'work_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // leave, kaaj
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500);
            $table->string('place', 255)->nullable();
            $table->string('status', 10)->default('pending'); // pending, approved, rejected, cancelled
            $table->foreignId('decided_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'starts_on', 'ends_on']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_networks');
        Schema::dropIfExists('attendance_places');
        Schema::dropIfExists('attendance_settings');
    }
};
