<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import type { Note, SharedProps, WorkspaceNote } from '../lib/types';
    import NoteSticky from './NoteSticky.svelte';

    let {
        stickyNotes = [],
        taskNotes,
        compose = true,
    }: {
        stickyNotes?: WorkspaceNote[];
        // Optional: falls back to the globally shared `taskNotes` prop when the
        // parent doesn't pass an explicit (page-scoped) list.
        taskNotes?: Note[];
        /** Sticky-only strips show the composer; the task strip is read-only. */
        compose?: boolean;
    } = $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const resolvedTaskNotes = $derived(taskNotes ?? shared.taskNotes ?? []);

    const FREEFORM_PREVIEW = 6;
    const TASK_PREVIEW = 8;

    const freeformPreview = $derived(stickyNotes.slice(0, FREEFORM_PREVIEW));
    const taskPreview = $derived(resolvedTaskNotes.slice(0, TASK_PREVIEW));
    const overflow = $derived(
        stickyNotes.length -
            freeformPreview.length +
            (resolvedTaskNotes.length - taskPreview.length),
    );
</script>

<div class="flex flex-wrap items-center gap-2.5">
    {#each freeformPreview as note (`w-${note.id}`)}
        <NoteSticky kind="freeform" {note} />
    {/each}

    {#each taskPreview as note (`t-${note.id}`)}
        <NoteSticky kind="task" {note} />
    {/each}

    {#if compose}
        <button
            type="button"
            onclick={() => notesBoard.show({ compose: true })}
            aria-label="New note"
            class="btn"
        >
            <Plus class="h-3.5 w-3.5" />
            New note
        </button>
    {/if}

    {#if overflow > 0}
        <button
            type="button"
            onclick={() => notesBoard.show()}
            class="btn-ghost tabular-nums">{overflow} more</button
        >
    {/if}
</div>
