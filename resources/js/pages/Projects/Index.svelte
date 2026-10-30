<script lang="ts">
    import { inertia, router, useForm } from '@inertiajs/svelte';
    import { FolderTree, Plus } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AssigneePicker from '../../components/AssigneePicker.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import { formatDate } from '../../lib/format';
    import type {
        OrganizationOption,
        Program,
        ProgramRef,
        Project,
    } from '../../lib/types';

    interface ManagerCandidate {
        id: number;
        name: string;
        email: string | null;
        title: string | null;
        organization_id: number;
    }

    let {
        projects,
        archivedView = false,
        archivedCount = 0,
        managerCandidates = [],
        canCreate = false,
        isSuperAdmin = false,
        canPublish = false,
        organizations = [],
        programs = [],
        canManagePrograms = false,
    }: {
        projects: Project[];
        archivedView?: boolean;
        archivedCount?: number;
        /** Who may manage a new project: active people of its organization. */
        managerCandidates?: ManagerCandidate[];
        canCreate?: boolean;
        isSuperAdmin?: boolean;
        canPublish?: boolean;
        organizations?: OrganizationOption[];
        /** The programs the viewer may open; the page groups by them. */
        programs?: Program[];
        canManagePrograms?: boolean;
    } = $props();

    // ?new=1 opens the composer straight away, so the sidebar's + can mean
    // "new project" rather than just "take me to the list".
    let creating = $state(
        typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('new'),
    );
    const uid = $props.id();

    // Seeded once; read inside a function so the one-time capture is deliberate.
    function seed() {
        return {
            title: '',
            title_np: '',
            description: '',
            is_public: false,
            manager_id: null as number | null,
            program_id: null as number | null,
            // With a single organization there is nothing to choose.
            organization_id:
                organizations.length === 1
                    ? organizations[0].id
                    : (null as number | null),
        };
    }

    const form = useForm(seed());
    /** The picked manager: the searchable picker works on a list of ids. */
    let managerIds = $state<number[]>([]);

    // A super-admin picks the organization first; the manager comes from it.
    const formManagers = $derived(
        isSuperAdmin
            ? managerCandidates.filter(
                  (m) => m.organization_id === form.organization_id,
              )
            : managerCandidates,
    );

    // A new project may join a program of the organization it lands in.
    const formPrograms = $derived(
        isSuperAdmin
            ? programs.filter((p) => p.organization.id === form.organization_id)
            : programs,
    );

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            manager_id: managerIds[0] ?? null,
        })).post('/workspace/projects', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                managerIds = [];
                creating = false;
            },
        });
    }

    let creatingProgram = $state(false);

    function seedProgram() {
        return {
            name: '',
            description: '',
            manager_id: null as number | null,
            organization_id:
                organizations.length === 1
                    ? organizations[0].id
                    : (null as number | null),
        };
    }

    const programForm = useForm(seedProgram());
    let programManagerIds = $state<number[]>([]);

    // A program's manager is an active person of its organization.
    const programManagers = $derived(
        isSuperAdmin
            ? managerCandidates.filter(
                  (m) => m.organization_id === programForm.organization_id,
              )
            : managerCandidates,
    );

    function submitProgram(e: SubmitEvent) {
        e.preventDefault();
        programForm
            .transform((data) => ({
                ...data,
                manager_id: programManagerIds[0] ?? null,
            }))
            .post('/workspace/programs', {
                preserveScroll: true,
                onSuccess: () => {
                    programForm.reset();
                    programManagerIds = [];
                    creatingProgram = false;
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

    type Group =
        | {
              key: string;
              kind: 'manager';
              manager: Project['manager'] | null;
              projects: Project[];
          }
        | {
              key: string;
              kind: 'program';
              program: ProgramRef;
              /** Null when the viewer may not open the program's page. */
              details: Program | null;
              projects: Project[];
          }
        | { key: string; kind: 'none'; projects: Project[] };

    // With programs to show, projects sit under their program, programs by
    // name, then the ones in none. An empty program still shows on the
    // active tab, so a new one has its place.
    const byProgram = $derived(programs.length > 0);

    const programGroups = $derived.by((): Group[] => {
        const list = programs.map((program) => ({
            key: `p-${program.slug}`,
            kind: 'program' as const,
            program: { slug: program.slug, name: program.name },
            details: program as Program | null,
            projects: [] as Project[],
        }));
        const none: Project[] = [];

        for (const project of projects) {
            const program = project.program;

            if (!program) {
                none.push(project);
                continue;
            }

            let group = list.find((g) => g.program.slug === program.slug);

            // A program the viewer works in but may not open.
            if (!group) {
                group = {
                    key: `p-${program.slug}`,
                    kind: 'program',
                    program,
                    details: null,
                    projects: [],
                };
                list.push(group);
            }

            group.projects.push(project);
        }

        return [
            ...list
                .filter((g) => !archivedView || g.projects.length > 0)
                .sort((a, b) => a.program.name.localeCompare(b.program.name)),
            ...(none.length > 0
                ? [{ key: 'none', kind: 'none' as const, projects: none }]
                : []),
        ];
    });

    // The portfolio: projects grouped under their manager, managers by name,
    // projects still waiting for one last.
    const managerGroups = $derived.by((): Group[] => {
        const byManager: {
            key: string;
            kind: 'manager';
            manager: Project['manager'];
            projects: Project[];
        }[] = [];

        for (const project of projects) {
            const key = project.manager ? `m${project.manager.id}` : 'none';
            let group = byManager.find((g) => g.key === key);

            if (!group) {
                group = {
                    key,
                    kind: 'manager',
                    manager: project.manager ?? null,
                    projects: [],
                };
                byManager.push(group);
            }

            group.projects.push(project);
        }

        return byManager.sort((a, b) =>
            a.manager && b.manager
                ? a.manager.name.localeCompare(b.manager.name)
                : a.manager
                  ? -1
                  : 1,
        );
    });

    const groups = $derived(byProgram ? programGroups : managerGroups);

    function summary(list: Project[]): string {
        const open = list.reduce((n, p) => n + (p.open_tasks_count ?? 0), 0);
        const overdue = list.reduce(
            (n, p) => n + (p.overdue_tasks_count ?? 0),
            0,
        );
        const parts = [
            `${list.length} ${list.length === 1 ? 'project' : 'projects'}`,
            `${open} open`,
        ];

        if (overdue > 0) {
            parts.push(`${overdue} overdue`);
        }

        return parts.join(' · ');
    }

    const showArchiveColumn = $derived(projects.some((p) => p.can_archive));

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
        {#if canCreate || canManagePrograms}
            <div class="flex items-center gap-1.5">
                {#if creating || creatingProgram}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => {
                            creating = false;
                            creatingProgram = false;
                        }}>Cancel</button
                    >
                {:else}
                    {#if canManagePrograms}
                        <button
                            type="button"
                            class="btn"
                            onclick={() => (creatingProgram = true)}
                        >
                            <Plus class="h-3.5 w-3.5" />
                            New program
                        </button>
                    {/if}
                    {#if canCreate}
                        <button
                            type="button"
                            class="btn-primary"
                            onclick={() => (creating = true)}
                        >
                            <Plus class="h-3.5 w-3.5" />
                            New project
                        </button>
                    {/if}
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
        {#if creatingProgram}
            <div class="mb-6 max-w-2xl border-b border-line pb-6">
                <form onsubmit={submitProgram}>
                    <h2 class="mb-4 text-[15px] font-semibold text-fg">
                        New program
                    </h2>
                    <div>
                        <label
                            for={`${uid}-program-name`}
                            class="label mb-1 block">Name</label
                        >
                        <input
                            id={`${uid}-program-name`}
                            type="text"
                            bind:value={programForm.name}
                            required
                            class="input"
                        />
                        {#if programForm.errors.name}<p
                                class="mt-1 text-xs text-danger"
                            >
                                {programForm.errors.name}
                            </p>{/if}
                    </div>
                    <div class="mt-3">
                        <label
                            for={`${uid}-program-description`}
                            class="label mb-1 block">Description</label
                        >
                        <textarea
                            id={`${uid}-program-description`}
                            bind:value={programForm.description}
                            rows="2"
                            class="input"
                        ></textarea>
                        {#if programForm.errors.description}<p
                                class="mt-1 text-xs text-danger"
                            >
                                {programForm.errors.description}
                            </p>{/if}
                    </div>
                    {#if isSuperAdmin}
                        <div class="mt-3">
                            <label
                                for={`${uid}-program-organization`}
                                class="label mb-1 block">Organization</label
                            >
                            <select
                                id={`${uid}-program-organization`}
                                class="input"
                                required
                                value={programForm.organization_id ?? ''}
                                onchange={(e) => {
                                    programForm.organization_id = e
                                        .currentTarget.value
                                        ? Number(e.currentTarget.value)
                                        : null;
                                    programManagerIds = [];
                                }}
                            >
                                <option value="" disabled
                                    >Choose an organization</option
                                >
                                {#each organizations as organization (organization.id)}
                                    <option value={organization.id}
                                        >{organization.name}</option
                                    >
                                {/each}
                            </select>
                            {#if programForm.errors.organization_id}<p
                                    class="mt-1 text-xs text-danger"
                                >
                                    {programForm.errors.organization_id}
                                </p>{/if}
                        </div>
                    {/if}
                    <div class="mt-3">
                        <span class="label mb-1 block">Program manager</span>
                        {#if isSuperAdmin && programForm.organization_id === null}
                            <p class="text-xs text-fg-muted">
                                Choose an organization first.
                            </p>
                        {:else}
                            <AssigneePicker
                                team={programManagers}
                                bind:selectedIds={programManagerIds}
                                placeholder="Search or choose who manages it"
                            />
                        {/if}
                        <p class="mt-1 text-xs text-fg-muted">
                            In a ministry, usually a Joint Secretary.
                        </p>
                        <p class="mt-1 text-xs text-fg-muted">
                            They run every project in the program as its manager
                            would.
                        </p>
                        {#if programForm.errors.manager_id}<p
                                class="mt-1 text-xs text-danger"
                            >
                                {programForm.errors.manager_id}
                            </p>{/if}
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <div class="flex-1"></div>
                        <button
                            type="submit"
                            disabled={programForm.processing}
                            class="btn-primary">Create</button
                        >
                    </div>
                </form>
            </div>
        {/if}

        {#if creating}
            <div class="mb-6 max-w-2xl border-b border-line pb-6">
                <form onsubmit={submit}>
                    <h2 class="mb-4 text-[15px] font-semibold text-fg">
                        New project
                    </h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for={`${uid}-title`} class="label mb-1 block"
                                >Title</label
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
                                class="label mb-1 block">Title (Nepali)</label
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
                    {#if isSuperAdmin}
                        <div class="mt-3">
                            <label
                                for={`${uid}-organization`}
                                class="label mb-1 block">Organization</label
                            >
                            <select
                                id={`${uid}-organization`}
                                class="input"
                                required
                                value={form.organization_id ?? ''}
                                onchange={(e) => {
                                    form.organization_id = e.currentTarget.value
                                        ? Number(e.currentTarget.value)
                                        : null;
                                    managerIds = [];
                                    form.program_id = null;
                                }}
                            >
                                <option value="" disabled
                                    >Choose an organization</option
                                >
                                {#each organizations as organization (organization.id)}
                                    <option value={organization.id}
                                        >{organization.name}</option
                                    >
                                {/each}
                            </select>
                            {#if form.errors.organization_id}<p
                                    class="mt-1 text-xs text-danger"
                                >
                                    {form.errors.organization_id}
                                </p>{/if}
                        </div>
                    {/if}
                    <div class="mt-3">
                        <span class="label mb-1 block">Project manager</span>
                        {#if isSuperAdmin && form.organization_id === null}
                            <p class="text-xs text-fg-muted">
                                Choose an organization first.
                            </p>
                        {:else}
                            <AssigneePicker
                                team={formManagers}
                                bind:selectedIds={managerIds}
                                placeholder="Search or choose who manages it"
                            />
                        {/if}
                        <p class="mt-1 text-xs text-fg-muted">
                            In a ministry, usually an Under Secretary.
                        </p>
                        <p class="mt-1 text-xs text-fg-muted">
                            The project gets its own team, led by its manager,
                            who then adds the people.
                        </p>
                        {#if form.errors.manager_id}<p
                                class="mt-1 text-xs text-danger"
                            >
                                {form.errors.manager_id}
                            </p>{/if}
                    </div>
                    {#if formPrograms.length > 0}
                        <div class="mt-3">
                            <label
                                for={`${uid}-program`}
                                class="label mb-1 block"
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
                                {#each formPrograms as program (program.id)}
                                    <option value={program.id}
                                        >{program.name}</option
                                    >
                                {/each}
                            </select>
                            {#if form.errors.program_id}<p
                                    class="mt-1 text-xs text-danger"
                                >
                                    {form.errors.program_id}
                                </p>{/if}
                        </div>
                    {/if}

                    {#if canPublish}
                        <label
                            class="mt-4 flex items-center gap-2 text-fg-muted"
                        >
                            <input
                                type="checkbox"
                                bind:checked={form.is_public}
                            /> Public (everyone in its organization can see it)
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
                    {#if form.errors.title}<p class="mt-2 text-xs text-danger">
                            {form.errors.title}
                        </p>{/if}
                </form>
            </div>
        {/if}

        {#if groups.length === 0}
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
                    class="col-head hidden items-center gap-3 border-b border-line px-4 pb-1.5 sm:flex lg:px-8"
                >
                    <span class="w-4"></span>
                    <span class="flex-1">Name</span>
                    <span class="w-14 text-right">Team</span>
                    <span class="w-14 text-right">Open</span>
                    <span class="w-16 text-right">Overdue</span>
                    <span class="w-28 text-right">Next deadline</span>
                    {#if showArchiveColumn}
                        <span class="w-[72px]"></span>
                    {/if}
                </div>
                {#each groups as group (group.key)}
                    <div
                        class="group-head flex items-center gap-2.5 border-b border-line bg-surface-alt px-4 py-2 lg:px-8"
                    >
                        {#if group.kind === 'program'}
                            <FolderTree
                                class="h-4 w-4 shrink-0 text-fg-faint"
                            />
                            {#if group.details}
                                <a
                                    href={`/workspace/programs/${group.program.slug}`}
                                    use:inertia
                                    class="truncate font-medium text-fg hover:text-accent"
                                    >{group.program.name}</a
                                >
                                {#if group.details.manager}
                                    <span
                                        class="hidden min-w-0 items-center gap-1.5 text-xs text-fg-muted sm:inline-flex"
                                    >
                                        <Avatar
                                            name={group.details.manager.name}
                                        />
                                        <span class="truncate"
                                            >{group.details.manager.name}</span
                                        >
                                    </span>
                                {/if}
                            {:else}
                                <span class="truncate font-medium text-fg"
                                    >{group.program.name}</span
                                >
                            {/if}
                        {:else if group.kind === 'none'}
                            <span class="font-medium text-fg-muted"
                                >No program</span
                            >
                        {:else if group.manager}
                            <Avatar name={group.manager.name} size="md" />
                            <span class="truncate font-medium text-fg"
                                >{group.manager.name}</span
                            >
                            {#if group.manager.title}
                                <span
                                    class="hidden truncate text-xs text-fg-muted sm:inline"
                                    >{group.manager.title}</span
                                >
                            {/if}
                        {:else}
                            <span class="font-medium text-warn"
                                >No manager yet</span
                            >
                        {/if}
                        {#if group.projects.length > 0}
                            <span class="ml-auto shrink-0 text-xs text-fg-muted"
                                >{summary(group.projects)}</span
                            >
                        {/if}
                    </div>
                    {#if group.projects.length === 0}
                        <p
                            class="border-b border-line-soft px-4 py-2.5 text-xs text-fg-muted lg:px-8"
                        >
                            No projects in this program yet.{canCreate
                                ? ' Choose it when you create or edit a project.'
                                : ''}
                        </p>
                    {/if}
                    {#each group.projects as project (project.id)}
                        <a
                            href={`/workspace/projects/${project.slug}`}
                            class={`row group min-h-10 py-2 lg:px-8 ${archivedView ? 'text-fg-muted' : ''}`}
                        >
                            <ProgressRing
                                percent={project.percent_complete ?? 0}
                                class={archivedView ? 'opacity-50' : ''}
                            />
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex min-w-0 items-baseline gap-2.5"
                                >
                                    <span
                                        class={`truncate font-medium group-hover:text-accent ${archivedView ? 'text-fg-muted' : 'text-fg'}`}
                                        >{project.title}</span
                                    >
                                    <span
                                        class="shrink-0 font-mono text-xs text-fg-muted tabular-nums"
                                        >{project.percent_complete ?? 0}%</span
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
                                    {#if byProgram}
                                        {#if project.manager}
                                            <span
                                                class="hidden truncate text-xs text-fg-muted sm:inline"
                                                >{project.manager.name}</span
                                            >
                                        {:else}
                                            <span
                                                class="hidden shrink-0 text-xs text-warn sm:inline"
                                                >No manager yet</span
                                            >
                                        {/if}
                                    {/if}
                                    {#if isSuperAdmin && project.organization}
                                        <span
                                            class="truncate text-xs text-fg-faint"
                                            >{project.organization}</span
                                        >
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
                                class="hidden w-14 shrink-0 text-right font-mono text-xs text-fg-muted tabular-nums sm:inline"
                                title="People on its team"
                                >{project.team?.members_count ?? 0}</span
                            >
                            <span
                                class="hidden w-14 shrink-0 text-right font-mono text-xs text-fg-muted tabular-nums sm:inline"
                                >{project.open_tasks_count ?? 0}</span
                            >
                            <span
                                class={`hidden w-16 shrink-0 text-right font-mono text-xs tabular-nums sm:inline ${(project.overdue_tasks_count ?? 0) > 0 ? 'text-danger' : 'text-fg-faint'}`}
                                >{project.overdue_tasks_count ?? 0}</span
                            >
                            <span
                                class="hidden w-28 shrink-0 text-right font-mono text-xs text-fg-muted tabular-nums sm:inline"
                                >{project.next_deadline_at
                                    ? formatDate(project.next_deadline_at)
                                    : ''}</span
                            >
                            {#if project.can_archive}
                                <span
                                    class="flex w-[72px] shrink-0 justify-end"
                                >
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
                            {:else if showArchiveColumn}
                                <span class="w-[72px] shrink-0"></span>
                            {/if}
                        </a>
                    {/each}
                {/each}
            </div>
        {/if}
    </div>
</AppShell>
