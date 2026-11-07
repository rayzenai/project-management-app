<?php

namespace App\Queries\Documents;

use App\Models\Document;
use App\Models\User;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\EditorToken;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\URL;

/**
 * What the page hands ONLYOFFICE to open a document: where the editor is,
 * and its config, signed as `token` with the shared secret so the editor
 * trusts nothing the browser changed. The file link expires; the save link
 * does not (the editor reports a save after everyone has closed it). The
 * token expires with the file link: a page brought back from the browser's
 * history later (after a sign-out, say) can't open the editor again, while
 * an editor already open keeps its own session. The person is named by id
 * and name, never email. `$access` is the viewer's level
 * (WorkspaceAccess::documentAccess): view opens the editor read-only.
 */
class DocumentEditorQuery
{
    /**
     * @return array{server_url: string, config: array<string, mixed>}
     */
    public function for(Document $document, User $viewer, string $access): array
    {
        $edit = $access === Document::ACCESS_EDIT;
        $project = $document->project;
        $back = $project !== null && WorkspaceAccess::canUseProjectDocuments($viewer, $project)
            ? ['url' => route('workspace.projects.show', ['project' => $project->slug, 'view' => 'documents']), 'text' => __('Back to :project', ['project' => $project->title])]
            : ['url' => url('/workspace/documents'), 'text' => __('Back to Documents')];

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
                // Can view opens read-only; the signed token keeps the
                // browser from turning it into an editing session.
                'permissions' => [
                    'edit' => $edit,
                    'comment' => $edit,
                    'review' => $edit,
                    'fillForms' => $edit,
                    'modifyFilter' => $edit,
                    'modifyContentControl' => $edit,
                    'download' => true,
                    'print' => true,
                    // Nothing keeps the editor's chat; talk on the task instead.
                    'chat' => false,
                ],
            ],
            'editorConfig' => [
                'mode' => $edit ? 'edit' : 'view',
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
                    'goback' => $back + ['blank' => false],
                    'uiTheme' => match ($viewer->appearance()['theme']) {
                        'light' => 'default-light',
                        'dark' => 'default-dark',
                        default => null,
                    },
                ], fn (mixed $value): bool => $value !== null),
            ],
        ];

        $minutes = (int) config('documents.file_link_minutes');
        $config['token'] = EditorToken::sign($config + ['iat' => now()->timestamp, 'exp' => now()->addMinutes($minutes)->timestamp]);

        return [
            'server_url' => (string) config('documents.server_url'),
            'config' => $config,
        ];
    }
}
