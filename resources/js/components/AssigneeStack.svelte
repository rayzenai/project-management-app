<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';
    import type { Assignment, Member, Task } from '../lib/types';
    import Avatar from './Avatar.svelte';
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
        size === 'sm'
            ? 'h-5 w-5 rounded-[5px] text-[9.5px]'
            : 'h-6 w-6 rounded-md text-[10px]',
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
    triggerClass="rounded-md transition hover:bg-hover"
>
    {#snippet trigger()}
        <span class="flex items-center px-0.5 -space-x-0.5">
            {#each visible as assignment (assignment.id)}
                <Avatar
                    name={assignment.member?.name}
                    {size}
                    class="ring-2 ring-surface"
                />
            {/each}
            {#if overflow > 0}
                <span
                    class={`inline-grid place-items-center border border-line bg-surface-alt font-mono text-fg-muted ring-2 ring-surface ${dim}`}
                >
                    +{overflow}
                </span>
            {/if}
            {#if assignments.length === 0}
                <span
                    class={`inline-grid place-items-center border border-dashed border-fg-faint text-fg-faint ${dim}`}
                >
                    <Plus class="h-3 w-3" />
                </span>
            {/if}
        </span>
    {/snippet}

    <div class="px-1 pt-1 pb-1.5">
        <input
            type="text"
            bind:value={query}
            placeholder="Search people"
            class="input"
        />
    </div>

    {#if assignments.length > 0}
        <div class="border-b border-line-soft pb-1">
            {#each assignments as assignment (assignment.id)}
                <div
                    class="flex items-center gap-2 rounded-md px-2 py-1 text-[13px] text-fg-muted"
                >
                    <Avatar name={assignment.member?.name} size="sm" />
                    <span class="min-w-0 flex-1 truncate"
                        >{assignment.member?.name}</span
                    >
                    <button
                        type="button"
                        aria-label={`Unassign ${assignment.member?.name ?? 'member'}`}
                        class="btn-icon h-5 w-5 hover:text-danger disabled:opacity-40"
                        disabled={assignment.id <= 0}
                        onclick={() => unassign(assignment)}
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>
            {/each}
        </div>
    {/if}

    <div class="max-h-52 overflow-auto pt-1">
        {#each candidates.filter((u) => !assignedIds.has(u.id)) as member (member.id)}
            <button
                type="button"
                data-popover-item
                class="menu-item"
                onclick={() => assign(member)}
            >
                <Avatar name={member.name} size="sm" />
                <span class="min-w-0 flex-1 truncate">{member.name}</span>
            </button>
        {:else}
            <p class="px-2 py-1.5 text-xs text-fg-faint">No matches.</p>
        {/each}
    </div>
</Popover>
