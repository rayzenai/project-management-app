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
 * its person (DocumentShared, also emailed), and not again about the same
 * document within a day; changing sharing never counts
 * as editing the document (updated_at stays). A project document's changes
 * go into its activity (DocumentObserver, DocumentShareObserver).
 */
class DocumentSharingService
{
    /** A person is told about the same document being shared at most once in this many hours. */
    public const NOTIFY_AGAIN_AFTER_HOURS = 24;

    public function setOrganizationAccess(Document $document, ?string $access): ServiceResult
    {
        Document::withoutTimestamps(function () use ($document, $access): void {
            $document->organization_access = $access;
            $document->save();
        });

        $organizationId = WorkspaceAccess::documentOrganizationId($document);
        $organization = $organizationId === null ? __('the organization') : (string) Organization::query()->whereKey($organizationId)->value('name');

        $replace = ['organization' => $organization, 'title' => $document->title];

        return ServiceResult::success($document, match ($access) {
            Document::ACCESS_EDIT => __('Everyone in :organization can edit :title.', $replace),
            Document::ACCESS_VIEW => __('Everyone in :organization can view :title.', $replace),
            default => __(':title is no longer open to everyone in :organization.', $replace),
        });
    }

    public function share(Document $document, Member $member, string $permission, Authenticatable $user): ServiceResult
    {
        // firstOrCreate inserts with createOrFirst: two identical shares at
        // the same moment make one row, the second finding the first's.
        $share = DocumentShare::query()->firstOrCreate(
            ['document_id' => $document->id, 'member_id' => $member->id],
            ['permission' => $permission, 'shared_by' => (int) $user->getAuthIdentifier()],
        );

        if ($share->permission !== $permission) {
            $share->permission = $permission;
            $share->save();
        }

        $recipient = $member->user;

        if ($share->wasRecentlyCreated && $recipient instanceof User && $recipient->id !== (int) $user->getAuthIdentifier()
            && WorkspaceAccess::documentAccess($recipient, $document) !== null
            && ! $this->toldRecently($recipient, $document)) {
            $recipient->notify(new DocumentShared($document, $user instanceof User ? $user->name : 'Someone', $permission));
        }

        return ServiceResult::success($share->load('member.designation'), __('Shared :title with :name.', ['title' => $document->title, 'name' => $member->name]));
    }

    /**
     * Whether the person was told this document was shared with them in the
     * last NOTIFY_AGAIN_AFTER_HOURS: taking a share away and giving it back
     * tells them once a day at most.
     */
    private function toldRecently(User $recipient, Document $document): bool
    {
        return $recipient->notifications()
            ->where('type', DocumentShared::class)
            ->where('created_at', '>=', now()->subHours(self::NOTIFY_AGAIN_AFTER_HOURS))
            ->get(['data'])
            ->contains(fn ($notification): bool => ($notification->data['document']['id'] ?? null) === $document->id);
    }

    public function changePermission(DocumentShare $share, string $permission): ServiceResult
    {
        $share->permission = $permission;
        $share->save();

        $replace = ['name' => $share->member->name, 'title' => $share->document->title];

        return ServiceResult::success($share->load('member.designation'), $permission === Document::ACCESS_EDIT
            ? __(':name can now edit :title.', $replace)
            : __(':name can now view :title.', $replace));
    }

    public function unshare(DocumentShare $share): ServiceResult
    {
        $share->delete();

        return ServiceResult::success(null, __(':name no longer has :title.', ['name' => $share->member->name, 'title' => $share->document->title]));
    }
}
