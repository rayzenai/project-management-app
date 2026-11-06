<?php

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Sends every email through a real mailer whose server answers each
 * recipient by `$answer`: it throws what `$answer` returns, else delivers.
 * Returns every address it was asked to send to, in order.
 * `Mail::fake()` puts a working one back.
 *
 * @param  Closure(string): ?Throwable  $answer
 * @return ArrayObject<int, string>
 */
function fakeMailServer(Closure $answer): ArrayObject
{
    $asked = new ArrayObject;
    $mailer = new MailManager(app());
    $mailer->extend('scripted', fn () => new class($answer, $asked) implements TransportInterface
    {
        /**
         * @param  Closure(string): ?Throwable  $answer
         * @param  ArrayObject<int, string>  $asked
         */
        public function __construct(private Closure $answer, private ArrayObject $asked) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $envelope ??= Envelope::create($message);
            $to = $envelope->getRecipients()[0]->getAddress();
            $this->asked[] = $to;

            if (($refusal = ($this->answer)($to)) !== null) {
                throw $refusal;
            }

            return new SentMessage($message, $envelope);
        }

        public function __toString(): string
        {
            return 'scripted://';
        }
    });
    config(['mail.default' => 'scripted', 'mail.mailers.scripted' => ['transport' => 'scripted']]);
    Mail::swap($mailer);

    return $asked;
}

/**
 * A mail outage: the server can't be reached for any message.
 *
 * @return ArrayObject<int, string> the addresses it was asked to send to
 */
function breakMailTransport(): ArrayObject
{
    return fakeMailServer(fn (): Throwable => new TransportException('Connection could not be established with host "smtp.example.test:587"'));
}

/**
 * The mail server's reply `$code` to one step of sending a message, as
 * Symfony's SMTP transport throws it: the replies it expected in the
 * message, and the commands sent so far in its debug transcript (as
 * MailFailure reads them). Steps: `greeting`, `MAIL FROM`, `RCPT TO`,
 * `DATA` and `end of data`.
 */
function smtpReply(int $code, string $at = 'RCPT TO'): UnexpectedResponseException
{
    $steps = [
        'greeting' => [null, '220'],
        'MAIL FROM' => ['MAIL FROM:<no-reply@example.test>', '250'],
        'RCPT TO' => ['RCPT TO:<someone@example.test>', '250/251/252'],
        'DATA' => ['DATA', '354'],
        'end of data' => ['.', '250'],
    ];
    $debug = '';

    foreach ($steps as $step => [$command, $expected]) {
        $debug .= ($command === null ? '' : "[2026-11-03T10:03:00.000000+05:45] > {$command}\n")
            .'[2026-11-03T10:03:00.000000+05:45] < '.($step === $at ? "{$code} No" : explode('/', $expected)[0].' OK')."\r\n";

        if ($step === $at) {
            $refusal = new UnexpectedResponseException("Expected response code \"{$expected}\" but got code \"{$code}\", with message \"{$code} No\".", $code);
            $refusal->appendDebug($debug);

            return $refusal;
        }
    }

    throw new InvalidArgumentException("No SMTP step {$at}.");
}

/*
 * Documents (tests/Feature/Workspace/Documents*Test.php): the editor's shared
 * secret, people, documents and the editor's signed calls.
 */

const DOCS_SECRET = 'a-test-secret-that-is-at-least-32-characters';

function docsPerson(Organization $organization, ?Project $project, ?string $role = null, array $member = []): User
{
    $user = User::factory()->create();
    $person = Member::factory()->linkedTo($user)->create($member + ['organization_id' => $organization->id]);

    if ($project !== null && $role !== null) {
        $project->ensureTeam()->members()->attach($person->id, ['role' => $role]);
    }

    return $user;
}

function docsCreate(Project $project, User $user, string $title = 'Agreement', string $type = 'docx'): Document
{
    test()->actingAs($user)->post("/workspace/projects/{$project->slug}/documents", ['title' => $title, 'type' => $type])->assertRedirect();

    return Document::query()->where('project_id', $project->id)->where('title', $title)->latest('id')->firstOrFail();
}

/** @param  array<string, mixed>  $payload */
function docsToken(array $payload, string $operation, string $secret = DOCS_SECRET): string
{
    return JWT::encode(['payload' => $payload, 'operation' => $operation], $secret, 'HS256');
}

function docsCallback(Document $document, array $payload, ?string $token = null): TestResponse
{
    auth()->logout();

    return test()->withHeader('Authorization', 'Bearer '.($token ?? docsToken($payload, 'callback')))
        ->postJson(URL::signedRoute('documents.callback', ['document' => $document->id]), $payload);
}

/** A real docx, different from the blank one (its bytes plus a comment in the zip). */
function docsEditedFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'edited').'.docx';
    copy(resource_path('documents/blank.docx'), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setArchiveComment('edited '.uniqid());
    $zip->close();

    return (string) file_get_contents($path);
}

/**
 * A document as the create service makes one (no file): personal when
 * $project is null, owned by $by.
 *
 * @param  array<string, mixed>  $attributes
 */
function docsMake(User $by, ?Project $project = null, string $title = 'Notes', array $attributes = []): Document
{
    $document = new Document(['project_id' => $project?->id, 'title' => $title, 'file_type' => 'docx']);
    $document->owner_id = $project === null ? $by->id : null;
    $document->version = 1;
    $document->editor_key = Document::newEditorKey();
    $document->size = 100;
    $document->created_by = $by->id;
    $document->updated_by = $by->id;
    $document->forceFill($attributes)->save();

    return $document;
}

/** Shares $document with $user's person. */
function docsShare(Document $document, User $user, string $permission = 'edit'): DocumentShare
{
    return DocumentShare::query()->create([
        'document_id' => $document->id,
        'member_id' => WorkspaceAccess::memberFor($user)?->id,
        'permission' => $permission,
        'shared_by' => $document->owner_id ?? $document->created_by,
    ]);
}

/**
 * Changes $user's person and forgets every memo, as a new request would.
 *
 * @param  array<string, mixed>  $attributes
 */
function docsUpdatePerson(User $user, array $attributes): void
{
    WorkspaceAccess::memberFor($user)?->update($attributes);
    WorkspaceAccess::flush();
}

/** Gives a document made with docsMake its first version's file. */
function docsAddFile(Document $document): void
{
    $path = DocumentVersion::pathFor($document, 1);
    Storage::disk('documents')->put($path, (string) file_get_contents(resource_path('documents/blank.docx')));
    DocumentVersion::query()->create([
        'document_id' => $document->id,
        'version' => 1,
        'path' => $path,
        'size' => 100,
        'sha256' => str_repeat('0', 64),
        'user_id' => $document->created_by,
        'source' => 'created',
    ]);
}

/**
 * The editor config the editor page was given, as the editor reads its
 * signed token.
 *
 * @return array<string, mixed>
 */
function docsEditorConfig(TestResponse $response): array
{
    $token = $response->viewData('page')['props']['editor']['config']['token'];

    return json_decode((string) json_encode(JWT::decode($token, new Key(DOCS_SECRET, 'HS256'))), true);
}
