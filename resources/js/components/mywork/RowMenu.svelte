<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Ellipsis, Moon, Star, StarOff, Sun, X } from '@lucide/svelte';
    import { shiftDay, shortDay } from '../../lib/dates';
    import { formatDay, isNepali, t } from '../../lib/i18n';
    import { QUIET, toast } from '../../lib/toast.svelte';
    import type { MyWorkTaskRow } from '../../lib/types';
    import Popover from '../Popover.svelte';

    /**
     * A row's own menu on My work: star it, snooze it, or give it your own
     * date. Only the person on the assignment changes these (Task 3), and on
     * My work that is always the viewer. Days are the office's Y-m-d. The
     * menu says what it did itself (a star says nothing: the filled star
     * is the answer), so the server stays quiet. "Tomorrow" is the next
     * working day, as in the Tomorrow group.
     */
    let {
        assignment,
        title,
        today,
        tomorrow,
    }: {
        assignment: NonNullable<MyWorkTaskRow['assignment']>;
        title: string;
        today: string;
        tomorrow: string;
    } = $props();

    let open = $state(false);
    let snoozeDate = $state('');
    let ownDate = $state('');

    const snoozed = $derived(
        assignment.snoozed_until !== null && assignment.snoozed_until > today,
    );

    $effect(() => {
        if (open) {
            snoozeDate = assignment.snoozed_until ?? '';
            ownDate = assignment.personal_due_at ?? '';
        }
    });

    function patch(
        data: Record<string, string | boolean | null>,
        said?: string,
    ): void {
        open = false;
        router.patch(`/workspace/assignments/${assignment.id}`, data, {
            headers: QUIET,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (said) {
                    toast.show(said);
                }
            },
        });
    }

    /** "Thu 12 Nov", in the reader's language. */
    function shortText(iso: string): string {
        return isNepali()
            ? formatDay(iso, {
                  weekday: 'short',
                  day: 'numeric',
                  month: 'short',
              })
            : shortDay(iso);
    }

    function snooze(until: string | null): void {
        patch(
            { snoozed_until: until },
            until
                ? t('Snoozed until :day', { day: shortText(until) })
                : t('No longer snoozed'),
        );
    }

    function setOwnDate(day: string | null): void {
        patch(
            { personal_due_at: day },
            day
                ? t('Your date: :day', { day: shortText(day) })
                : t('Your date cleared'),
        );
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
    triggerLabel={t('Options for :title', { title })}
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
            {t('Remove star')}
        {:else}
            <Star class="size-4 shrink-0" />
            {t('Star')}
        {/if}
    </button>

    <div class="my-1 border-t border-line-soft"></div>

    <button
        type="button"
        data-popover-item
        class="menu-item"
        onclick={() => snooze(tomorrow)}
    >
        <Moon class="size-4 shrink-0" />
        {t('Snooze until tomorrow')}
    </button>
    <button
        type="button"
        data-popover-item
        class="menu-item"
        onclick={() => snooze(shiftDay(today, 7))}
    >
        <Moon class="size-4 shrink-0" />
        {t('Snooze for a week')}
    </button>
    <form
        class="px-2 pt-1 pb-1.5"
        onsubmit={(e) => {
            e.preventDefault();
            snooze(snoozeDate);
        }}
    >
        <label class="label" for={`snooze-${assignment.id}`}
            >{t('Snooze until')}</label
        >
        <div class="mt-1 flex items-center gap-1.5">
            <input
                id={`snooze-${assignment.id}`}
                type="date"
                class="input min-w-0 flex-1"
                min={shiftDay(today, 1)}
                required
                bind:value={snoozeDate}
                onkeydown={keepKeys}
            />
            <button type="submit" class="btn">{t('Snooze')}</button>
        </div>
    </form>
    {#if snoozed}
        <button
            type="button"
            data-popover-item
            class="menu-item"
            onclick={() => snooze(null)}
        >
            <Sun class="size-4 shrink-0" />
            {t('Stop snoozing')}
        </button>
    {/if}

    <div class="my-1 border-t border-line-soft"></div>

    <form
        class="px-2 pt-1 pb-1.5"
        onsubmit={(e) => {
            e.preventDefault();
            setOwnDate(ownDate);
        }}
    >
        <label class="label" for={`own-date-${assignment.id}`}
            >{t('My own date')}</label
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
            <button type="submit" class="btn">{t('Set')}</button>
        </div>
    </form>
    {#if assignment.personal_due_at}
        <button
            type="button"
            data-popover-item
            class="menu-item"
            onclick={() => setOwnDate(null)}
        >
            <X class="size-4 shrink-0" />
            {t('Clear my own date')}
        </button>
    {/if}
</Popover>
