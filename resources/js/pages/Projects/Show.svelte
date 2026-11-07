<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { Archive, ChartGantt, Pencil, Plus } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import BoardView from '../../components/project/BoardView.svelte';
    import CodeView from '../../components/project/CodeView.svelte';
    import DocumentsView from '../../components/project/DocumentsView.svelte';
    import FormsView from '../../components/project/FormsView.svelte';
    import ListView from '../../components/project/ListView.svelte';
    import PeopleView from '../../components/project/PeopleView.svelte';
    import ProjectEditForm from '../../components/project/ProjectEditForm.svelte';
    import ProjectFilters from '../../components/project/ProjectFilters.svelte';
    import type {
        CategoryOption,
        ProjectFiltersState,
    } from '../../components/project/ProjectFilters.svelte';
    import ProjectSummaryStrip from '../../components/project/ProjectSummaryStrip.svelte';
    import ProjectTeamPanel from '../../components/project/ProjectTeamPanel.svelte';
    import type { ProjectForms } from '../../lib/forms/types';
    import { peek } from '../../lib/peek.svelte';
    import { quickAdd } from '../../lib/quickAdd.svelte';
    import type {
        Member,
        ProgramOption,
        Project,
        ProjectDocuments,
        ProjectGit,
        ProjectTeam,
        SharedProps,
        Task,
    } from '../../lib/types';

    let {
        project,
        tasks,
        team = null,
        git = null,
        documents = null,
        forms = null,
        programs = [],
    }: {
        project: Project;
        tasks: Task[];
        team?: ProjectTeam | null;
        /** The Code tab; null when the organization has no git. */
        git?: ProjectGit | null;
        /** The Documents tab; null when not the viewer's to use. */
        documents?: ProjectDocuments | null;
        /** The Forms tab; null when forms are off or not the viewer's to use. */
        forms?: ProjectForms | null;
        /** Edit project's Program choices; empty unless the viewer may move it. */
        programs?: ProgramOption[];
    } = $props();

    let editing = $state(false);

    type Tab = 'board' | 'list' | 'people' | 'code' | 'documents' | 'forms';

    const TABS: { value: Tab; label: string }[] = [
        { value: 'board', label: 'Board' },
        { value: 'list', label: 'List' },
        { value: 'people', label: 'People' },
    ];

    // Code only where the organization has git; Documents only for the
    // people who may use them.
    const tabs = $derived<{ value: Tab; label: string }[]>([
        ...TABS,
        ...(git ? [{ value: 'code' as const, label: 'Code' }] : []),
        ...(documents
            ? [{ value: 'documents' as const, label: 'Documents' }]
            : []),
        ...(forms ? [{ value: 'forms' as const, label: 'Forms' }] : []),
    ]);

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
            requested === 'people' ||
            (requested === 'code' && git) ||
            (requested === 'documents' && documents) ||
            (requested === 'forms' && forms)
        ) {
            return requested;
        }

        const raw = window.localStorage.getItem(
            `workspace.view.${project.slug}`,
        );

        if (raw === 'kanban') {
            return 'board';
        } // legacy value migration

        if (raw === 'code' && git) {
            return 'code';
        }

        if (raw === 'documents' && documents) {
            return 'documents';
        }

        if (raw === 'forms' && forms) {
            return 'forms';
        }

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

    const isOverdue = (t: Task) => t.is_overdue === true;

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
        if (tab === 'code') {
            return git?.repositories.length ?? 0;
        }

        if (tab === 'documents') {
            return documents?.documents.length ?? 0;
        }

        if (tab === 'forms') {
            return forms?.forms.length ?? 0;
        }

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
            <!-- A phone's bar keeps only where you are. -->
            <a
                href="/workspace/projects"
                use:inertia
                class="hidden shrink-0 text-fg-muted hover:text-fg sm:inline"
                >Projects</a
            >
            <span class="hidden text-fg-faint sm:inline">/</span>
            <span class="truncate font-medium">{project.title}</span>
            {#if project.title_np}
                <span class="font-np hidden truncate text-fg-faint sm:inline"
                    >{project.title_np}</span
                >
            {/if}
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            {#if project.can_view_progress}
                <a
                    href={`/workspace/projects/${project.slug}/progress`}
                    use:inertia
                    class="btn-ghost"
                    aria-label="Progress"
                    ><ChartGantt class="h-3.5 w-3.5" /><span
                        class="hidden sm:inline">Progress</span
                    ></a
                >
            {/if}
            {#if project.can_manage_access && !project.is_archived}
                <button
                    type="button"
                    class="btn-ghost"
                    aria-label="Edit project"
                    onclick={() => (editing = true)}
                >
                    <Pencil class="h-3.5 w-3.5 sm:hidden" />
                    <span class="hidden sm:inline">Edit project</span>
                </button>
            {/if}
            {#if project.can_archive && !project.is_archived}
                <button
                    type="button"
                    class="btn-ghost"
                    aria-label="Archive"
                    onclick={archiveProject}
                >
                    <Archive class="h-3.5 w-3.5 sm:hidden" />
                    <span class="hidden sm:inline">Archive</span>
                </button>
            {/if}
            <button
                type="button"
                class="btn-primary"
                aria-label="Add task"
                onclick={() =>
                    quickAdd.open({ projectId: project.id, lockProject: true })}
            >
                <Plus class="h-3.5 w-3.5 sm:hidden" />
                <span class="hidden sm:inline">Add task</span>
            </button>
        </div>
    {/snippet}

    {#if project.is_archived}
        <div
            class="flex items-center justify-between gap-3 border-b border-line bg-warn-soft px-4 py-2 text-warn"
        >
            <span
                >This project is archived. It is hidden from My work, the
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
        <div class="border-b border-line px-6 py-7 lg:px-8">
            <ProjectEditForm
                {project}
                isSuperAdmin={shared.isSuperAdmin ?? false}
                {programs}
                onclose={() => (editing = false)}
            />
        </div>
    {/if}

    {#if tasks.length === 0}
        <div class="flex flex-col items-start gap-3 px-6 py-12 lg:px-8">
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
        {#if team || git}
            <!-- A new project starts by gathering its team and linking its
                 repositories: side by side on wide screens, each filling
                 its half. -->
            <div
                class={`grid border-t border-line ${team && git ? 'xl:grid-cols-2' : ''}`}
            >
                {#if team}
                    <div class="min-w-0 px-6 py-7 lg:px-8">
                        <ProjectTeamPanel {team} projectTitle={project.title} />
                    </div>
                {/if}
                {#if git}
                    <div
                        class={`min-w-0 px-6 py-7 lg:px-8 ${team ? 'border-t border-line xl:border-t-0 xl:border-l' : ''}`}
                    >
                        <CodeView {project} {git} />
                    </div>
                {/if}
            </div>
        {/if}
        {#if documents}
            <!-- Its documents, the whole width below. -->
            <div class="border-t border-line px-6 py-7 lg:px-8">
                <DocumentsView {project} {documents} />
            </div>
        {/if}
        {#if forms}
            <div class="border-t border-line px-6 py-7 lg:px-8">
                <FormsView {project} {forms} />
            </div>
        {/if}
    {:else}
        <div
            class="flex h-14 items-center gap-1 overflow-x-auto border-b border-line px-6 lg:px-8"
        >
            {#each tabs as tab (tab.value)}
                {@const active = activeTab === tab.value}
                <button
                    type="button"
                    class={`inline-flex h-14 shrink-0 items-center gap-2 border-b-2 px-3.5 font-medium transition ${
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

            {#if activeTab !== 'code' && activeTab !== 'documents' && activeTab !== 'forms'}
                <!-- Filters narrow tasks; Code and Documents list other things. -->
                <ProjectFilters
                    bind:filters
                    {teammates}
                    {categories}
                    shownCount={filteredTasks.length}
                    totalCount={tasks.length}
                />
            {/if}
        </div>

        <!-- Project context: the title block, then the metric cards. Its
             team lives on the People tab. -->
        <div class="flex flex-col gap-4 border-b border-line px-6 py-5 lg:px-8">
            <div>
                <h1 class="text-[17px] font-semibold tracking-[-0.02em]">
                    {project.title}
                </h1>
                {#if project.title_np}
                    <p class="font-np mt-1 text-[13px] text-fg-muted">
                        {project.title_np}
                    </p>
                {/if}
                {#if project.description}
                    <p class="mt-1 max-w-2xl text-[13px] text-fg-muted">
                        {project.description}
                    </p>
                {/if}
                <p class="mt-1.5 text-[13px] text-fg-muted">
                    {#if project.manager}
                        Managed by <span class="text-fg"
                            >{project.manager.name}</span
                        >
                    {:else}
                        <span class="text-warn">No manager yet</span>
                    {/if}
                    {#if team}
                        ·
                        <button
                            type="button"
                            class="hover:text-fg"
                            onclick={() => (activeTab = 'people')}
                            >Team of {team.members.length}</button
                        >
                    {/if}
                </p>
            </div>

            <ProjectSummaryStrip {tasks} {statuses} />
        </div>

        {#if filteredTasks.length === 0 && activeTab !== 'code' && activeTab !== 'documents' && activeTab !== 'forms'}
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
        {:else if activeTab === 'code'}
            {#if git}
                <div class="px-6 py-6 lg:px-8">
                    <CodeView {project} {git} />
                </div>
            {/if}
        {:else if activeTab === 'documents'}
            {#if documents}
                <div class="px-6 py-6 lg:px-8">
                    <DocumentsView {project} {documents} />
                </div>
            {/if}
        {:else if activeTab === 'forms'}
            {#if forms}
                <div class="px-6 py-6 lg:px-8">
                    <FormsView {project} {forms} />
                </div>
            {/if}
        {:else}
            {#if team}
                <div class="border-b border-line px-6 py-6 lg:px-8">
                    <ProjectTeamPanel {team} projectTitle={project.title} />
                </div>
            {/if}
            {#if filteredTasks.length > 0}
                <PeopleView {project} tasks={filteredTasks} {statuses} />
            {/if}
        {/if}
    {/if}
</AppShell>
