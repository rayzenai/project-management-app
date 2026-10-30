<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { page } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { projectLabel } from '../lib/projectLabel';
    import type {
        Member,
        Priority,
        QuickAddProject,
        SharedProps,
        TaskOption,
    } from '../lib/types';
    import AssigneePicker from './AssigneePicker.svelte';
    import DependsOnPicker from './DependsOnPicker.svelte';
    import PillGroup from './PillGroup.svelte';
    import StatusGlyph from './StatusGlyph.svelte';
    import TokenInput from './TokenInput.svelte';

    let {
        projects,
        team,
        currentMemberId,
        defaultProjectId = null,
        lockProject = false,
        prefill = '',
        variant = 'inline',
        onSuccess,
        onCancel,
    }: {
        projects: QuickAddProject[];
        team: Member[];
        currentMemberId: number | null;
        defaultProjectId?: number | null;
        lockProject?: boolean;
        prefill?: string;
        variant?: 'inline' | 'overlay';
        onSuccess?: () => void;
        onCancel?: () => void;
    } = $props();

    const uid = $props.id();

    // Empty picker values are stripped before POSTing so parsed title tokens
    // (#project @assignee !priority dates) can fill the gaps server-side;
    // anything explicitly picked here still wins over tokens.
    const shared = $derived((page.props ?? {}) as unknown as SharedProps);

    /**
     * An organizational admin whose organization has no project yet starts
     * one, "General", with the task: the picker offers it first and the
     * server makes it (managed by them) on submit.
     */
    const GENERAL = 'general';
    type ProjectChoice = number | typeof GENERAL | null;
    const startGeneral = $derived(
        Boolean(shared.quickAddContext?.startGeneral) && !lockProject,
    );
    const organizationPeople = $derived(
        shared.quickAddContext?.organizationPeople ?? [],
    );
    const organizationId = $derived(shared.organization?.id ?? null);

    const initialProject = untrack(
        (): ProjectChoice =>
            defaultProjectId ??
            (startGeneral ? GENERAL : (projects[0]?.id ?? null)),
    );

    // When launched from inside a project, the project is fixed: we hide the
    // selector and show its name as static text instead.
    const lockedProjectName = $derived(
        lockProject
            ? (projects.find((p) => p.id === defaultProjectId)?.title ?? null)
            : null,
    );

    const lockedProgramName = $derived(
        lockProject
            ? (projects.find((p) => p.id === defaultProjectId)?.program ?? null)
            : null,
    );

    const statuses = $derived(shared.statuses ?? []);
    // A task you just typed has not been started; the plan-tracker's "unclear"
    // default only makes sense for imported commitments.
    const defaultStatus = $derived(
        statuses.find((s) => s.value === 'not_started')?.value ??
            statuses[0]?.value ??
            '',
    );

    const form = useForm(
        untrack(() => ({
            project_id: initialProject,
            title: prefill,
            assignee_member_ids: [] as number[],
            deadline_at: '',
            priority: '' as Priority | '',
            status: '',
            description: '',
        })),
    );

    // Seed the status once the shared workflow arrives.
    $effect(() => {
        if (form.status === '' && defaultStatus !== '') {
            untrack(() => (form.status = defaultStatus));
        }
    });

    // A title may carry an `@assignee` token that the server resolves. Detect it so an
    // auto-defaulted "self" assignee doesn't silently override what the user typed.
    const titleHasAssigneeToken = $derived(/(^|\s)@\S/.test(form.title));

    /**
     * A `#token` in the title beats the picker server-side, so resolve it here
     * too and show the result. Without this the modal could say one project
     * while the task landed in another — and the assignee list would be scoped
     * to the wrong team, so a valid-looking pick got rejected on submit.
     *
     * Mirrors QuickAddDispatcher::resolveProject's match order.
     */
    const tokenProject = $derived.by(() => {
        const match = /(?:^|\s)#([\w-]+)/.exec(form.title);

        if (!match || lockProject) {
            return null;
        }

        const needle = match[1].toLowerCase();
        const ordered = [...projects].sort((a, b) =>
            a.title.localeCompare(b.title),
        );

        return (
            ordered.find((p) => p.slug.toLowerCase() === needle) ??
            ordered.find((p) => p.slug.toLowerCase().startsWith(needle)) ??
            ordered.find((p) => p.title.toLowerCase().startsWith(needle)) ??
            ordered.find((p) => p.slug.toLowerCase().includes(needle)) ??
            ordered.find((p) => p.title.toLowerCase().includes(needle)) ??
            null
        );
    });

    /** What the task will actually be filed under. */
    const effectiveProjectId = $derived(
        tokenProject?.id ??
            (typeof form.project_id === 'number' ? form.project_id : null),
    );
    const isGeneral = $derived(!tokenProject && form.project_id === GENERAL);
    /** Nothing to file it under: Add task waits, and says why. */
    const hasProject = $derived(effectiveProjectId !== null || isGeneral);
    const projectKey = $derived(isGeneral ? GENERAL : effectiveProjectId);

    // Once General exists the context stops offering it (the page reloads
    // after the create): stay on the project it became.
    $effect(() => {
        if (form.project_id === GENERAL && !startGeneral) {
            untrack(() => {
                form.project_id =
                    projects.find((p) => p.organization_id === organizationId)
                        ?.id ?? null;
            });
        }
    });

    /**
     * `@tokens` the server will resolve, mirrored here so the modal can show who
     * the task is actually going to. Matches QuickAddDispatcher::resolveAssignees
     * (name prefix, within the project's assignable members). Unmatched tokens
     * stay in the title, exactly as the server leaves them.
     */
    const tokenAssignees = $derived.by(() => {
        const found: Member[] = [];

        for (const match of form.title.matchAll(/(?:^|\s)@([\w-]+)/g)) {
            const needle = match[1].toLowerCase();
            const member = projectTeam.find((m) =>
                m.name.toLowerCase().startsWith(needle),
            );

            if (member && !found.some((f) => f.id === member.id)) {
                found.push(member);
            }
        }

        return found;
    });

    form.transform((data) => {
        const payload: Record<string, unknown> = { title: data.title };

        if (data.project_id === GENERAL) {
            payload.start_general = true;
        } else {
            payload.project_id = data.project_id;
        }

        let assignees = data.assignee_member_ids;

        // If the only assignee is the auto-defaulted self AND the title names an @assignee,
        // drop it so the token wins; an explicit pick of anyone else is always kept.
        if (
            titleHasAssigneeToken &&
            assignees.length === 1 &&
            assignees[0] === currentMemberId
        ) {
            assignees = [];
        }

        if (assignees.length > 0) {
            payload.assignee_member_ids = assignees;
        }

        if (data.deadline_at) {
            payload.deadline_at = data.deadline_at;
        }

        if (data.priority) {
            payload.priority = data.priority;
        }

        if (data.status) {
            payload.status = data.status;
        }

        if (data.description.trim()) {
            payload.description = data.description.trim();
        }

        if (dependsOn.length > 0) {
            payload.depends_on_task_ids = dependsOn.map((t) => t.id);
        }

        // One-off creates from the dialog land on the new task's project board.
        // "Create another" stays put so a run of tasks isn't interrupted.
        if (variant === 'overlay' && !keepOpen) {
            payload.redirect_to_project = true;
        }

        return payload;
    });

    // Only members on the selected project's team(s) are assignable. Falls back to the
    // full member list when a caller doesn't supply per-project membership. General's
    // team starts with you, its manager.
    const selectedProject = $derived(
        projects.find((p) => p.id === effectiveProjectId) ?? null,
    );
    const projectMemberIds = $derived(
        new Set(
            isGeneral
                ? currentMemberId !== null
                    ? [currentMemberId]
                    : []
                : (selectedProject?.member_ids ?? team.map((m) => m.id)),
        ),
    );
    const projectTeam = $derived(
        team.filter((m) => projectMemberIds.has(m.id)),
    );
    const selfEligible = $derived(
        currentMemberId !== null && projectMemberIds.has(currentMemberId),
    );

    // Whoever runs the project's team may also give the task to anyone active in
    // its organization, who joins the team (the server checks each pick).
    const canAddPeople = $derived(
        isGeneral ||
            (Boolean(selectedProject?.can_add_people) &&
                selectedProject?.organization_id === organizationId),
    );
    const joiners = $derived(
        canAddPeople
            ? organizationPeople.filter((m) => !projectMemberIds.has(m.id))
            : [],
    );
    const assignableIds = $derived(
        new Set([...projectMemberIds, ...joiners.map((m) => m.id)]),
    );
    const pickedJoiners = $derived(
        joiners.filter((m) => form.assignee_member_ids.includes(m.id)),
    );

    // When the project changes (and on mount), drop assignees who aren't on the new
    // project, then default to self when self is on that project.
    let seededProjectId = $state<ProjectChoice | undefined>(undefined);
    $effect(() => {
        const pid = projectKey;

        if (untrack(() => seededProjectId) === pid) {
            return;
        }

        untrack(() => {
            seededProjectId = pid;
            // Dependencies only live inside one project.
            dependsOn = [];
            form.assignee_member_ids = form.assignee_member_ids.filter((id) =>
                assignableIds.has(id),
            );

            if (
                form.assignee_member_ids.length === 0 &&
                selfEligible &&
                currentMemberId !== null &&
                !titleHasAssigneeToken
            ) {
                form.assignee_member_ids = [currentMemberId];
                selfAutoSeeded = true;
            }
        });
    });

    // form.transform already drops the auto-seeded self when an @token is
    // present. Do it in the UI too, so the chips show what will really happen.
    $effect(() => {
        if (
            titleHasAssigneeToken &&
            untrack(() => selfAutoSeeded) &&
            form.assignee_member_ids.length === 1 &&
            form.assignee_member_ids[0] === currentMemberId
        ) {
            untrack(() => {
                form.assignee_member_ids = [];
                selfAutoSeeded = false;
            });
        }
    });

    // You were filled in for convenience: picking someone else gives the task to
    // them instead of to both of you. Picking yourself on purpose is kept.
    $effect(() => {
        const ids = form.assignee_member_ids;

        untrack(() => {
            if (
                !selfAutoSeeded ||
                (ids.length === 1 && ids[0] === currentMemberId)
            ) {
                return;
            }

            selfAutoSeeded = false;

            if (ids.length > 1 && ids.includes(currentMemberId ?? -1)) {
                form.assignee_member_ids = ids.filter(
                    (id) => id !== currentMemberId,
                );
            }
        });
    });

    let advanced = $state(false);
    /** Tasks the new one waits for (same project). */
    let dependsOn = $state<TaskOption[]>([]);
    let selfAutoSeeded = $state(false);
    let tokenInput = $state<{ focus: () => void } | null>(null);
    let keepOpen = $state(false);

    // Validation errors AND service failures (which arrive under `__global`),
    // so a rejected create can never look like a modal that did nothing.
    const errorList = $derived(
        Object.entries(form.errors as Record<string, string>)
            .filter(([, message]) => Boolean(message))
            .map(([field, message]) => ({ field, message })),
    );

    export function focusInput(): void {
        tokenInput?.focus();
    }

    function submit() {
        // Add task is disabled, and says "Pick a project.", in either case.
        if (!form.title.trim() || !hasProject) {
            return;
        }

        form.post('/workspace/quick-add', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('title', 'deadline_at', 'description');
                dependsOn = [];
                // Re-seed the default assignee (self when eligible) for the next task.
                form.assignee_member_ids =
                    selfEligible && currentMemberId !== null
                        ? [currentMemberId]
                        : [];
                selfAutoSeeded = selfEligible && currentMemberId !== null;
                form.priority = '';
                form.status = defaultStatus;
                tokenInput?.focus();

                // "Create another" keeps the modal up with the pickers intact.
                if (!keepOpen) {
                    onSuccess?.();
                }
            },
        });
    }

    function assignMe() {
        if (!currentMemberId) {
            return;
        }

        if (!form.assignee_member_ids.includes(currentMemberId)) {
            form.assignee_member_ids = [
                ...form.assignee_member_ids,
                currentMemberId,
            ];
        }
    }
