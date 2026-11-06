<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\DocumentShared;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who has a document beyond its home: everyone in its organization
 * (`organization_access`) and chosen people (`DocumentShare`). The requests
 * have checked canShareDocument and whom it goes to. Only a new share tells
 * its person (DocumentShared, also emailed); changing sharing never counts
 * as editing the document (updated_at stays). A project document's changes
 * go into its activity (DocumentObserver, DocumentShareObserver).
 */
class DocumentSharingService
{
    public function setOrganizationAccess(Document $document, ?string $access): ServiceResult
    {
        Document::withoutTimestamps(function () use ($document, $access): void {
            $document->organization_access = $access;
            $document->save();
        });

        $organizationId = WorkspaceAccess::documentOrganizationId($document);
        $organization = $organizationId === null ? 'the organization' : (string) Organization::query()->whereKey($organizationId)->value('name');

        return ServiceResult::success($document, match ($access) {
            Document::ACCESS_EDIT => "Everyone in {$organization} can edit {$document->title}.",
            Document::ACCESS_VIEW => "Everyone in {$organization} can view {$document->title}.",
            default => "{$document->title} is no longer open to everyone in {$organization}.",
        });
    }

    public function share(Document $document, Member $member, string $permission, Authenticatable $user): ServiceResult
    {
        $share = DocumentShare::query()->firstOrNew(['document_id' => $document->id, 'member_id' => $member->id]);
        $isNew = ! $share->exists;
        $share->permission = $permission;
        $share->shared_by ??= (int) $user->getAuthIdentifier();
        $share->save();

        $recipient = $member->user;

        if ($isNew && $recipient instanceof User && $recipient->id !== (int) $user->getAuthIdentifier()
            && WorkspaceAccess::documentAccess($recipient, $document) !== null) {
            $recipient->notify(new DocumentShared($document, $user instanceof User ? $user->name : 'Someone', $permission));
        }

        return ServiceResult::success($share->load('member.designation'), "Shared {$document->title} with {$member->name}.");
    }

    public function changePermission(DocumentShare $share, string $permission): ServiceResult
    {
        $share->permission = $permission;
        $share->save();

        $can = $permission === Document::ACCESS_EDIT ? 'edit' : 'view';

        return ServiceResult::success($share->load('member.designation'), "{$share->member->name} can now {$can} {$share->document->title}.");
    }

    public function unshare(DocumentShare $share): ServiceResult
    {
        $share->delete();

        return ServiceResult::success(null, "{$share->member->name} no longer has {$share->document->title}.");
    }
}
