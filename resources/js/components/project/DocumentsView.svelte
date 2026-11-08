<script lang="ts">
    import { number, t } from '../../lib/i18n';
    import type { Project, ProjectDocuments } from '../../lib/types';
    import DocumentList from '../documents/DocumentList.svelte';
    import DocumentStart from '../documents/DocumentStart.svelte';
    import DocumentTrash from '../documents/DocumentTrash.svelte';

    /**
     * A project's Documents tab: its Word, Excel and PowerPoint files, each
     * opened in the editor (ONLYOFFICE) where the team edits it together.
     * They start as on the Documents page (a tile per kind, upload, or a
     * file dropped on the page), here always into this project. Every save
     * is a version kept for good; the trash is never emptied. Every
     * document, the project's and personal ones, is on the Documents page
     * too.
     */
    let {
        project,
        documents,
    }: {
        project: Project;
        documents: ProjectDocuments;
    } = $props();
</script>

<section>
    <h2 class="section-title mb-1">
        {t('Documents')}
        <span class="section-count">{number(documents.documents.length)}</span>
    </h2>
    <p class="mb-3 text-xs text-fg-muted">
        {t(
            'Word, Excel and PowerPoint files the team edits together. Every save is kept as a version.',
        )}
    </p>
    <DocumentStart
        types={documents.types}
        maxUploadMb={documents.max_upload_mb}
        project={project.slug}
    />

    {#if documents.documents.length === 0}
        <p class="mt-4 text-[13px] text-fg-muted">
            {t(
                'No documents yet. Start one above, or drop a .docx, .xlsx or .pptx file of up to :max MB anywhere on this page.',
                { max: documents.max_upload_mb },
            )}
        </p>
    {:else}
        <div class="mt-4">
            <DocumentList documents={documents.documents} />
        </div>
    {/if}

    <DocumentTrash
        url={`/workspace/documents/trash?project=${encodeURIComponent(project.slug)}`}
        count={documents.trashed_count}
    />
</section>
