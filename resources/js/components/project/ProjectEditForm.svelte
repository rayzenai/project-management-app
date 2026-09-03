<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import type { Project } from '../../lib/types';

    let {
        project,
        isSuperAdmin = false,
        onclose,
    }: {
        project: Project;
        isSuperAdmin?: boolean;
        onclose: () => void;
    } = $props();

    const uid = $props.id();

    // Seeded once on mount — the parent renders this behind an {#if}, so re-opening
    // the form remounts it with fresh values. Read inside a function so the one-time
    // capture is deliberate rather than a `state_referenced_locally` accident.
    function seed() {
        return {
            title: project.title ?? '',
            title_np: project.title_np ?? '',
            description: project.description ?? '',
            description_np: project.description_np ?? '',
            is_public: project.is_public ?? false,
        };
    }

    const form = useForm(seed());

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.patch(`/workspace/projects/${project.slug}`, {
            preserveScroll: true,
            onSuccess: () => onclose(),
        });
    }
</script>

<form
    onsubmit={submit}
    class="mt-3 rounded-xl border border-line bg-surface p-4"
>
    <h2 class="mb-4 text-lg font-bold tracking-tight text-fg">Edit project</h2>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label
                for={`${uid}-title`}
                class="mb-1 block text-xs font-medium text-fg-muted"
                >Title</label
            >
            <input
                id={`${uid}-title`}
                type="text"
                bind:value={form.title}
                required
                class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-fg"
            />
            {#if form.errors.title}<p class="mt-1 text-xs text-danger">
                    {form.errors.title}
                </p>{/if}
        </div>
        <div>
            <label
                for={`${uid}-title-np`}
                class="mb-1 block text-xs font-medium text-fg-muted"
                >Title (Nepali)</label
            >
            <input
                id={`${uid}-title-np`}
                type="text"
                bind:value={form.title_np}
                class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-fg"
            />
            {#if form.errors.title_np}<p class="mt-1 text-xs text-danger">
                    {form.errors.title_np}
                </p>{/if}
        </div>
    </div>

    <div class="mt-3">
        <label
            for={`${uid}-description`}
            class="mb-1 block text-xs font-medium text-fg-muted"
            >Description</label
        >
        <textarea
            id={`${uid}-description`}
            bind:value={form.description}
            rows="2"
            class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-fg"
        ></textarea>
        {#if form.errors.description}<p class="mt-1 text-xs text-danger">
                {form.errors.description}
            </p>{/if}
    </div>

    <div class="mt-3">
        <label
            for={`${uid}-description-np`}
            class="mb-1 block text-xs font-medium text-fg-muted"
            >Description (Nepali)</label
        >
        <textarea
            id={`${uid}-description-np`}
            bind:value={form.description_np}
            rows="2"
            class="w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-fg"
        ></textarea>
        {#if form.errors.description_np}<p class="mt-1 text-xs text-danger">
                {form.errors.description_np}
            </p>{/if}
    </div>

    {#if isSuperAdmin}
        <label class="mt-4 flex items-center gap-2 text-sm text-fg-muted">
            <input type="checkbox" bind:checked={form.is_public} /> Public (visible
            to everyone)
        </label>
    {/if}

    <div class="mt-4 flex items-center justify-end gap-2">
        <button
            type="button"
            class="rounded-md border border-line px-3 py-1.5 text-sm font-medium text-fg-muted transition hover:bg-surface-alt"
            onclick={onclose}>Cancel</button
        >
        <button
            type="submit"
            disabled={form.processing}
            class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
            >Save</button
        >
    </div>
</form>
