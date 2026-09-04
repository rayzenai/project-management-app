<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { Check, Plus } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import type { Project } from '../../lib/types';

    let {
        projects,
        archivedView = false,
        archivedCount = 0,
        assignableTeams = [],
        canCreate = false,
        isSuperAdmin = false,
    }: {
        projects: Project[];
        archivedView?: boolean;
        archivedCount?: number;
        assignableTeams?: { id: number; name: string; slug: string }[];
        canCreate?: boolean;
        isSuperAdmin?: boolean;
    } = $props();

    // ?new=1 opens the composer straight away, so the sidebar's + can mean
    // "new project" rather than just "take me to the list".
    let creating = $state(
        typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );
    const uid = $props.id();
    const form = useForm({
        title: '',
        title_np: '',
        description: '',
        is_public: false,
        team_ids: [] as number[],
    });

    const needsTeam = $derived(canCreate && assignableTeams.length === 0);
    const teamForm = useForm({ name: '', description: '', color: '' });

    /** True once the user creates a team inside the wizard, keeps the stepper visible on step 2. */
    let teamJustCreated = $state(false);
    /** Show the two-step stepper only inside the no-team wizard flow, not for the normal project form. */
    const showStepper = $derived(needsTeam || teamJustCreated);

    function toggleCreating() {
        creating = !creating;

        if (!creating) {
            teamJustCreated = false;
        }
    }

    function createTeam(e: SubmitEvent) {
        e.preventDefault();
        teamForm.post('/workspace/teams', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                teamForm.reset();
                teamJustCreated = true;
                router.reload({ only: ['assignableTeams'] });
            },
        });
    }

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.post('/workspace/projects', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                creating = false;
                teamJustCreated = false;
            },
        });
    }

    function archive(project: Project) {
        if (
            !confirm(
                `Archive "${project.title}"? It will disappear from My Workspace, the dashboard, and quick-add until restored.`,
            )
        ) {
            return;
        }

        router.patch(
            `/workspace/projects/${project.slug}/archive`,
            {},
            { preserveScroll: true },
        );
    }

    function restore(project: Project) {
        router.patch(
            `/workspace/projects/${project.slug}/restore`,
            {},
            { preserveScroll: true },
        );
    }

    const tabBase =
        'inline-flex h-10 items-center gap-2 border-b-2 px-2.5 font-medium';
    const tabOn = 'border-accent text-fg';
    const tabOff = 'border-transparent text-fg-muted hover:text-fg';
</script>

