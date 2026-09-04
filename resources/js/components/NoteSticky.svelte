<script lang="ts">
    import { Link2 } from '@lucide/svelte';
    import { noteTypeColor, paperClass, tilt } from '../lib/noteColors';
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

    const paper =
        'flex h-20 w-32 flex-col overflow-hidden rounded-md border p-2 text-left shadow-[0_1px_2px_rgba(0,0,0,0.08)] transition hover:-translate-y-0.5';
</script>

{#if props.kind === 'freeform'}
    <button
        type="button"
        onclick={() => notesBoard.show({ noteId: props.note.id })}
        title={props.note.title || props.note.body}
        style:transform={`rotate(${tilt(props.note.id)}deg)`}
        class={`${paper} ${paperClass[color]}`}
    >
        {#if props.note.title}
            <span class="line-clamp-1 text-xs font-medium text-fg"
                >{props.note.title}</span
            >
        {/if}
        <span class="line-clamp-3 text-xs leading-snug text-fg-muted"
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
        class={`${paper} ${paperClass[color]}`}
    >
        <span
            class="mb-0.5 flex items-center gap-1 text-[11px] font-medium text-fg-muted"
        >
            <Link2 class="h-3 w-3 shrink-0" />
            <span class="line-clamp-1">{props.note.type_label}</span>
        </span>
        <span class="line-clamp-2 flex-1 text-xs leading-snug text-fg-muted"
            >{props.note.body}</span
        >
        {#if props.note.task}
            <span class="mt-0.5 line-clamp-1 text-[11px] text-fg-faint">
                {props.note.task.short_title || props.note.task.title}
            </span>
        {/if}
    </svelte:element>
{/if}
