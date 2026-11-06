<script lang="ts">
    import type { Project, ProjectDocuments } from '../../lib/types';
    import DocumentList from '../documents/DocumentList.svelte';
    import DocumentTrash from '../documents/DocumentTrash.svelte';
    import NewDocument from '../documents/NewDocument.svelte';

    /**
     * A project's Documents tab: its Word, Excel and PowerPoint files, each
     * opened in the editor (ONLYOFFICE) where the team edits it together.
     * Every save is a version kept for good; the trash is never emptied.
     * Every document, the project's and personal ones, is on the Documents
     * page too.
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
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title mb-1">
                Documents <span class="section-count"
                    >{documents.documents.length}</span
                >
            </h2>
            <p class="text-xs text-fg-muted">
                Word, Excel and PowerPoint files the team edits together. Every
                save is kept as a version.
            </p>
        </div>
        <NewDocument
            types={documents.types}
            maxUploadMb={documents.max_upload_mb}
            project={project.slug}
        />
    </div>

    {#if documents.documents.length === 0}
        <p class="mt-3 text-[13px] text-fg-muted">
            No documents yet. Start one, or upload a .docx, .xlsx or .pptx file
            of up to {documents.max_upload_mb} MB.
        </p>
    {:else}
        <div class="mt-3">
            <DocumentList documents={documents.documents} />
        </div>
    {/if}

    <DocumentTrash
        url={`/workspace/documents/trash?project=${encodeURIComponent(project.slug)}`}
        count={documents.trashed_count}
    />
</section>