<svelte:head><title>Projects · Workspace</title></svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Projects</span>
        </div>
        {#if canCreate}
            <div class="flex items-center gap-1.5">
                {#if creating}
                    <button type="button" class="btn" onclick={toggleCreating}
                        >Cancel</button
                    >
                {:else}
                    <button
                        type="button"
                        class="btn-primary"
                        onclick={toggleCreating}
                    >
                        <Plus class="h-3.5 w-3.5" />
                        New project
                    </button>
                {/if}
            </div>
        {/if}
    {/snippet}

    <div class="flex h-10 items-center gap-0.5 border-b border-line px-3">
        <a
            href="/workspace/projects"
            class={`${tabBase} ${archivedView ? tabOff : tabOn}`}
            >Active
            {#if !archivedView}
                <span class="section-count">{projects.length}</span>
            {/if}
        </a>
        <a
            href="/workspace/projects?archived=1"
            class={`${tabBase} ${archivedView ? tabOn : tabOff}`}
            >Archived
            {#if archivedCount}
                <span class="section-count">{archivedCount}</span>
            {/if}
        </a>
    </div>

    <div class="px-4 py-5 lg:px-8 lg:py-6">
        {#if creating}
            <div class="mb-6 max-w-2xl border-b border-line pb-6">
                {#if showStepper}
                    <ol
                        class="mb-5 flex items-center gap-3 text-xs font-medium"
                    >
                        <li
                            class="flex items-center gap-2 {needsTeam
                                ? 'text-fg'
                                : 'text-fg-muted'}"
                        >
                            <span
                                class="inline-grid h-5 w-5 place-items-center rounded-sm font-mono text-[11px] {needsTeam
                                    ? 'bg-accent text-white'
                                    : 'bg-success-soft text-success'}"
                            >
                                {#if needsTeam}
                                    1
                                {:else}
                                    <Check class="h-3 w-3" />
                                {/if}
                            </span>
                            Create a team
                        </li>
                        <li
                            class="h-px w-8 {needsTeam
                                ? 'bg-line'
                                : 'bg-success'}"
                            aria-hidden="true"
                        ></li>
                        <li
                            class="flex items-center gap-2 {needsTeam
                                ? 'text-fg-faint'
                                : 'text-fg'}"
                        >
                            <span
                                class="inline-grid h-5 w-5 place-items-center rounded-sm font-mono text-[11px] {needsTeam
                                    ? 'bg-surface-alt text-fg-faint'
                                    : 'bg-accent text-white'}"
                            >
                                2
                            </span>
                            Create the project
                        </li>
                    </ol>
                {/if}

                {#if needsTeam}
                    <form onsubmit={createTeam}>
                        <h2 class="text-[15px] font-semibold text-fg">
                            Create a team first
                        </h2>
                        <p class="mt-1 text-fg-muted">
                            Projects belong to teams, and you don't have one
                            yet. Create your first team below, then continue to
                            the project form.
                        </p>
                        <div class="mt-4">
                            <label
                                for={`${uid}-team-name`}
                                class="label mb-1 block">Team name</label
                            >
                            <input
                                id={`${uid}-team-name`}
                                type="text"
                                bind:value={teamForm.name}
                                required
                                placeholder="e.g. Mayor's Office, Engineering, Comms"
                                class="input"
                            />
                            {#if teamForm.errors.name}<p
                                    class="mt-1 text-xs text-danger"
                                >
                                    {teamForm.errors.name}
                                </p>{/if}
                        </div>
                        <div class="mt-3">
                            <label
                                for={`${uid}-team-desc`}
                                class="label mb-1 block"
                                >Description (optional)</label
                            >
                            <textarea
                                id={`${uid}-team-desc`}
                                bind:value={teamForm.description}
                                rows="2"
                                class="input"
                            ></textarea>
                        </div>
                        <div class="mt-4 flex justify-end">
                            <button
                                type="submit"
                                disabled={teamForm.processing}
                                class="btn-primary"
                                >Create team and continue</button
                            >
                        </div>
                    </form>
                {:else}
                    <form onsubmit={submit}>
                        <h2 class="mb-4 text-[15px] font-semibold text-fg">
                            New project
                        </h2>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label
                                    for={`${uid}-title`}
                                    class="label mb-1 block">Title</label
                                >
                                <input
                                    id={`${uid}-title`}
                                    type="text"
                                    bind:value={form.title}
                                    required
                                    class="input"
                                />
                            </div>
                            <div>
                                <label
                                    for={`${uid}-title-np`}
                                    class="label mb-1 block"
                                    >Title (Nepali)</label
                                >
                                <input
                                    id={`${uid}-title-np`}
                                    type="text"
                                    bind:value={form.title_np}
                                    class="input font-np"
                                />
                            </div>
                        </div>
                        <div class="mt-3">
                            <label
                                for={`${uid}-description`}
                                class="label mb-1 block">Description</label
                            >
                            <textarea
                                id={`${uid}-description`}
                                bind:value={form.description}
                                rows="2"
                                class="input"
                            ></textarea>
                        </div>
                        <fieldset class="mt-4">
                            <legend class="label">Teams with access</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                {#each assignableTeams as team (team.id)}
                                    <label
                                        class="flex h-7 items-center gap-2 rounded-md border border-line px-2.5 text-[13px] text-fg"
                                    >
                                        <input
                                            type="checkbox"
                                            value={team.id}
                                            checked={form.team_ids.includes(
                                                team.id,
                                            )}
                                            onchange={(e) => {
                                                const id = team.id;
                                                form.team_ids = e.currentTarget
                                                    .checked
                                                    ? [...form.team_ids, id]
                                                    : form.team_ids.filter(
                                                          (t) => t !== id,
                                                      );
                                            }}
                                        />
                                        {team.name}
                                    </label>
                                {/each}
                            </div>
                            {#if form.errors.team_ids}<p
                                    class="mt-1 text-xs text-danger"
                                >
                                    {form.errors.team_ids}
                                </p>{/if}
                        </fieldset>

                        {#if isSuperAdmin}
                            <label
                                class="mt-4 flex items-center gap-2 text-fg-muted"
                            >
                                <input
                                    type="checkbox"
                                    bind:checked={form.is_public}
                                /> Public (visible to everyone)
                            </label>
                        {/if}

                        <div class="mt-4 flex items-center gap-2">
                            <div class="flex-1"></div>
                            <button
                                type="submit"
                                disabled={form.processing}
                                class="btn-primary">Create</button
                            >
                        </div>
                        {#if form.errors.title}<p
                                class="mt-2 text-xs text-danger"
                            >
                                {form.errors.title}
                            </p>{/if}
                    </form>
                {/if}
            </div>
        {/if}

        {#if projects.length === 0}
            <p class="text-fg-muted">
                {#if archivedView}
                    No archived projects.
                {:else if canCreate}
                    No projects yet. Use "New project" to start one.
                {:else}
                    No projects yet.
                {/if}
            </p>
        {:else}
            <div class="-mx-4 lg:-mx-8">
                <div
                    class="col-head flex items-center gap-3 border-b border-line px-4 pb-1.5 lg:px-8"
                >
                    <span class="w-4"></span>
                    <span class="flex-1">Name</span>
                    <span class="w-14 text-right">Tasks</span>
                    {#if projects.some((p) => p.can_archive)}
                        <span class="w-[72px]"></span>
                    {/if}
                </div>
                {#each projects as project (project.id)}
                    <a
                        href={`/workspace/projects/${project.slug}`}
                        class={`row group min-h-10 py-2 lg:px-8 ${archivedView ? 'text-fg-muted' : ''}`}
                    >
                        <ProgressRing
                            percent={project.percent_complete ?? 0}
                            class={archivedView ? 'opacity-50' : ''}
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 items-baseline gap-2.5">
                                <span
                                    class={`truncate font-medium group-hover:text-accent ${archivedView ? 'text-fg-muted' : 'text-fg'}`}
                                    >{project.title}</span
                                >
                                {#if project.title_np}
                                    <span
                                        class="font-np truncate text-xs text-fg-muted"
                                        >{project.title_np}</span
                                    >
                                {/if}
                                {#if project.is_public}
                                    <span class="chip">public</span>
                                {/if}
                            </div>
                            {#if project.description}
                                <p
                                    class="mt-0.5 line-clamp-1 text-xs text-fg-muted"
                                >
                                    {project.description}
                                </p>
                            {/if}
                        </div>
                        <span
                            class="w-14 shrink-0 text-right font-mono text-xs text-fg-muted tabular-nums"
                            >{project.tasks_count ?? 0}</span
                        >
                        {#if project.can_archive}
                            <span class="flex w-[72px] shrink-0 justify-end">
                                {#if project.is_archived}
                                    <button
                                        type="button"
                                        class="btn"
                                        onclick={(e) => {
                                            e.stopPropagation();
                                            e.preventDefault();
                                            restore(project);
                                        }}>Restore</button
                                    >
                                {:else}
                                    <button
                                        type="button"
                                        class="btn-ghost"
                                        onclick={(e) => {
                                            e.stopPropagation();
                                            e.preventDefault();
                                            archive(project);
                                        }}>Archive</button
                                    >
                                {/if}
                            </span>
                        {:else if projects.some((p) => p.can_archive)}
                            <span class="w-[72px] shrink-0"></span>
                        {/if}
                    </a>
                {/each}
            </div>
        {/if}
    </div>
</AppShell>
