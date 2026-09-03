<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import BoardView from '../../components/project/BoardView.svelte';
    import ListView from '../../components/project/ListView.svelte';
    import PeopleView from '../../components/project/PeopleView.svelte';
    import ProjectEditForm from '../../components/project/ProjectEditForm.svelte';
    import ProjectFilters from '../../components/project/ProjectFilters.svelte';
    import type {
        CategoryOption,
        ProjectFiltersState,
    } from '../../components/project/ProjectFilters.svelte';
    import ProjectSummaryStrip from '../../components/project/ProjectSummaryStrip.svelte';
    import { peek } from '../../lib/peek.svelte';
    import { quickAdd } from '../../lib/quickAdd.svelte';
    import type {
        Member,
        Project,
        SharedProps,
        Task,
        Team,
    } from '../../lib/types';

    let {
        project,
        tasks,
        teams = [],
    }: { project: Project; tasks: Task[]; teams?: Team[] } = $props();

    let editing = $state(false);

    function toggleProjectTeam(team: Team) {
        const current = project.team_ids ?? [];
        const next = current.includes(team.id)
            ? current.filter((id) => id !== team.id)
            : [...current, team.id];
        router.patch(
            `/workspace/projects/${project.slug}`,
            { team_ids: next },
            { preserveState: true, preserveScroll: true },
        );
    }

    type Tab = 'board' | 'list' | 'people';

    const TABS: { value: Tab; label: string }[] = [
        { value: 'board', label: 'Board' },
        { value: 'list', label: 'List' },
        { value: 'people', label: 'People' },
    ];

    function initialTab(): Tab {
        if (typeof window === 'undefined') {
            return 'list';
        }

        const raw = window.localStorage.getItem(
            `workspace.view.${project.slug}`,
        );

        if (raw === 'kanban') {
            return 'board';
        } // legacy value migration

        return raw === 'board' || raw === 'people' ? raw : 'list'; // default + garbage guard
    }

    let activeTab = $state<Tab>(initialTab());

    $effect(() => {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(
                `workspace.view.${project.slug}`,
                activeTab,
            );
        }
    });

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const statuses = $derived(shared.statuses ?? []);

    let filters = $state<ProjectFiltersState>({
        assigneeIds: [],
        overdueOnly: false,
        category: null,
    });

    const completeSet = $derived(
        new SvelteSet(
            statuses.filter((s) => s.is_complete).map((s) => s.value),
        ),
    );
    const isComplete = (t: Task) => completeSet.has(t.status);
    const isOverdue = (t: Task) =>
        !!t.deadline_at &&
        !isComplete(t) &&
        new Date(t.deadline_at) < new Date(new Date().toDateString()); // date-only compare

    const teammates = $derived.by(() => {
        // Unique, name-sorted, from assignments.
        const m = new SvelteMap<number, Member>();

        for (const t of tasks) {
            for (const a of t.assignments ?? []) {
                if (a.member) {
                    m.set(a.member.id, a.member);
                }
            }
        }

        return [...m.values()].sort((a, b) => a.name.localeCompare(b.name));
    });

    const categories = $derived.by(() => {
        // Unique {value,label,color}; [] hides the select.
        const m = new SvelteMap<string, CategoryOption>();

        for (const t of tasks) {
            if (t.category) {
                m.set(t.category, {
                    value: t.category,
                    label: t.category_label ?? t.category,
                    color: t.category_color ?? null,
                });
            }
        }

        return [...m.values()].sort((a, b) => a.label.localeCompare(b.label));
    });

    const filteredTasks = $derived(
        tasks.filter(
            (t) =>
                (filters.assigneeIds.length === 0 ||
                    (t.assignments ?? []).some((a) =>
                        filters.assigneeIds.includes(a.member_id),
                    )) &&
                (!filters.overdueOnly || isOverdue(t)) &&
                (filters.category === null || t.category === filters.category),
        ),
    );

    const filtersActive = $derived(
        filters.assigneeIds.length > 0 ||
            filters.overdueOnly ||
            filters.category !== null,
    );

    function clearFilters() {
        filters = { assigneeIds: [], overdueOnly: false, category: null };
    }

    function archiveProject() {
        if (
            !confirm(
                `Archive "${project.title}"? It will go dormant until restored.`,
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

    function restoreProject() {
        router.patch(
            `/workspace/projects/${project.slug}/restore`,
            {},
            { preserveScroll: true },
        );
    }

    onMount(() => {
        peek.openFromUrl(tasks.map((t) => ({ id: t.id, slug: t.slug })));
    });
</script>

<svelte:head><title>{project.title} · Workspace</title></svelte:head>

<AppShell>
    {#if project.is_archived}
        <div
            class="mb-4 flex items-center justify-between rounded-lg border border-warn/40 bg-warn/10 px-4 py-2 text-sm text-warn"
        >
            <span
                >This project is archived — hidden from My Workspace, the
                dashboard, and quick-add.</span
            >
            {#if project.can_archive}
                <button
                    type="button"
                    class="rounded-md bg-accent px-2.5 py-1 text-xs font-semibold text-bg hover:bg-accent-dim"
                    onclick={restoreProject}>Restore</button
                >
            {/if}
        </div>
    {/if}

    <header class="mb-6">
        <nav class="mb-2 flex items-center gap-2 text-xs text-fg-muted">
            <span>
                <a href="/workspace/projects" class="hover:underline"
                    >Projects</a
                >
                /
                <span>{project.title}</span>
            </span>
        </nav>
        {#if editing}
            <ProjectEditForm
                {project}
                isSuperAdmin={shared.isSuperAdmin ?? false}
                onclose={() => (editing = false)}
            />
        {:else}
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-fg">
                        {project.title}
                    </h1>
                    {#if project.can_manage_access && !project.is_archived}
                        <button
                            type="button"
                            class="rounded-md border border-accent px-3 py-1.5 text-sm font-semibold text-accent transition hover:bg-accent/10"
                            onclick={() => (editing = true)}
                        >
                            Edit project
                        </button>
                    {/if}
                    {#if project.can_archive && !project.is_archived}
                        <button
                            type="button"
                            class="rounded-md border border-line px-3 py-1.5 text-sm font-medium text-fg-muted transition hover:bg-surface-alt hover:text-fg"
                            onclick={archiveProject}
                        >
                            Archive
                        </button>
                    {/if}
                </div>
                {#if project.title_np}
                    <div class="mt-1 text-base text-fg-muted">
                        {project.title_np}
                    </div>
                {/if}
                {#if project.description}
                    <p class="mt-2 max-w-2xl text-sm text-fg-muted">
                        {project.description}
                    </p>
                {/if}
                {#if teams.length > 0}
                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="ws-eyebrow text-fg-muted">Teams</span>
                        {#each teams as team (team.id)}
                            {@const attached = (
                                project.team_ids ?? []
                            ).includes(team.id)}
                            <button
                                type="button"
                                aria-pressed={attached}
                                title={attached
                                    ? `Detach ${team.name}`
                                    : `Attach ${team.name} — scopes the assignee picker to its members`}
                                class={`rounded-full px-2.5 py-1 text-xs font-medium transition ${
                                    attached
                                        ? 'bg-accent/20 text-accent ring-1 ring-accent/60'
                                        : 'bg-surface-alt text-fg-muted hover:bg-surface-alt'
                                }`}
                                onclick={() => toggleProjectTeam(team)}
                            >
                                {team.name}
                            </button>
                        {/each}
                        {#if (project.team_ids ?? []).length === 0}
                            <span class="text-xs text-fg-faint"
                                >none — everyone is assignable</span
                            >
                        {/if}
                    </div>
                {/if}
            </div>
        {/if}
    </header>

    {#if tasks.length === 0}
        <div
            class="rounded-xl border border-dashed border-line bg-surface p-10 text-center"
        >
            <p class="text-base font-medium">No tasks yet.</p>
            <p class="mt-1 text-sm text-fg-muted">
                Press <strong>q</strong> anywhere, or:
            </p>
            <button
                type="button"
                class="mt-3 rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim"
                onclick={() =>
                    quickAdd.open({ projectId: project.id, lockProject: true })}
            >
                + Add task
            </button>
        </div>
    {:else}
        <div class="mb-4 flex items-center justify-between gap-2">
            <div
                class="inline-flex overflow-hidden rounded-md border border-line text-sm"
            >
                {#each TABS as tab (tab.value)}
                    <button
                        type="button"
                        class={`px-3 py-1.5 transition ${
                            activeTab === tab.value
                                ? 'bg-accent text-bg'
                                : 'bg-surface text-fg-muted hover:bg-surface-alt'
                        }`}
                        onclick={() => (activeTab = tab.value)}
                        aria-pressed={activeTab === tab.value}
                    >
                        {tab.label}
                    </button>
                {/each}
            </div>
            <button
                type="button"
                class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim"
                onclick={() =>
                    quickAdd.open({ projectId: project.id, lockProject: true })}
            >
                + Add task
            </button>
        </div>

        <ProjectSummaryStrip {tasks} {statuses} />
        <ProjectFilters
            bind:filters
            {teammates}
            {categories}
            shownCount={filteredTasks.length}
            totalCount={tasks.length}
        />

        {#if filteredTasks.length === 0}
            <div
                class="mb-4 rounded-xl border border-dashed border-line bg-surface p-8 text-center"
            >
                <p class="text-sm text-fg-muted">
                    No tasks match the current filters.
                </p>
                <button
                    type="button"
                    class="mt-3 rounded-md border border-accent px-3 py-1.5 text-sm font-medium text-accent transition hover:bg-accent/10"
                    onclick={clearFilters}
                >
                    Clear filters
                </button>
            </div>
        {/if}

        {#if activeTab === 'board'}
            <BoardView
                {project}
                tasks={filteredTasks}
                {statuses}
                {filtersActive}
            />
        {:else if activeTab === 'list'}
            {#if filteredTasks.length > 0}
                <ListView {project} tasks={filteredTasks} {statuses} />
            {/if}
        {:else if filteredTasks.length > 0}
            <PeopleView {project} tasks={filteredTasks} {statuses} />
        {/if}
    {/if}
</AppShell>
