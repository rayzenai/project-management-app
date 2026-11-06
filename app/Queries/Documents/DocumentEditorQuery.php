<?php

namespace App\Queries\Documents;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\EditorToken;
use Illuminate\Support\Facades\URL;

/**
 * What the page hands ONLYOFFICE to open a document: where the editor is,
 * and its config, signed as `token` with the shared secret so the editor
 * trusts nothing the browser changed. The file link expires; the save link
 * does not (the editor reports a save after everyone has closed it). The
 * person is named by id and name, never email.
 */
class DocumentEditorQuery
{
    /**
     * @return array{server_url: string, config: array<string, mixed>}
     */
    public function for(Document $document, Project $project, User $viewer): array
    {
        $config = [
            'documentType' => DocumentTypes::editorType($document->file_type),
            'document' => [
                'fileType' => $document->file_type,
                'key' => $document->editor_key,
                'title' => $document->fileName(),
                'url' => URL::temporarySignedRoute(
                    'documents.file',
                    now()->addMinutes((int) config('documents.file_link_minutes')),
                    ['document' => $document->id, 'version' => $document->version],
                ),
                'permissions' => [
                    'edit' => true,
                    'comment' => true,
                    'review' => true,
                    'fillForms' => true,
                    'modifyFilter' => true,
                    'modifyContentControl' => true,
                    'download' => true,
                    'print' => true,
                    // Nothing keeps the editor's chat; talk on the task instead.
                    'chat' => false,
                ],
            ],
            'editorConfig' => [
                'mode' => 'edit',
                'lang' => 'en',
                'callbackUrl' => URL::signedRoute('documents.callback', ['document' => $document->id]),
                'user' => ['id' => (string) $viewer->id, 'name' => $viewer->name],
                'customization' => array_filter([
                    // Nothing leaves SDOC's servers: no AI tools, no plugins
                    // (several call outside services), no macros.
                    'ai' => ['disabled' => true],
                    'plugins' => false,
                    'macros' => false,
                    'forcesave' => false,
                    'mentionShare' => false,
                    'feedback' => false,
                    'goback' => [
                        'url' => route('workspace.projects.show', ['project' => $project->slug, 'view' => 'documents']),
                        'text' => "Back to {$project->title}",
                        'blank' => false,
                    ],
                    'uiTheme' => match ($viewer->appearance()['theme']) {
                        'light' => 'default-light',
                        'dark' => 'default-dark',
                        default => null,
                    },
                ], fn (mixed $value): bool => $value !== null),
            ],
        ];

        $config['token'] = EditorToken::sign($config);

        return [
            'server_url' => (string) config('documents.server_url'),
            'config' => $config,
        ];
    }
}
