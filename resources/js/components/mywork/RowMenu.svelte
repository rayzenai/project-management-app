<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Ellipsis, Moon, Star, StarOff, Sun, X } from '@lucide/svelte';
    import { shiftDay } from '../../lib/dates';
    import type { MyWorkRow } from '../../lib/types';
    import Popover from '../Popover.svelte';

    /**
     * A row's own menu on My work: star it, snooze it, or give it your own
     * date. Only the person on the assignment changes these (Task 3), and on
     * My work that is always the viewer. Days are the office's Y-m-d.
     */
    let {
        assignment,
        title,
        today,
    }: {
        assignment: NonNullable<MyWorkRow['assignment']>;
        title: string;
        today: string;
    } = $props();

    let open = $state(false);
    let snoozeDate = $state('');
    let ownDate = $state('');

    const tomorrow = $derived(shiftDay(today, 1));
    const snoozed = $derived(
        assignment.snoozed_until !== null && assignment.snoozed_until > today,
    );

    $effect(() => {
        if (open) {
            snoozeDate = assignment.snoozed_until ?? '';
            ownDate = assignment.personal_due_at ?? '';
        }
    });

    function patch(data: Record<string, string | boolean | null>): void {
        open = false;
        router.patch(`/workspace/assignments/${assignment.id}`, data, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    /** Arrow keys belong to the date field, not the menu's item focus. */
    function keepKeys(event: KeyboardEvent): void {
        if (event.key !== 'Escape') {
            event.stopPropagation();
        }
    }
</script>

<Popover
    bind:open
    align="right"
    triggerLabel={`Options for ${title}`}
    triggerClass={`btn-icon size-[44px] sm:size-[32px] ${open ? 'bg-hover text-fg' : 'pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:group-focus-within:opacity-100'}`}
    panelClass="w-64"
>
    {#snippet trigger()}
        <Ellipsis class="size-4" />
    {/snippet}

    <button
        type="button"
        data-popover-item
        class="menu-item"
        onclick={() => patch({ is_focused: !assignment.is_focused })}
    >
        {#if assignment.is_focused}
            <StarOff class="size-4 shrink-0" />
            Remove star
        {:else}
            <Star class="size-4 shrink-0" />
            Star
        {/if}
    </button>

    <div class="my-1 border-t border-line-soft"></div>

    <button
        type="button"
        data-popover-item
        class="menu-item"
        onclick={() => patch({ snoozed_until: tomorrow })}
    >
        <Moon class="size-4 shrink-0" />
        Snooze until tomorrow
    </button>
    <button
        type="button"
        data-popover-item
        class="menu-item"
        onclick={() => patch({ snoozed_until: shiftDay(today, 7) })}
    >
        <Moon class="size-4 shrink-0" />
        Snooze for a week
    </button>
    <form
        class="px-2 pt-1 pb-1.5"
        onsubmit={(e) => {
            e.preventDefault();
            patch({ snoozed_until: snoozeDate });
        }}
    >
        <label class="label" for={`snooze-${assignment.id}`}>Snooze until</label
        >
        <div class="mt-1 flex items-center gap-1.5">
            <input
                id={`snooze-${assignment.id}`}
                type="date"
                class="input min-w-0 flex-1"
                min={tomorrow}
                required
                bind:value={snoozeDate}
                onkeydown={keepKeys}
            />
            <button type="submit" class="btn">Snooze</button>
        </div>
    </form>
    {#if snoozed}
        <button
            type="button"
            data-popover-item
            class="menu-item"
            onclick={() => patch({ snoozed_until: null })}
        >
            <Sun class="size-4 shrink-0" />
            Stop snoozing
        </button>
    {/if}

    <div class="my-1 border-t border-line-soft"></div>

    <form
        class="px-2 pt-1 pb-1.5"
        onsubmit={(e) => {
            e.preventDefault();
            patch({ personal_due_at: ownDate });
        }}
    >
        <label class="label" for={`own-date-${assignment.id}`}
            >My own date</label
        >
        <div class="mt-1 flex items-center gap-1.5">
            <input
                id={`own-date-${assignment.id}`}
                type="date"
                class="input min-w-0 flex-1"
                required
                bind:value={ownDate}
                onkeydown={keepKeys}
            />
            <button type="submit" class="btn">Set</button>
        </div>
    </form>
    {#if assignment.personal_due_at}
        <button
            type="button"
            data-popover-item
            class="menu-item"
            onclick={() => patch({ personal_due_at: null })}
        >
            <X class="size-4 shrink-0" />
            Clear my own date
        </button>
    {/if}
</Popover>
