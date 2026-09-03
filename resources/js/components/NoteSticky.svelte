<script lang="ts">
    import { paperClass, tilt, noteTypeColor } from '../lib/noteColors';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import type { Note, WorkspaceNote } from '../lib/types';

    type FreeformProps = { kind: 'freeform'; note: WorkspaceNote };
    type TaskProps = { kind: 'task'; note: Note };

    let props: FreeformProps | TaskProps = $props();

    const color = $derived(
        props.kind === 'task'
            ? noteTypeColor(props.note.type)
            : (props.note as WorkspaceNote).color,
    );

    const taskHref = $derived.by(() => {
        if (props.kind !== 'task') {
            return null;
        }

        const task = props.note.task;

        if (!task?.project?.slug || !task.slug) {
            return null;
        }

        return `/workspace/projects/${task.project.slug}/tasks/${task.slug}?tab=notes`;
    });
</script>

{#if props.kind === 'freeform'}
    <button
        type="button"
        onclick={() => notesBoard.show({ noteId: props.note.id })}
        title={props.note.title || props.note.body}
        style:transform={`rotate(${tilt(props.note.id)}deg)`}
        class={`flex h-20 w-32 flex-col overflow-hidden rounded-md border p-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md ${paperClass[color]}`}
    >
        {#if props.note.title}
            <span
                class="line-clamp-1 text-xs font-bold text-neutral-900 dark:text-neutral-50"
                >{props.note.title}</span
            >
        {/if}
        <span
            class="line-clamp-3 text-[11px] leading-snug text-neutral-800 dark:text-neutral-100"
            >{props.note.body}</span
        >
    </button>
{:else}
    {@const href = taskHref}
    <svelte:element
        this={href ? 'a' : 'div'}
        {href}
        title={props.note.body}
        style:transform={`rotate(${tilt(props.note.id)}deg)`}
        class={`flex h-20 w-32 flex-col overflow-hidden rounded-md border p-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md ${paperClass[color]}`}
    >
        <span
            class="mb-0.5 line-clamp-1 text-[9px] font-bold tracking-wider text-neutral-700/80 uppercase dark:text-neutral-200/80"
        >
            {props.note.type_label}
        </span>
        <span
            class="line-clamp-2 flex-1 text-[11px] leading-snug text-neutral-800 dark:text-neutral-100"
            >{props.note.body}</span
        >
        {#if props.note.task}
            <span
                class="mt-0.5 line-clamp-1 text-[10px] font-medium text-neutral-700/70 dark:text-neutral-200/70"
            >
                on: {props.note.task.short_title || props.note.task.title}
            </span>
        {/if}
    </svelte:element>
{/if}
