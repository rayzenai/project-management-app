<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Documents\DocumentFiles;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What the editor reports about a document (its callback, already verified:
 * `$payload` is the signed token's, never the request body).
 *
 *   1 editing, 4 closed with no changes: nothing to do.
 *   2 everyone closed it, 6 a forced save: fetch the saved file from the
 *     editor's own host and keep it as the next version.
 *   3 and 7 are 2 and 6 where the editor hit an error building the file:
 *     kept the same way (ONLYOFFICE's own integrations do), with a warning,
 *     because the earlier versions are all still there.
 *   2 and 3 end the session: the document gets a new editor key, so the next
 *   session opens the saved file. 6 and 7 keep it: people are still editing.
 *
 * A key the document no longer has is a report about a session already
 * saved, answered as done (and logged when it carried a file). A failure is
 * a failure result, so the editor asks again. The same file twice in a row
 * is not a new version.
 */
class SaveEditedDocumentService
{
    /** The session is over: everyone closed it. */
    private const ENDS_SESSION = [2, 3];

    /** A forced save while people keep editing. */
    private const KEEPS_SESSION = [6, 7];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Document $document, array $payload): ServiceResult
    {
        $status = (int) ($payload['status'] ?? 0);

        if (! in_array($status, [...self::ENDS_SESSION, ...self::KEEPS_SESSION], true)) {
            return ServiceResult::success(['saved' => false]);
        }

        if (in_array($status, [3, 7], true)) {
            Log::warning('Documents: the editor hit an error building a saved document; keeping what it sent', ['document_id' => $document->id, 'status' => $status]);
        }

        if (($payload['key'] ?? null) !== $document->editor_key) {
            if (isset($payload['url'])) {
                Log::warning('Documents: dropped a save for a session already saved', ['document_id' => $document->id, 'status' => $status]);
            }

            return ServiceResult::success(['saved' => false]);
        }

        $url = $payload['url'] ?? null;

        if (! is_string($url) || ! self::onEditorHost($url)) {
            Log::warning('Documents: refused a saved file off the editor\'s host', ['document_id' => $document->id]);

            return ServiceResult::failure('The saved file is not on the editor.', 422);
        }

        $temp = tempnam(sys_get_temp_dir(), 'doc');

        try {
            $this->download($url, (string) $temp);

            $saved = DB::transaction(fn (): bool => $this->keep($document->id, $payload, $status, (string) $temp));
        } catch (Throwable $e) {
            // Not report($e): a connection error names the editor's signed
            // link to the file, which is a key to the document.
            Log::warning('Documents: couldn\'t keep a save; the editor will send it again', [
                'document_id' => $document->id,
                'status' => $status,
                'error' => $e::class.': '.preg_replace('#https?://\S+#', '[link]', $e->getMessage()),
            ]);

            return ServiceResult::failure("Couldn't save the document.", 502);
        } finally {
            @unlink((string) $temp);
        }

        return ServiceResult::success(['saved' => $saved]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function keep(int $documentId, array $payload, int $status, string $file): bool
    {
        $document = Document::withTrashed()->lockForUpdate()->findOrFail($documentId);

        // Another report saved this session while the file was coming.
        if (($payload['key'] ?? null) !== $document->editor_key) {
            return false;
        }

        $sha256 = (string) hash_file('sha256', $file);
        $current = DocumentVersion::query()->where('document_id', $document->id)->where('version', $document->version)->value('sha256');
        $changed = $current !== $sha256;

        if ($changed) {
            $next = $document->version + 1;
            $path = DocumentFiles::write($document, $next, $file);

            $userId = self::editorUserId($payload);

            DocumentVersion::query()->create([
                'document_id' => $document->id,
                'version' => $next,
                'path' => $path,
                'size' => (int) filesize($file),
                'sha256' => $sha256,
                'user_id' => $userId,
                'source' => DocumentVersion::SOURCE_EDITED,
            ]);

            $document->version = $next;
            $document->size = (int) filesize($file);
            $document->updated_by = $userId ?? $document->updated_by;
        }

        if (in_array($status, self::ENDS_SESSION, true)) {
            $document->editor_key = Document::newEditorKey();
        }

        $document->saveQuietly();

        return $changed;
    }

    private function download(string $url, string $to): void
    {
        $max = (int) config('documents.max_saved_bytes');

        $response = Http::timeout(120)
            ->withOptions([
                'sink' => $to,
                'allow_redirects' => false,
                'progress' => function (int $total, int $downloaded) use ($max): void {
                    if ($total > $max || $downloaded > $max) {
                        throw new RuntimeException('The saved file is too large.');
                    }
                },
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("The editor answered {$response->status()} for the saved file.");
        }

        clearstatcache(true, $to);
        $size = (int) filesize($to);

        if ($size === 0 || $size > $max) {
            throw new RuntimeException("The saved file is {$size} bytes.");
        }
    }

    /** Only the configured editor's own origin serves saved files. */
    private static function onEditorHost(string $url): bool
    {
        $server = parse_url((string) config('documents.server_url'));
        $file = parse_url($url);

        return is_array($server) && is_array($file)
            && ($file['scheme'] ?? null) === ($server['scheme'] ?? null)
            && strtolower((string) ($file['host'] ?? '')) === strtolower((string) ($server['host'] ?? ''))
            && ($file['port'] ?? null) === ($server['port'] ?? null)
            && ! isset($file['user']) && ! isset($file['pass']);
    }

    /**
     * The tracker user the editor names as the last to edit (the config's
     * `user.id` is the user's id), when they still exist.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function editorUserId(array $payload): ?int
    {
        $users = $payload['users'] ?? [];
        $id = is_array($users) ? ($users[0] ?? null) : null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        if (! ctype_digit((string) $id)) {
            return null;
        }

        return User::query()->whereKey((int) $id)->exists() ? (int) $id : null;
    }
}
