<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import type { Project } from '../../lib/types';

    let {
        project,
        status,
        onClose,
    }: { project: Project; status: string; onClose?: () => void } = $props();

    let input = $state<HTMLInputElement | null>(null);
    const form = useForm(untrack(() => ({ title: '', status })));

    $effect(() => {
        input?.focus();
    });

    function submit(e: SubmitEvent) {
        e.preventDefault();

        if (!form.title.trim()) {
            return;
        }

        form.status = status;
        form.post(`/workspace/projects/${project.slug}/tasks`, {
            preserveScroll: true,
            onSuccess: () => {
                // Stay open and keep focus for rapid entry.
                form.title = '';
                input?.focus();
            },
        });
    }
</script>

<form onsubmit={submit} class="rounded-lg border border-accent bg-surface p-2">
    <input
        type="text"
        bind:this={input}
        bind:value={form.title}
        placeholder="Add a task…"
        class="w-full bg-transparent text-sm text-fg outline-none placeholder:text-fg-faint"
        onkeydown={(e) => {
            if (e.key === 'Escape') {
                form.title = '';
                onClose?.();
            }
        }}
    />
    {#if form.errors.title}
        <p class="mt-1 text-xs text-danger">{form.errors.title}</p>
    {/if}
    <div class="mt-2 flex items-center justify-end gap-2">
        <button
            type="button"
            class="text-xs text-fg-muted hover:text-fg"
            onclick={() => {
                form.title = '';
                onClose?.();
            }}
        >
            Cancel
        </button>
        <button
            type="submit"
            disabled={form.processing || !form.title.trim()}
            class="rounded-md bg-accent px-2.5 py-1 text-xs font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
        >
            Add
        </button>
    </div>
</form>
