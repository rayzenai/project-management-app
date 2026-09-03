<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { initials } from '../lib/format';
    import type { Assignment, Member, Task } from '../lib/types';
    import Popover from './Popover.svelte';

    let {
        task,
        team,
        max = 3,
        size = 'md',
        align = 'right',
        onUpdated,
    }: {
        task: Pick<Task, 'id' | 'slug'> & { assignments?: Assignment[] };
        team: Member[];
        max?: number;
        size?: 'sm' | 'md';
        align?: 'left' | 'right';
        onUpdated?: () => void;
    } = $props();

    let open = $state(false);
    let query = $state('');
    let busy = $state(false);

    const assignments = $derived(task.assignments ?? []);
    const assignedIds = $derived(new Set(assignments.map((a) => a.member_id)));
    const visible = $derived(assignments.slice(0, max));
    const overflow = $derived(assignments.length - max);
    const candidates = $derived(
        team.filter(
            (u) =>
                query.trim() === '' ||
                u.name.toLowerCase().includes(query.toLowerCase()) ||
                (u.email ?? '').toLowerCase().includes(query.toLowerCase()),
        ),
    );

    const dim = $derived(
        size === 'sm' ? 'h-5 w-5 text-[9px]' : 'h-6 w-6 text-[10px]',
    );

    function assign(member: Member) {
        if (busy || assignedIds.has(member.id)) {
            return;
        }

        busy = true;

        router.post(
            `/workspace/tasks/${task.id}/assignments`,
            { member_id: member.id },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => (busy = false),
                onSuccess: () => onUpdated?.(),
            },
        );
    }

    function unassign(assignment: Assignment) {
        if (busy || assignment.id <= 0) {
            return;
        }

        busy = true;

        router.delete(`/workspace/assignments/${assignment.id}`, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => (busy = false),
            onSuccess: () => onUpdated?.(),
        });
    }
</script>

<Popover
    bind:open
    role="dialog"
    {align}
    triggerLabel="Assignees"
    panelClass="w-64"
>
    {#snippet trigger()}
        <span class="flex items-center -space-x-1.5">
            {#each visible as assignment (assignment.id)}
                <span
                    title={assignment.member?.name}
                    class={`flex items-center justify-center rounded-full bg-surface-alt font-semibold text-fg-muted ring-2 ring-surface ${dim}`}
                >
                    {initials(assignment.member?.name)}
                </span>
            {/each}
            {#if overflow > 0}
                <span
                    class={`flex items-center justify-center rounded-full bg-surface-alt font-mono text-fg-muted ring-2 ring-surface ${dim}`}
                >
                    +{overflow}
                </span>
            {/if}
            {#if assignments.length === 0}
                <span
                    class={`flex items-center justify-center rounded-full border border-dashed border-line text-fg-faint ${dim}`}
                >
                    +
                </span>
            {/if}
        </span>
    {/snippet}

    <div class="px-2 pt-1 pb-2">
        <input
            type="text"
            bind:value={query}
            placeholder="Search people…"
            class="w-full rounded-md border border-line bg-surface px-2 py-1 text-sm text-fg"
        />
    </div>

    {#if assignments.length > 0}
        <div class="border-b border-line-soft px-1 pb-1">
            {#each assignments as assignment (assignment.id)}
                <div
                    class="flex items-center gap-2 rounded px-2 py-1 text-sm text-fg-muted"
                >
                    <span class="min-w-0 flex-1 truncate"
                        >{assignment.member?.name}</span
                    >
                    <button
                        type="button"
                        aria-label={`Unassign ${assignment.member?.name ?? 'member'}`}
                        class="text-fg-faint hover:text-danger disabled:opacity-40"
                        disabled={assignment.id <= 0}
                        onclick={() => unassign(assignment)}
                    >
                        ×
                    </button>
                </div>
            {/each}
        </div>
    {/if}

    <div class="max-h-52 overflow-auto px-1 pt-1">
        {#each candidates.filter((u) => !assignedIds.has(u.id)) as member (member.id)}
            <button
                type="button"
                data-popover-item
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-fg-muted hover:bg-surface-alt"
                onclick={() => assign(member)}
            >
                <span
                    class="flex h-5 w-5 items-center justify-center rounded-full bg-surface-alt text-[9px] font-semibold text-fg-muted"
                >
                    {initials(member.name)}
                </span>
                <span class="min-w-0 flex-1 truncate">{member.name}</span>
            </button>
        {:else}
            <p class="px-2 py-1.5 text-xs text-fg-faint">No matches.</p>
        {/each}
    </div>
</Popover>
