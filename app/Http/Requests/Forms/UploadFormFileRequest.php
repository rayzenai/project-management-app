<?php

namespace App\Http\Requests\Forms;

use App\Models\FormFile;
use App\Services\Forms\FormFillService;
use App\Support\Forms\FormSchema;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Validator;

/**
 * Add a file. A content file (the builder's picture or download) needs Can
 * edit; an answer's file needs the person to be filling the form in (or the
 * form's side trying it), for a file question of the questions they see,
 * of a kind and size that question takes.
 */
class UploadFormFileRequest extends FormRouteRequest
{
    public function authorize(): bool
    {
        if ($this->isContent()) {
            return WorkspaceAccess::canEditForm($this->user(), $this->form());
        }

        return $this->boolean('test')
            ? WorkspaceAccess::canEditForm($this->user(), $this->form())
            : WorkspaceAccess::canAnswerForm($this->user(), $this->form());
    }

    public function isContent(): bool
    {
        return $this->input('purpose') === FormFile::PURPOSE_CONTENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'in:content,answer'],
            'block' => ['required', 'string', 'regex:'.FormSchema::ID_PATTERN],
            'file' => ['required', 'file', 'max:'.(FormSchema::maxUploadMb() * 1024)],
            'test' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $file = $this->file('file');
            $extension = strtolower((string) $file?->getClientOriginalExtension());

            if ($this->isContent()) {
                $kinds = $this->input('kind') === 'image' ? ['image'] : ['pdf', 'image', 'word', 'excel'];
                $max = FormSchema::maxUploadMb();
            } else {
                $question = FormSchema::allQuestions(FormFillService::schemaFor($this->form(), $this->boolean('test')))[(string) $this->input('block')] ?? null;

                if ($question === null || $question['type'] !== 'file') {
                    $validator->errors()->add('file', 'That question doesn\'t take files.');

                    return;
                }

                $kinds = (array) ($question['rules']['kinds'] ?? ['pdf', 'image']);
                $max = (int) ($question['rules']['max_mb'] ?? FormSchema::maxUploadMb());
            }

            $allowed = array_merge(...array_map(fn (string $kind): array => FormSchema::FILE_EXTENSIONS[$kind] ?? [], $kinds));

            if (! in_array($extension, $allowed, true)) {
                $validator->errors()->add('file', 'Add a '.implode(', ', array_map('strtoupper', $allowed)).' file.');
            } elseif (($file?->getSize() ?? 0) > $max * 1024 * 1024) {
                $validator->errors()->add('file', "Add a file of {$max} MB or less.");
            }
        });
    }
}
