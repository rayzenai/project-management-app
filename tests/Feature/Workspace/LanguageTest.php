<?php

use App\Http\Middleware\EnsureCanSignIn;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/*
 * English or Nepali, each person's own (docs/superpowers/specs/2026-11-07-languages-design.md):
 * saved as their preference, kept in a cookie for the sign-in page, and the
 * language every page, message and form error answers in.
 */

const DEVANAGARI = '/\p{Devanagari}/u';

it('starts everyone in English', function () {
    $this->get('/workspace/login')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('locale', 'en')
            ->where('locales', [['value' => 'en', 'label' => 'English'], ['value' => 'ne', 'label' => 'नेपाली']]));

    $this->actingAs(User::factory()->create())->get('/workspace/my')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('saves a person\'s language and answers in it from then on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/workspace/my')
        ->post('/locale', ['locale' => 'ne'])
        ->assertRedirect('/workspace/my')
        ->assertCookie('locale', 'ne');

    expect($user->fresh()->locale())->toBe('ne');
    $this->actingAs($user->fresh())->get('/workspace/my')
        ->assertInertia(fn ($page) => $page->where('locale', 'ne'));
});

it('saves the language through the preferences route too', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/workspace/my')
        ->patch('/workspace/preferences', ['locale' => 'ne'])
        ->assertRedirect('/workspace/my')
        ->assertCookie('locale', 'ne');

    expect($user->fresh()->locale())->toBe('ne');
});

it('follows the saved language over the cookie', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['locale' => 'ne']);

    $this->actingAs($user)->withCookie('locale', 'en')->get('/workspace/my')
        ->assertInertia(fn ($page) => $page->where('locale', 'ne'));
});

it('lets someone signed out choose the sign-in page\'s language', function () {
    $this->from('/workspace/login')->post('/locale', ['locale' => 'ne'])
        ->assertRedirect('/workspace/login')
        ->assertCookie('locale', 'ne');

    $this->withCookie('locale', 'ne')->get('/workspace/login')
        ->assertInertia(fn ($page) => $page->where('locale', 'ne'));
});

it('refuses a language the app does not have', function () {
    $user = User::factory()->create();

    $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    $this->actingAs($user)->patch('/workspace/preferences', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    $this->actingAs($user)->withCookie('locale', 'fr')->get('/workspace/my')
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('answers form errors in the person\'s language', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['locale' => 'ne']);

    $response = $this->actingAs($user)->post('/locale', ['locale' => ''])->assertSessionHasErrors('locale');

    expect(session('errors')->first('locale'))->toMatch(DEVANAGARI);
});

it('gives the language to the app and takes it from it', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('locale', 'en');
    $this->patchJson('/api/v1/user/preferences', ['locale' => 'ne'])->assertOk()->assertJsonPath('data.locale', 'ne');
    $this->getJson('/api/v1/user')->assertJsonPath('locale', 'ne');

    $error = $this->patchJson('/api/v1/user/preferences', ['locale' => 'fr'])->assertUnprocessable()->json('errors.locale.0');
    expect($error)->toMatch(DEVANAGARI);
});

it('names the field in Nepali in a form error', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['locale' => 'ne']);

    $this->actingAs($user)->post('/workspace/quick-add', ['personal' => true, 'title' => ''])->assertSessionHasErrors('title');

    expect(session('errors')->first('title'))->toBe('शीर्षक आवश्यक छ।');
});

it('answers a document action in Nepali, on the web and over the API', function () {
    config(['documents.enabled' => true, 'documents.server_url' => 'https://docs.example', 'documents.jwt_secret' => DOCS_SECRET]);
    $owner = docsPerson(Organization::factory()->create(), null);
    $owner->preferences()->create(['locale' => 'ne']);
    $document = docsMake($owner, title: 'Notes');

    $this->actingAs($owner)->from('/workspace/documents')
        ->patch("/workspace/documents/{$document->id}", ['title' => 'Budget'])
        ->assertSessionHas('workspace_flash.message', 'नाम फेरेर Budget राखियो।');

    Sanctum::actingAs($owner);
    $message = $this->patchJson("/api/v1/workspace/documents/{$document->id}", ['title' => 'Letter'])->assertOk()->json('message');

    expect($message)->toMatch(DEVANAGARI)->toContain('Letter');
});

it('refuses a sign-in in Nepali', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

    $this->withCookie('locale', 'ne')->from('/workspace/login')
        ->post('/workspace/login', ['email' => $user->email, 'password' => 'wrong-pass'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe('इमेल ठेगाना वा पासवर्ड मिलेन।');

    Member::factory()->linkedTo($user)->inactive()->create();

    $this->withCookie('locale', 'ne')->from('/workspace/login')
        ->post('/workspace/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toMatch(DEVANAGARI)->not->toBe(EnsureCanSignIn::DEACTIVATED);
});
