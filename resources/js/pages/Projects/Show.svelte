<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
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
            return 'board';
        }

        // An explicit ?view= wins over the remembered one — that is how the
        // new-task dialog drops you on the board next to what you just made.
        const requested = new URLSearchParams(window.location.search).get(
            'view',
        );

        if (
            requested === 'board' ||
            requested === 'list' ||
            requested === 'people'
        ) {
            return requested;
        }

        const raw = window.localStorage.getItem(
            `workspace.view.${project.slug}`,
        );

        if (raw === 'kanban') {
            return 'board';
        } // legacy value migration

        return raw === 'list' || raw === 'people' ? raw : 'board'; // default + garbage guard
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

    function tabCount(tab: Tab): number {
        return tab === 'people' ? teammates.length : filteredTasks.length;
    }

    onMount(() => {
        peek.openFromUrl(tasks.map((t) => ({ id: t.id, slug: t.slug })));
    });
</script>

<svelte:head><title>{project.title} · Workspace</title></svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/projects"
                use:inertia
                class="shrink-0 text-fg-muted hover:text-fg">Projects</a
            >
            <span class="text-fg-faint">/</span>
            <span class="truncate font-medium">{project.title}</span>
            {#if project.title_np}
                <span class="font-np hidden truncate text-fg-faint sm:inline"
                    >{project.title_np}</span
                >
            {/if}
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            {#if project.can_manage_access && !project.is_archived}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (editing = true)}
                >
                    Edit project
                </button>
            {/if}
            {#if project.can_archive && !project.is_archived}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={archiveProject}
                >
                    Archive
                </button>
            {/if}
            <button
                type="button"
                class="btn-primary"
                onclick={() =>
                    quickAdd.open({ projectId: project.id, lockProject: true })}
            >
                Add task
            </button>
        </div>
    {/snippet}

    {#if project.is_archived}
        <div
            class="flex items-center justify-between gap-3 border-b border-line bg-warn-soft px-4 py-2 text-warn"
        >
            <span
                >This project is archived. It is hidden from My Workspace, the
                dashboard and quick-add.</span
            >
            {#if project.can_archive}
                <button type="button" class="btn" onclick={restoreProject}
                    >Restore</button
                >
            {/if}
        </div>
    {/if}

    {#if editing}
        <div class="border-b border-line px-4 py-4">
            <ProjectEditForm
                {project}
                isSuperAdmin={shared.isSuperAdmin ?? false}
                onclose={() => (editing = false)}
            />
        </div>
    {/if}

    {#if tasks.length === 0}
        <div class="flex flex-col items-start gap-2 px-4 py-8">
            <p class="font-medium">No tasks yet</p>
            <p class="text-fg-muted">
                Press <kbd class="kbd">Q</kbd> anywhere to add one, or:
            </p>
            <button
                type="button"
                class="btn-primary mt-1"
                onclick={() =>
                    quickAdd.open({ projectId: project.id, lockProject: true })}
            >
                Add task
            </button>
        </div>
    {:else}
        <div
            class="flex h-10 items-center gap-0.5 overflow-x-auto border-b border-line px-3"
        >
            {#each TABS as tab (tab.value)}
                {@const active = activeTab === tab.value}
                <button
                    type="button"
                    class={`inline-flex h-10 shrink-0 items-center gap-2 border-b-2 px-2.5 font-medium transition ${
                        active
                            ? 'border-accent text-fg'
                            : 'border-transparent text-fg-muted hover:text-fg'
                    }`}
                    onclick={() => (activeTab = tab.value)}
                    aria-pressed={active}
                >
                    {tab.label}
                    <span class="section-count">{tabCount(tab.value)}</span>
                </button>
            {/each}

            <ProjectFilters
                bind:filters
                {teammates}
                {categories}
                shownCount={filteredTasks.length}
                totalCount={tasks.length}
            />
        </div>

        <div class="flex flex-col gap-4 px-4 py-4">
            {#if project.description || teams.length > 0}
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    {#if project.description}
                        <p class="max-w-2xl text-fg-muted">
                            {project.description}
                        </p>
                    {/if}
                    {#if teams.length > 0}
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="label">Teams</span>
                            {#each teams as team (team.id)}
                                {@const attached = (
                                    project.team_ids ?? []
                                ).includes(team.id)}
                                <button
                                    type="button"
                                    aria-pressed={attached}
                                    title={attached
                                        ? `Detach ${team.name}`
                                        : `Attach ${team.name}: scopes the assignee picker to its members`}
                                    class={`btn ${attached ? 'border-accent/40 bg-accent-soft text-accent hover:bg-accent-soft' : ''}`}
                                    onclick={() => toggleProjectTeam(team)}
                                >
                                    {team.name}
                                </button>
                            {/each}
                            {#if (project.team_ids ?? []).length === 0}
                                <span class="text-xs text-fg-faint"
                                    >None. Everyone is assignable.</span
                                >
                            {/if}
                        </div>
                    {/if}
                </div>
            {/if}

            <ProjectSummaryStrip {tasks} {statuses} />
        </div>

        {#if filteredTasks.length === 0}
            <div class="flex items-center gap-3 border-t border-line px-4 py-6">
                <p class="text-fg-muted">No tasks match the current filters.</p>
                <button type="button" class="btn" onclick={clearFilters}>
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
