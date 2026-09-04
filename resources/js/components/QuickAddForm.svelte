<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { ChevronDown, ChevronUp, Plus } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import type { Member, Priority, QuickAddProject } from '../lib/types';
    import AssigneePicker from './AssigneePicker.svelte';
    import PillGroup from './PillGroup.svelte';
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
    const initialProject = untrack(
        () => defaultProjectId ?? projects[0]?.id ?? null,
    );

    // When launched from inside a project, the project is fixed: we hide the
    // selector and show its name as static text instead.
    const lockedProjectName = $derived(
        lockProject
            ? (projects.find((p) => p.id === defaultProjectId)?.title ?? null)
            : null,
    );

    // Empty picker values are stripped before POSTing so parsed title tokens
    // (#project @assignee !priority dates) can fill the gaps server-side;
    // anything explicitly picked here still wins over tokens.
    const form = useForm(
        untrack(() => ({
            project_id: initialProject,
            title: prefill,
            assignee_member_ids: [] as number[],
            deadline_at: '',
            priority: '' as Priority | '',
        })),
    );

    // A title may carry an `@assignee` token that the server resolves. Detect it so an
    // auto-defaulted "self" assignee doesn't silently override what the user typed.
    const titleHasAssigneeToken = $derived(/(^|\s)@\S/.test(form.title));

    form.transform((data) => {
        const payload: Record<string, unknown> = {
            project_id: data.project_id,
            title: data.title,
        };
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

        return payload;
    });

    // Only members on the selected project's team(s) are assignable. Falls back to the
    // full member list when a caller doesn't supply per-project membership.
    const selectedProject = $derived(
        projects.find((p) => p.id === form.project_id) ?? null,
    );
    const projectMemberIds = $derived(
        new Set(selectedProject?.member_ids ?? team.map((m) => m.id)),
    );
    const projectTeam = $derived(
        team.filter((m) => projectMemberIds.has(m.id)),
    );
    const selfEligible = $derived(
        currentMemberId !== null && projectMemberIds.has(currentMemberId),
    );

    // When the project changes (and on mount), drop assignees who aren't on the new
    // project, then default to self when self is on that project.
    let seededProjectId = $state<number | null | undefined>(undefined);
    $effect(() => {
        const pid = form.project_id;

        if (untrack(() => seededProjectId) === pid) {
            return;
        }

        untrack(() => {
            seededProjectId = pid;
            form.assignee_member_ids = form.assignee_member_ids.filter((id) =>
                projectMemberIds.has(id),
            );

            if (
                form.assignee_member_ids.length === 0 &&
                selfEligible &&
                currentMemberId !== null &&
                !titleHasAssigneeToken
            ) {
                form.assignee_member_ids = [currentMemberId];
            }
        });
    });

    let advanced = $state(false);
    let tokenInput = $state<{ focus: () => void } | null>(null);

    export function focusInput(): void {
        tokenInput?.focus();
    }

    function submit() {
        if (!form.title.trim() || !form.project_id) {
            return;
        }

        form.post('/workspace/quick-add', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('title', 'deadline_at');
                // Re-seed the default assignee (self when eligible) for the next task.
                form.assignee_member_ids =
                    selfEligible && currentMemberId !== null
                        ? [currentMemberId]
                        : [];
                form.priority = '';
                tokenInput?.focus();
                onSuccess?.();
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

{#snippet advancedFields()}
    <div class="flex flex-col gap-1">
        <span class="label">Assign to</span>
        {#if projectTeam.length > 0}
            <AssigneePicker
                team={projectTeam}
                bind:selectedIds={form.assignee_member_ids}
                max={5}
                placeholder="Pick teammates"
                flow={variant === 'overlay'}
            />
            {#if selfEligible && !form.assignee_member_ids.includes(currentMemberId ?? -1)}
                <button
                    type="button"
                    class="self-start text-xs font-medium text-accent hover:underline"
                    onclick={assignMe}>Assign me</button
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

{#snippet projectPicker()}
    {#if lockProject}
        {#if lockedProjectName}
            <span class="flex items-center gap-1.5 text-xs text-fg-muted">
                Project
                <span class="font-medium text-fg">{lockedProjectName}</span>
            </span>
        {/if}
    {:else}
        <label class="flex items-center gap-1.5 text-xs text-fg-muted">
            Project
            <select bind:value={form.project_id} class="input h-7 w-auto py-0">
                {#each projects as project (project.id)}
                    <option value={project.id}>{project.title}</option>
                {/each}
            </select>
        </label>
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
                    disabled={form.processing || !form.title.trim()}
                    class="btn-primary">Add</button
                >
            </div>
        </div>

        {#if advanced}
            <div
                class="mt-3 grid grid-cols-1 gap-3 border-t border-line pt-3 sm:grid-cols-3"
            >
                {@render advancedFields()}
            </div>
        {/if}
    {:else}
        <div class="border-b border-line">
            <TokenInput
                bind:this={tokenInput}
                bind:value={form.title}
                placeholder="What needs to happen?"
                disabled={form.processing}
                size="lg"
                onsubmit={submit}
            />
        </div>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-4 py-2 text-xs text-fg-faint"
        >
            <span><kbd class="kbd">#</kbd> project</span>
            <span><kbd class="kbd">@</kbd> assignee</span>
            <span><kbd class="kbd">!</kbd> low, medium, high, urgent</span>
            <span>today, fri, jun 20</span>
        </div>

        <div class="flex items-center justify-between gap-2 px-4 py-2.5">
            <div class="flex items-center">{@render projectPicker()}</div>
            <button
                type="button"
                class="btn-ghost"
                aria-expanded={advanced}
                onclick={() => (advanced = !advanced)}
            >
                {advanced ? 'Less' : 'More'}
                {#if advanced}
                    <ChevronUp class="h-3.5 w-3.5" />
                {:else}
                    <ChevronDown class="h-3.5 w-3.5" />
                {/if}
            </button>
        </div>

        {#if advanced}
            <div
                class="grid grid-cols-1 items-start gap-3 border-t border-line px-4 py-3 sm:grid-cols-3"
            >
                {@render advancedFields()}
            </div>
        {/if}

        <div
            class="flex items-center justify-end gap-1.5 border-t border-line px-4 py-2.5"
        >
            {#if onCancel}
                <button type="button" class="btn-ghost" onclick={onCancel}
                    >Cancel</button
                >
            {/if}
            <button
                type="submit"
                disabled={form.processing || !form.title.trim()}
                class="btn-primary"
            >
                Add task
                <kbd class="kbd border-white/30 bg-transparent text-white/80"
                    >↩</kbd
                >
            </button>
        </div>
    {/if}

    {#if form.errors.title}
        <p
            class="mt-2 text-xs text-danger"
            class:px-4={variant === 'overlay'}
            class:pb-3={variant === 'overlay'}
        >
            {form.errors.title}
        </p>
    {/if}
</form>
