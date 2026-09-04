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

    // Seeded once on mount: the parent renders this behind an {#if}, so re-opening
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

<form onsubmit={submit} class="flex max-w-3xl flex-col gap-3">
    <h2 class="section-title">Edit project</h2>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
            <label for={`${uid}-title`} class="label">Title</label>
            <input
                id={`${uid}-title`}
                type="text"
                bind:value={form.title}
                required
                class="input"
            />
            {#if form.errors.title}
                <p class="text-xs text-danger">{form.errors.title}</p>
            {/if}
        </div>
        <div class="flex flex-col gap-1">
            <label for={`${uid}-title-np`} class="label">Title (Nepali)</label>
            <input
                id={`${uid}-title-np`}
                type="text"
                bind:value={form.title_np}
                class="input font-np"
            />
            {#if form.errors.title_np}
                <p class="text-xs text-danger">{form.errors.title_np}</p>
            {/if}
        </div>
    </div>

    <div class="flex flex-col gap-1">
        <label for={`${uid}-description`} class="label">Description</label>
        <textarea
            id={`${uid}-description`}
            bind:value={form.description}
            rows="2"
            class="input"
        ></textarea>
        {#if form.errors.description}
            <p class="text-xs text-danger">{form.errors.description}</p>
        {/if}
    </div>

    <div class="flex flex-col gap-1">
        <label for={`${uid}-description-np`} class="label"
            >Description (Nepali)</label
        >
        <textarea
            id={`${uid}-description-np`}
            bind:value={form.description_np}
            rows="2"
            class="input font-np"
        ></textarea>
        {#if form.errors.description_np}
            <p class="text-xs text-danger">{form.errors.description_np}</p>
        {/if}
    </div>

    {#if isSuperAdmin}
        <label class="flex items-center gap-2 text-fg-muted">
            <input type="checkbox" bind:checked={form.is_public} />
            Public (visible to everyone)
        </label>
    {/if}

    <div class="flex items-center justify-end gap-1.5">
        <button type="button" class="btn-ghost" onclick={onclose}>Cancel</button
        >
        <button type="submit" disabled={form.processing} class="btn-primary"
            >Save</button
        >
    </div>
</form>
