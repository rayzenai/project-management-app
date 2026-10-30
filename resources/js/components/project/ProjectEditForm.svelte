<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import type { ProgramOption, Project } from '../../lib/types';

    let {
        project,
        isSuperAdmin = false,
        programs = [],
        onclose,
    }: {
        project: Project;
        isSuperAdmin?: boolean;
        /** Its organization's programs; given only to whoever may move it. */
        programs?: ProgramOption[];
        onclose: () => void;
    } = $props();

    const uid = $props.id();

    // Seeded once on mount: the parent renders this behind an {#if}, so re-opening
    // the form remounts it with fresh values. Read inside a function so the one-time
    // capture is deliberate rather than a `state_referenced_locally` accident.
    function seed() {
        return {
            title: project.title ?? '',
            key: project.key ?? '',
            title_np: project.title_np ?? '',
            description: project.description ?? '',
            description_np: project.description_np ?? '',
            is_public: project.is_public ?? false,
            is_cross_organization: project.team?.is_cross_organization ?? false,
            program_id:
                programs.find((p) => p.slug === project.program?.slug)?.id ??
                null,
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

    {#if project.can_change_key}
        <div class="flex flex-col gap-1">
            <label for={`${uid}-key`} class="label">Key</label>
            <input
                id={`${uid}-key`}
                type="text"
                bind:value={form.key}
                maxlength="10"
                required
                class="input w-40 font-mono uppercase"
            />
            <p class="text-xs text-fg-muted">
                Tasks are written KEY-12 in commits and branches. Old keys keep
                working after a change.
            </p>
            {#if form.errors.key}
                <p class="text-xs text-danger">{form.errors.key}</p>
            {/if}
        </div>
    {/if}

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

    {#if programs.length > 0}
        <div class="flex flex-col gap-1">
            <label for={`${uid}-program`} class="label"
                >Program (optional)</label
            >
            <select
                id={`${uid}-program`}
                class="input"
                value={form.program_id ?? ''}
                onchange={(e) =>
                    (form.program_id = e.currentTarget.value
                        ? Number(e.currentTarget.value)
                        : null)}
            >
                <option value="">No program</option>
                {#each programs as program (program.id)}
                    <option value={program.id}>{program.name}</option>
                {/each}
            </select>
            {#if form.errors.program_id}
                <p class="text-xs text-danger">{form.errors.program_id}</p>
            {/if}
        </div>
    {/if}

    {#if project.can_publish}
        <label class="flex items-center gap-2 text-fg-muted">
            <input type="checkbox" bind:checked={form.is_public} />
            Public (everyone in its organization can see it)
        </label>
    {/if}
    {#if isSuperAdmin}
        <label class="flex items-center gap-2 text-fg-muted">
            <input type="checkbox" bind:checked={form.is_cross_organization} />
            Its team can take people from other organizations
        </label>
        {#if form.errors.is_cross_organization}
            <p class="text-xs text-danger">
                {form.errors.is_cross_organization}
            </p>
        {/if}
    {/if}

    <div class="flex items-center justify-end gap-1.5">
        <button type="button" class="btn-ghost" onclick={onclose}>Cancel</button
        >
        <button type="submit" disabled={form.processing} class="btn-primary"
            >Save</button
        >
    </div>
</form>
