<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { page } from '@inertiajs/svelte';
    import {
        CalendarDays,
        ChevronDown,
        Folder,
        Plus,
        UserPlus,
    } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { isoDay, shiftDay, shortDay } from '../lib/dates';
    import { projectLabel } from '../lib/projectLabel';
    import { tokenDate } from '../lib/quickAddTokens';
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
    import Popover from './Popover.svelte';
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
        today = isoDay(new Date()),
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
        /** The office's today, Y-m-d: what Today, Tomorrow and a typed date count from. */
        today?: string;
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

    // The project being viewed, else where the person last added a task.
    const initialProject = untrack(
        (): ProjectChoice =>
            defaultProjectId ??
            shared.quickAddContext?.lastProjectId ??
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
            selfAutoSeeded &&
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

    // The dialog fills you in; the add field leaves Who empty, and the
    // server gives the task to you when nobody is named.
    const seedsSelf = $derived(variant === 'overlay');

    /**
     * What the add field's chips show. A picked date or person wins over a
     * typed one, as on the server; a typed `#project` wins over the picked
     * project (tokenProject). Display only: the server stays the authority.
     */
    const shownDate = $derived(
        form.deadline_at || tokenDate(form.title, today),
    );
    const whoNames = $derived(
        form.assignee_member_ids.length > 0
            ? [...projectTeam, ...joiners]
                  .filter((m) => form.assignee_member_ids.includes(m.id))
                  .map((m) => m.name)
            : tokenAssignees.map((m) => m.name),
    );
    const projectChip = $derived(
        selectedProject
            ? projectLabel(selectedProject)
            : isGeneral
              ? 'General (new)'
              : 'Project',
    );

    let dateOpen = $state(false);

    function pickDate(day: string): void {
        form.deadline_at = day;
        dateOpen = false;
        tokenInput?.focus();
    }

    const CHIP =
        'inline-flex h-[34px] max-w-full min-w-0 items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 text-[14px] font-medium text-fg transition hover:bg-hover sm:h-[28px] sm:text-[13px]';
    const EMPTY_CHIP = 'border-dashed text-fg-muted';

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
                seedsSelf &&
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
        // The add field stays enabled while adding (a disabled field loses
        // its focus), so a second Enter waits here.
        if (form.processing || !form.title.trim() || !hasProject) {
            return;
        }

        // A one-off create from the dialog closes it and lands on the project
        // board, which destroys this form before onSuccess runs: decide now,
        // and read none of its deriveds once it is gone. "Create another"
        // (and the inline bar) stays open, so it resets for the next task.
        const closeAfter = keepOpen ? undefined : onSuccess;

        form.post('/workspace/quick-add', {
            preserveScroll: true,
            onSuccess: () => {
                if (closeAfter) {
                    closeAfter();

                    return;
                }

                form.reset('title', 'deadline_at', 'description');
                dependsOn = [];
                // Re-seed the default assignee (self when eligible) for the
                // next task; the add field keeps only its project.
                form.assignee_member_ids =
                    seedsSelf && selfEligible && currentMemberId !== null
                        ? [currentMemberId]
                        : [];
                selfAutoSeeded = form.assignee_member_ids.length > 0;
                form.priority = '';
                form.status = defaultStatus;
                tokenInput?.focus();
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
        <div
            class="rounded-lg border border-line bg-surface transition focus-within:border-accent focus-within:ring-[3px] focus-within:ring-accent-soft"
        >
            <div class="flex items-center gap-3 px-3.5">
                <Plus class="h-4 w-4 shrink-0 text-accent" />
                <div class="min-w-0 flex-1">
                    <TokenInput
                        bind:this={tokenInput}
                        bind:value={form.title}
                        placeholder="Add a task"
                        size="lg"
                        onsubmit={submit}
                    />
                </div>
            </div>

            <div
                class="flex flex-wrap items-center gap-2 px-3.5 pb-3 sm:pl-[42px]"
            >
                <Popover
                    bind:open={dateOpen}
                    role="dialog"
                    triggerLabel={shownDate
                        ? `Date ${shortDay(shownDate)}`
                        : 'Date'}
                    triggerClass={`${CHIP} ${shownDate ? '' : EMPTY_CHIP}`}
                    panelClass="w-60"
                >
                    {#snippet trigger()}
                        <CalendarDays
                            class="h-3.5 w-3.5 shrink-0 text-fg-muted"
                        />
                        {shownDate ? shortDay(shownDate) : 'Date'}
                    {/snippet}
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item"
                        onclick={() => pickDate(today)}
                    >
                        Today
                        <span class="ml-auto text-xs text-fg-faint"
                            >{shortDay(today)}</span
                        >
                    </button>
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item"
                        onclick={() => pickDate(shiftDay(today, 1))}
                    >
                        Tomorrow
                        <span class="ml-auto text-xs text-fg-faint"
                            >{shortDay(shiftDay(today, 1))}</span
                        >
                    </button>
                    <div class="px-1 py-1">
                        <input
                            type="date"
                            aria-label="Pick a date"
                            bind:value={form.deadline_at}
                            class="input"
                        />
                    </div>
                    {#if form.deadline_at}
                        <button
                            type="button"
                            data-popover-item
                            class="menu-item"
                            onclick={() => pickDate('')}>No date</button
                        >
                    {/if}
                </Popover>

                {#if tokenProject}
                    <span class={CHIP} title="From your #tag">
                        <Folder class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
                        <span class="truncate">{projectChip}</span>
                    </span>
                {:else}
                    <label
                        class={`${CHIP} relative cursor-pointer focus-within:border-accent ${hasProject ? '' : EMPTY_CHIP}`}
                    >
                        <Folder class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
                        <span class="max-w-[16rem] truncate">{projectChip}</span
                        >
                        <ChevronDown
                            class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                        />
                        <!-- The native list (a phone's own picker), laid
                             invisibly over the chip; 16px so iOS doesn't zoom. -->
                        <select
                            aria-label="Project"
                            bind:value={form.project_id}
                            class="absolute inset-0 cursor-pointer appearance-none opacity-0 text-[16px]"
                        >
                            {@render projectOptions()}
                        </select>
                    </label>
                {/if}

                <Popover
                    role="dialog"
                    triggerLabel={whoNames.length > 0
                        ? `Who: ${whoNames.join(', ')}`
                        : 'Who'}
                    triggerClass={`${CHIP} ${whoNames.length > 0 ? '' : EMPTY_CHIP}`}
                    panelClass="w-[300px]"
                >
                    {#snippet trigger()}
                        <UserPlus class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
                        <span class="max-w-[12rem] truncate"
                            >{whoNames.length === 0
                                ? 'Who'
                                : whoNames.length === 1
                                  ? whoNames[0]
                                  : `${whoNames[0]} +${whoNames.length - 1}`}</span
                        >
                    {/snippet}
                    <AssigneePicker
                        listOnly
                        team={projectTeam}
                        others={joiners}
                        bind:selectedIds={form.assignee_member_ids}
                        max={5}
                    />
                </Popover>

                <!-- A touch screen (a phone, an iPad) gets a button. -->
                <span
                    class="ml-auto hidden items-center gap-1.5 text-[12.5px] text-fg-muted lg:inline-flex"
                    ><kbd class="kbd">Enter</kbd> to add</span
                >
                <button
                    type="submit"
                    disabled={form.processing ||
                        !form.title.trim() ||
                        !hasProject}
                    aria-describedby={hasProject ? undefined : `${uid}-why`}
                    class="btn-primary ml-auto lg:hidden">Add</button
                >
            </div>

            {#if !hasProject || isGeneral || pickedJoiners.length > 0}
                <div class="flex flex-col gap-1 px-3.5 pb-3 sm:pl-[42px]">
                    {@render missingProject()}
                    {@render generalNote()}
                    {#if pickedJoiners.length > 0}
                        <p class="text-xs text-fg-muted">
                            <span class="font-medium text-fg"
                                >{pickedJoiners
                                    .map((m) => m.name)
                                    .join(', ')}</span
                            >
                            {pickedJoiners.length === 1 ? 'joins' : 'join'} the team.
                        </p>
                    {/if}
                </div>
            {/if}
        </div>
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
