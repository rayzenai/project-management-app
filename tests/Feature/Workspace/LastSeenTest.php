<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Carbon::setTestNow());

it('sets last_seen_at on a web visit', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    $user = User::factory()->create();

    expect($user->last_seen_at)->toBeNull();

    $this->actingAs($user)->get('/workspace/my')->assertSuccessful();

    expect($user->fresh()->last_seen_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('leaves a recent value alone and moves a stale one', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/workspace/my')->assertSuccessful();
    $first = $user->fresh()->last_seen_at;

    Carbon::setTestNow(now()->addMinutes(10));
    $this->actingAs($user->fresh())->get('/workspace/my')->assertSuccessful();
    expect($user->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    Carbon::setTestNow($first->copy()->addMinutes(61));
    $this->actingAs($user->fresh())->get('/workspace/my')->assertSuccessful();
    expect($user->fresh()->last_seen_at->equalTo($first))->toBeFalse()
        ->and($user->fresh()->last_seen_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('sets last_seen_at on an authenticated API request', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')->assertSuccessful();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('does not change updated_at', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['updated_at' => '2026-01-01 00:00:00']);

    $this->actingAs($user->fresh())->get('/workspace/my')->assertSuccessful();

    $fresh = $user->fresh();
    expect($fresh->last_seen_at)->not->toBeNull()
        ->and($fresh->updated_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('writes nothing for a guest', function () {
    User::factory()->create();

    $this->get('/workspace/my')->assertRedirect();

    expect(User::query()->whereNotNull('last_seen_at')->count())->toBe(0);
});
