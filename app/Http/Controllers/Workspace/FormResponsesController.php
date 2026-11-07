<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Forms\FormResponsesRequest;
use App\Http\Requests\Forms\MoveFormResponsesRequest;
use App\Http\Requests\Forms\StoreFormNoteRequest;
use App\Models\Document;
use App\Models\Form;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Models\Organization;
use App\Queries\Forms\FormItems;
use App\Queries\Forms\FormRespondentsQuery;
use App\Queries\Forms\FormResponsesQuery;
use App\Queries\Forms\FormSummaryQuery;
use App\Services\Forms\FormExportService;
use App\Services\Forms\FormResponseActionsService;
use App\Services\Forms\RemindFormService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * A form's responses, for whoever may see them: the page, the table's next
 * rows, one response, stages, notes, the summary, who hasn't answered,
 * Remind, and the exports (an .xlsx download, the same as a personal
 * document, and the files as one ZIP).
 */
class FormResponsesController extends Controller
{
    use RedirectsWithServiceResult;
    use RespondsWithServiceResult;

    public function index(FormResponsesRequest $request, FormResponsesQuery $responses): Response
    {
        $form = $request->form()->loadMissing(['project.program', 'project.organization', 'respondentOrganizations']);
        $organizations = Organization::query()->whereIn('id', [$form->project->organization_id, ...$form->respondentOrganizations->modelKeys()])->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Forms/Responses', [
            'form' => [...FormItems::of($form, $request->user()), 'stages' => $form->stages(), 'languages' => $form->languages()],
            'questions' => $responses->questions($form),
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->all(),
            'page' => $responses->page($form, $request->filters(), null),
            'filters' => $request->filters(),
            'open_response' => $request->integer('response') ?: null,
            'can_edit' => WorkspaceAccess::canEditForm($request->user(), $form),
            'can_open_as_spreadsheet' => WorkspaceAccess::canCreatePersonalDocuments($request->user()),
        ]);
    }

    public function list(FormResponsesRequest $request, FormResponsesQuery $responses): JsonResponse
    {
        return response()->json(['data' => $responses->page($request->form(), $request->filters(), $request->validated('cursor'))]);
    }

    public function show(FormResponsesRequest $request, Form $form, FormResponse $response, FormResponsesQuery $responses): JsonResponse
    {
        abort_unless($response->form_id === $form->id && ! $response->is_test, 404);

        return response()->json(['data' => $responses->show($response)]);
    }

    public function move(MoveFormResponsesRequest $request, FormResponseActionsService $service): JsonResponse
    {
        return $this->respondWithResult($service->move($request->form(), $request->ids(), (string) $request->validated('stage'), $request->validated('note'), $request->user()));
    }

    public function note(StoreFormNoteRequest $request, Form $form, FormResponse $response, FormResponseActionsService $service): JsonResponse
    {
        abort_unless($response->form_id === $form->id, 404);

        return $this->respondWithResult($service->note($response, (string) $request->validated('text'), $request->user()), null, 201);
    }

    public function summary(FormResponsesRequest $request, FormSummaryQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->for($request->form())]);
    }

    public function pending(FormResponsesRequest $request, FormRespondentsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->pending($request->form())]);
    }

    public function remind(FormResponsesRequest $request, RemindFormService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($request->form(), $request->user()));
    }

    public function download(FormResponsesRequest $request, FormExportService $export): BinaryFileResponse
    {
        $form = $request->form();

        return response()->download($export->write($form, $request->filters()), $export->fileName($form), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    public function spreadsheet(FormResponsesRequest $request, FormExportService $export): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canCreatePersonalDocuments($request->user()), 404);
        $result = $export->toDocument($request->form(), $request->filters(), $request->user());

        if ($result->success && $result->data instanceof Document) {
            return redirect()->route('workspace.documents.show', ['document' => $result->data->id])
                ->with('workspace_flash', ['success' => true, 'message' => 'Saved the responses as '.$result->data->title.', in your documents.']);
        }

        return $this->redirectWithResult($result);
    }

    public function files(FormResponsesRequest $request, FormResponsesQuery $responses): BinaryFileResponse|JsonResponse
    {
        $form = $request->form();
        $ids = $responses->filtered($form, $request->filters())->pluck('id');
        $files = FormFile::query()->where('form_id', $form->id)->where('purpose', FormFile::PURPOSE_ANSWER)->whereIn('form_response_id', $ids)->with('response:id,reference')->get();

        if ($files->sum('size') > (int) config('forms.max_zip_mb', 500) * 1024 * 1024) {
            return response()->json(['message' => 'Those files are too many to download at once. Filter the responses first.'], 422);
        }

        $path = tempnam(sys_get_temp_dir(), 'form-files').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $disk = Storage::disk((string) config('documents.disk'));
        $used = [];
        // Each file goes through a temporary copy on disk, which the archive
        // reads when it closes, so a large ZIP never sits in memory.
        $copies = [];

        foreach ($files as $file) {
            $name = ($file->response->reference ?? 'response').'/'.str_replace(['/', '\\'], '-', $file->name);

            while (isset($used[$name])) {
                $name = preg_replace('/(\.[^.]*)?$/', ' ('.$file->id.')$1', $name, 1) ?? $name.$file->id;
            }

            $used[$name] = true;
            $copy = (string) tempnam(sys_get_temp_dir(), 'form-file');
            $in = $disk->readStream($file->path);
            $out = fopen($copy, 'wb');

            if (is_resource($in) && $out !== false) {
                stream_copy_to_stream($in, $out);
            }

            if (is_resource($in)) {
                fclose($in);
            }

            if ($out !== false) {
                fclose($out);
            }

            $copies[] = $copy;
            $zip->addFile($copy, $name);
        }

        if ($files->isEmpty()) {
            $zip->addFromString('No files.txt', 'None of these responses has a file.');
        }

        $zip->close();

        foreach ($copies as $copy) {
            @unlink($copy);
        }

        return response()->download($path, trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $form->title)).' files.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
