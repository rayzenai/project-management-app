<?php

use App\Models\AttendancePunch;
use App\Models\Member;

it('makes a punch on the day of its time, with or without one given', function () {
    $member = Member::factory()->create();

    $plain = AttendancePunch::factory()->create(['member_id' => $member->id]);
    $given = AttendancePunch::factory()->create(['member_id' => $member->id, 'kind' => AttendancePunch::OUT, 'punched_at' => '2026-11-03 16:05:00']);

    expect($plain->work_date->toDateString())->toBe(now()->toDateString())
        ->and($given->work_date->toDateString())->toBe('2026-11-03');
});