</script>

{#snippet fields()}
    <div class="flex flex-col gap-1">
        <span class="label">Assign to</span>
        {#if projectTeam.length > 0 || joiners.length > 0}
            <AssigneePicker
                team={projectTeam}
                others={joiners}
                bind:selectedIds={form.assignee_member_ids}
                max={5}
                placeholder={joiners.length > 0
                    ? 'Pick people'
                    : 'Pick teammates'}
                flow={variant === 'overlay'}
            />
            {#if pickedJoiners.length > 0}
                <p class="text-xs text-fg-muted">
                    <span class="font-medium text-fg"
                        >{pickedJoiners.map((m) => m.name).join(', ')}</span
                    >
                    {pickedJoiners.length === 1 ? 'joins' : 'join'} the team.
                </p>
            {/if}
            {#if tokenAssignees.length > 0}
                <p class="text-xs text-fg-muted">
                    Also assigning
                    <span class="font-medium text-fg"
                        >{tokenAssignees.map((m) => m.name).join(', ')}</span
                    >
                    <span class="text-fg-faint">from your @tag</span>
                </p>
            {/if}
            {#if selfEligible && !form.assignee_member_ids.includes(currentMemberId ?? -1)}
                <button
                    type="button"
                    class="self-start text-xs font-medium text-accent hover:underline"
                    onclick={() => {
                        selfAutoSeeded = false;
                        assignMe();
                    }}>Assign me</button
                >
            {/if}
        {:else}
            <p class="text-xs text-fg-faint">
                No teammates on this project's team yet.
            </p>
        {/if}
    </div>
    <div class="flex flex-col gap-1">
        <label for={`${uid}-due`} class="label">Due date</label>
        <input
            id={`${uid}-due`}
            type="date"
            bind:value={form.deadline_at}
            class="input"
        />
    </div>
{/snippet}

{#snippet priorityPicker()}
    <div class="flex flex-col gap-1">
        <span class="label">Priority</span>
        <PillGroup
            dot
            bind:value={form.priority}
            options={[
                { value: 'low', label: 'Low', tone: 'neutral' },
                { value: 'medium', label: 'Medium', tone: 'amber' },
                { value: 'high', label: 'High', tone: 'orange' },
                { value: 'urgent', label: 'Urgent', tone: 'red' },
            ]}
        />
    </div>
{/snippet}

{#snippet statusPicker()}
    <div class="flex flex-col gap-1">
        <span class="label">Status</span>
        <div class="flex flex-wrap gap-1">
            {#each statuses as status (status.value)}
                {@const active = form.status === status.value}
                <button
                    type="button"
                    aria-pressed={active}
                    onclick={() => (form.status = status.value)}
                    class={`inline-flex h-7 items-center gap-1.5 rounded-md border px-2 text-[13px] transition ${
                        active
                            ? 'border-accent bg-accent-soft text-accent'
                            : 'border-line bg-surface text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    <StatusGlyph status={status.value} />
                    {status.label}
                </button>
            {/each}
        </div>
    </div>
{/snippet}

{#snippet errorBox()}
    {#if errorList.length > 0}
        <div
            class="border-t border-line bg-danger-soft px-5 py-2.5"
            role="alert"
        >
            {#each errorList as error (error.field)}
                <p class="text-xs text-danger">{error.message}</p>
            {/each}
        </div>
    {/if}
{/snippet}

{#snippet projectOptions()}
    {#if form.project_id === null}
        <option value={null} disabled>Pick a project</option>
    {/if}
    {#if startGeneral}
        <option value={GENERAL}>General (new)</option>
    {/if}
    {#each projects as project (project.id)}
        <option value={project.id}>{projectLabel(project)}</option>
    {/each}
{/snippet}

{#snippet generalNote()}
    {#if isGeneral}
        <p class="text-xs text-fg-muted">
            Your organization's first project, managed by you.
        </p>
    {/if}
{/snippet}

{#snippet projectField()}
    <div class="flex flex-col gap-1">
        {#if lockProject || tokenProject}
            {@const shown = lockProject
                ? {
                      title: lockedProjectName,
                      program: lockedProgramName,
                  }
                : tokenProject}
            <span class="label">Project</span>
            <p class="flex min-h-[30px] items-center gap-1.5 text-[13px]">
                <span class="font-medium text-fg">{shown?.title}</span>
                {#if shown?.program}
                    <span class="text-fg-faint">{shown.program}</span>
                {/if}
                {#if tokenProject}
                    <span class="text-fg-faint">from your #tag</span>
                {/if}
            </p>
        {:else}
            <label for={`${uid}-project`} class="label">Project</label>
            <select
                id={`${uid}-project`}
                bind:value={form.project_id}
                class="input"
            >
                {@render projectOptions()}
            </select>
            {@render generalNote()}
        {/if}
    </div>
{/snippet}

{#snippet projectPicker()}
    {#if lockProject}
        {#if lockedProjectName}
            <span class="flex items-center gap-1.5 text-xs text-fg-muted">
                Project
                <span class="font-medium text-fg">{lockedProjectName}</span>
                {#if lockedProgramName}
                    <span class="text-fg-faint">{lockedProgramName}</span>
                {/if}
            </span>
        {/if}
    {:else if tokenProject}
        <span class="flex items-center gap-1.5 text-xs text-fg-muted">
            Project
            <span class="font-medium text-fg">{tokenProject.title}</span>
            {#if tokenProject.program}
                <span class="text-fg-faint">{tokenProject.program}</span>
            {/if}
            <span class="text-fg-faint">from your #tag</span>
        </span>
    {:else}
        <label class="flex items-center gap-1.5 text-xs text-fg-muted">
            Project
            <select bind:value={form.project_id} class="input h-7 w-auto py-0">
                {@render projectOptions()}
            </select>
        </label>
    {/if}
{/snippet}

{#snippet missingProject()}
    {#if !hasProject}
        <span id={`${uid}-why`} class="text-xs text-fg-muted"
            >Pick a project.</span
        >
    {/if}
{/snippet}

<form
    onsubmit={(e) => {
        e.preventDefault();
        submit();
    }}
>
    {#if variant === 'inline'}
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                <Plus class="h-4 w-4 shrink-0 text-accent" />
                <div class="min-w-0 flex-1">
                    <TokenInput
                        bind:this={tokenInput}
                        bind:value={form.title}
                        placeholder="What needs to happen? (press N anywhere)"
                        disabled={form.processing}
                        onsubmit={submit}
                    />
                </div>
            </div>
            <div class="flex items-center gap-1.5">
                {@render projectPicker()}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (advanced = !advanced)}
                    >{advanced ? 'Less' : 'More'}</button
                >
                <button
                    type="submit"
                    disabled={form.processing ||
                        !form.title.trim() ||
                        !hasProject}
                    aria-describedby={hasProject ? undefined : `${uid}-why`}
                    title={hasProject ? undefined : 'Pick a project.'}
                    class="btn-primary">Add</button
                >
            </div>
        </div>

        {#if !hasProject || isGeneral}
            <div class="mt-1.5 flex justify-end gap-3">
                {@render missingProject()}
                {@render generalNote()}
            </div>
        {/if}

        {#if advanced}
            <div
                class="mt-3 grid grid-cols-1 gap-3 border-t border-line pt-3 sm:grid-cols-3"
            >
                {@render fields()}
                {@render priorityPicker()}
                <div class="sm:col-span-3">{@render statusPicker()}</div>
                <div class="sm:col-span-3">
                    <DependsOnPicker
                        projectSlug={selectedProject?.slug ?? null}
                        bind:selected={dependsOn}
                    />
                </div>
            </div>
        {/if}
    {:else}
        <div class="border-b border-line px-5 pt-3">
            <span class="label">Title</span>
            <div class="-mx-5">
                <TokenInput
                    bind:this={tokenInput}
                    bind:value={form.title}
                    placeholder="What needs to happen?"
                    disabled={form.processing}
                    size="xl"
                    onsubmit={submit}
                />
            </div>
        </div>

        <div class="border-b border-line px-5 py-4">
            <label for={`${uid}-desc`} class="label">Description</label>
            <textarea
                id={`${uid}-desc`}
                bind:value={form.description}
                rows="4"
                placeholder="Any detail worth writing down (optional)"
                class="input mt-1.5 resize-y"
            ></textarea>
        </div>

        <!-- Everything is on screen: hiding the assignee, date and priority
             behind "More" was the reason tasks got created without them. The
             project sits with them, since it decides who can be picked. -->
        <div
            class="grid grid-cols-1 items-start gap-x-6 gap-y-4 border-b border-line px-5 py-4 sm:grid-cols-3"
        >
            {@render projectField()}
            {@render fields()}
        </div>

        <div class="border-b border-line px-5 py-4">
            <DependsOnPicker
                projectSlug={selectedProject?.slug ?? null}
                bind:selected={dependsOn}
            />
        </div>

        <div
            class="flex flex-wrap items-start gap-x-10 gap-y-4 border-b border-line px-5 py-4"
        >
            {@render priorityPicker()}
            {@render statusPicker()}
        </div>

        <div
            class="flex flex-wrap items-center gap-x-5 gap-y-1 border-b border-line px-5 py-2.5 text-xs text-fg-faint"
        >
            <span>Type <kbd class="kbd">#</kbd> project</span>
            <span><kbd class="kbd">@</kbd> assignee</span>
            <span><kbd class="kbd">!</kbd> priority</span>
            <span>or a date: today, fri, jun 20</span>
        </div>

        {@render errorBox()}

        <div
            class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3"
        >
            {@render missingProject()}
            <label
                class="ml-auto flex cursor-pointer items-center gap-1.5 text-xs text-fg-muted"
            >
                <input
                    type="checkbox"
                    bind:checked={keepOpen}
                    class="accent-[var(--ws-accent)]"
                />
                Create another
            </label>
            {#if onCancel}
                <button type="button" class="btn-ghost" onclick={onCancel}
                    >Cancel</button
                >
            {/if}
            <button
                type="submit"
                disabled={form.processing || !form.title.trim() || !hasProject}
                aria-describedby={hasProject ? undefined : `${uid}-why`}
                title={hasProject ? undefined : 'Pick a project.'}
                class="btn-primary"
            >
                {form.processing ? 'Adding...' : 'Add task'}
                <kbd class="kbd border-white/30 bg-transparent text-white/80"
                    >&#8629;</kbd
                >
            </button>
        </div>
    {/if}

    {#if variant === 'inline' && errorList.length > 0}
        <div class="mt-2" role="alert">
            {#each errorList as error (error.field)}
                <p class="text-xs text-danger">{error.message}</p>
            {/each}
        </div>
    {/if}
</form>
