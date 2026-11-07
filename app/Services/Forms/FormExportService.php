<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormFile;
use App\Queries\Forms\FormResponsesQuery;
use App\Services\Documents\CreateDocumentService;
use App\Support\Forms\FormAnswerText;
use App\Support\Forms\FormSchema;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Responses as a spreadsheet: one row per response (who, when, stage, then
 * a column per question, earlier versions' questions last) and a sheet for
 * each repeating group, its rows linked by reference. Downloaded as .xlsx,
 * or saved as a personal document of the person exporting and opened in
 * ONLYOFFICE: never a project document, which the whole team could open. A
 * snapshot: exporting again makes a new one.
 */
class FormExportService
{
    public function __construct(private FormResponsesQuery $responses, private CreateDocumentService $documents) {}

    /**
     * Writes the filtered responses to a temporary .xlsx and returns its path.
     *
     * @param  array<string, mixed>  $filters
     */
    public function write(Form $form, array $filters): string
    {
        $questions = $this->responses->questions($form);
        $rows = $this->responses->filtered($form, $filters)->with('member')->orderBy('submitted_at')->orderBy('id')
            ->limit((int) config('forms.max_export_rows', 5000))->get();
        $names = FormFile::query()->whereIn('form_response_id', $rows->modelKeys())->pluck('name', 'id')->all();
        $stages = array_column($form->stages(), 'name', 'id');
        $language = $form->languages()[0] ?? 'en';
        $path = tempnam(sys_get_temp_dir(), 'form-export').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Responses');
        $plain = array_values(array_filter($questions, fn (array $q): bool => $q['type'] !== 'group'));
        $groups = array_values(array_filter($questions, fn (array $q): bool => $q['type'] === 'group'));

        $writer->addRow(Row::fromValues([
            'Reference', 'Sent', 'Changed', 'From', 'Designation', 'Organization', 'Stage',
            ...array_map(fn (array $q): string => FormSchema::label($q, $language).($q['earlier'] ? ' (earlier question)' : ''), $plain),
            ...array_map(fn (array $q): string => FormSchema::label($q, $language).' (entries)', $groups),
        ]));

        foreach ($rows as $response) {
            $writer->addRow(Row::fromValues([
                $response->reference,
                $response->submitted_at->format('Y-m-d H:i'),
                $response->updated_at?->format('Y-m-d H:i') ?? '',
                $response->sender['name'] ?? '',
                $response->sender['designation'] ?? '',
                $response->sender['organization'] ?? '',
                $stages[$response->stage ?? ''] ?? '',
                ...array_map(fn (array $q): string => FormAnswerText::of($q, $response->answers[$q['id']] ?? null, $language, $names), $plain),
                ...array_map(fn (array $q): string => FormAnswerText::of($q, $response->answers[$q['id']] ?? null, $language, $names), $groups),
            ]));
        }

        foreach ($groups as $index => $group) {
            $children = FormSchema::blocks($group);
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(($index + 2).' '.preg_replace('/[\[\]:*?\/\\\\]/', ' ', FormSchema::label($group, $language)), 0, 31));
            $writer->addRow(Row::fromValues(['Reference', 'Entry', ...array_map(fn (array $c): string => FormSchema::label($c, $language), $children)]));

            foreach ($rows as $response) {
                foreach (array_values(is_array($response->answers[$group['id']] ?? null) ? $response->answers[$group['id']] : []) as $i => $entry) {
                    $writer->addRow(Row::fromValues([
                        $response->reference,
                        $i + 1,
                        ...array_map(fn (array $c): string => FormAnswerText::of($c, is_array($entry) ? ($entry[$c['id']] ?? null) : null, $language, $names), $children),
                    ]));
                }
            }
        }

        $writer->close();

        return $path;
    }

    public function fileName(Form $form): string
    {
        $title = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $form->title)) ?: 'Form';

        return mb_substr($title, 0, 120).' responses '.now()->format('Y-m-d').'.xlsx';
    }

    /**
     * The same spreadsheet as a personal document of the person exporting.
     *
     * @param  array<string, mixed>  $filters
     */
    public function toDocument(Form $form, array $filters, Authenticatable $user): ServiceResult
    {
        $path = $this->write($form, $filters);

        try {
            $name = $this->fileName($form);
            $file = new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            return $this->documents->upload(null, $file, pathinfo($name, PATHINFO_FILENAME), $user);
        } finally {
            @unlink($path);
        }
    }
}
