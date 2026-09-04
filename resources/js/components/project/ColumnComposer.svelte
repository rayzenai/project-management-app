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

<form
    onsubmit={submit}
    class="flex flex-col gap-2 rounded-lg border border-line bg-raised p-2"
>
    <input
        type="text"
        bind:this={input}
        bind:value={form.title}
        placeholder="Add a task"
        class="input"
        onkeydown={(e) => {
            if (e.key === 'Escape') {
                form.title = '';
                onClose?.();
            }
        }}
    />
    {#if form.errors.title}
        <p class="text-xs text-danger">{form.errors.title}</p>
    {/if}
    <div class="flex items-center justify-end gap-1.5">
        <button
            type="button"
            class="btn-ghost"
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
            class="btn-primary"
        >
            Add
        </button>
    </div>
</form>
