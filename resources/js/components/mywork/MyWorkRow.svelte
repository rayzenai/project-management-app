<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Check, CircleDashed, Star } from '@lucide/svelte';
    import { dueLabel } from '../../lib/dates';
    import { formatTimeAgo, plural } from '../../lib/format';
    import { peek } from '../../lib/peek.svelte';
    import { QUIET, toast } from '../../lib/toast.svelte';
    import type { MyWorkGroup, MyWorkRow } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import CompleteCheckbox from '../CompleteCheckbox.svelte';
    import RowMenu from './RowMenu.svelte';

    /**
     * One row of My work. The done circle (a to-do gets its own check; work
     * you asked of others shows who is on it instead), the title, which
     * opens the task, where it belongs, and the date or how late it is. One
     * line from `sm`; on a phone the date and project sit under the title.
     * The latest update, when there is one, goes under both.
     */
    let {
        row,
        group,
        today,
    }: { row: MyWorkRow; group: MyWorkGroup; today: string } = $props();

    /** Kept in a constant: a bare ' · ' at the start of a block loses its spaces. */
    const DOT = ' · ';

    const asked = $derived(group.key === 'asked_others');
    const done = $derived(group.key === 'done');
    const title = $derived(row.subtask?.body ?? row.task.title);
    const where = $derived(
        row.subtask
            ? `${row.task.title} · ${row.task.project.label}`
            : row.task.project.label,
    );
    const late = $derived(
        row.days_late > 0 ? `${plural(row.days_late, 'day')} late` : '',
    );

    /** The day beside the row; left out when the group already says it. */
    const when = $derived.by(() => {
        if (done) {
            return row.task.completed_at
                ? dueLabel(row.task.completed_at.slice(0, 10), today)
                : '';
        }

        const label = row.due
            ? dueLabel(row.due, today)
            : asked
              ? 'No date'
              : '';

        return label === group.label ? '' : label;
    });

    const person = $derived(
        row.people.length === 0
            ? 'Nobody on it'
            : row.people.length === 1
              ? row.people[0].name
              : `${row.people[0].name} +${row.people.length - 1}`,
    );
    const update = $derived(row.subtask ? null : row.latest_update);
    /** Starring or snoozing finished work means nothing: no menu in Done. */
    const menu = $derived(done ? null : row.assignment);

    let todoDone = $state(false);

    /** A to-do's check: one toast, with Undo, like the done circle's. */
    function finishTodo(): void {
        if (!row.subtask || todoDone) {
            return;
        }

        const url = `/workspace/subtasks/${row.subtask.id}`;
        const body = row.subtask.body;
        const quietly = {
            headers: QUIET,
            preserveScroll: true,
            preserveState: true,
        };

        todoDone = true;
        router.patch(
            url,
            { is_done: true },
            {
                ...quietly,
                onSuccess: () =>
                    toast.show(`Completed "${body}"`, {
                        undo: {
                            run: () =>
                                router.patch(url, { is_done: false }, quietly),
                        },
                    }),
                onError: () => (todoDone = false),
            },
        );
    }

    function openTask(): void {
        peek.open({ id: row.task.id, slug: row.task.slug });
    }
</script>

{#snippet day()}
    {#if late}
        <span class="font-medium text-danger">{late}</span>
    {:else if when}
        <span>{when}</span>
    {/if}
{/snippet}

<div
    class={`group flex min-h-[44px] items-start gap-[14px] border-b border-line-soft py-3 pl-5 transition hover:bg-hover sm:-mx-[10px] sm:gap-3 sm:rounded-lg sm:border-0 sm:px-[10px] sm:py-[10px] ${menu ? 'pr-2' : 'pr-5'}`}
>
    <span
        class="flex size-[24px] shrink-0 items-center justify-center sm:mt-[3px] sm:size-[18px]"
    >
        {#if asked}
            {#if row.people.length > 0}
                <Avatar name={row.people[0].name} />
            {:else}
                <CircleDashed
                    class="size-[20px] text-fg-faint sm:size-[16px]"
                    aria-hidden="true"
                />
            {/if}
        {:else if row.subtask}
            <button
                type="button"
                role="checkbox"
                aria-checked={todoDone}
                aria-label={`Mark "${title}" done`}
                class={`relative flex size-[24px] items-center justify-center rounded-[6px] border-[1.5px] transition before:absolute before:-inset-[10px] before:content-[''] sm:size-[18px] sm:rounded-[5px] sm:before:-inset-[13px] ${
                    todoDone
                        ? 'border-success bg-success text-white'
                        : 'border-fg-faint text-transparent hover:border-success hover:text-success'
                }`}
                onclick={finishTodo}
            >
                <Check class="size-[14px] sm:size-[11px]" strokeWidth={3} />
            </button>
        {:else}
            <CompleteCheckbox
                task={row.task}
                projectSlug={row.task.project.slug}
                round
            />
        {/if}
    </span>

    <button
        type="button"
        class="flex min-w-0 flex-1 items-start gap-3 rounded-md text-left"
        onclick={openTask}
    >
        <span class="block min-w-0 flex-1">
            <span class="flex items-baseline gap-3">
                {#if row.assignment?.is_focused}
                    <span class="sr-only">Starred:</span>
                    <Star
                        class="-mr-1.5 size-[14px] shrink-0 self-center fill-current text-accent"
                        aria-hidden="true"
                    />
                {/if}
                <span
                    class={`line-clamp-2 min-w-0 text-[16px] leading-[22px] sm:line-clamp-1 sm:text-[15px] sm:leading-[24px] ${done ? 'text-fg-muted' : 'text-fg'}`}
                    >{title}</span
                >
                <span
                    class="hidden min-w-0 flex-1 truncate text-[13px] text-fg-muted sm:block"
                    >{where}</span
                >
            </span>
            <span
                class="mt-[3px] block truncate text-[13.5px] leading-[19px] text-fg-muted sm:hidden"
            >
                {#if late || when}{@render day()}{DOT}{/if}{#if asked}{person}{DOT}{/if}{where}
            </span>
            {#if update}
                <!-- The body is cut, never how long ago. -->
                <span
                    class="mt-[3px] flex min-w-0 text-[13.5px] leading-[19px] text-fg-muted sm:mt-0 sm:text-[13px] sm:leading-[20px]"
                >
                    <span class="truncate"
                        >{#if update.author}<span class="font-medium"
                                >{update.author}:</span
                            >{` ${update.body}`}{:else}{update.body}{/if}</span
                    >
                    {#if update.at}
                        <span class="shrink-0 whitespace-pre"
                            >{DOT}{formatTimeAgo(update.at)}</span
                        >
                    {/if}
                </span>
            {/if}
        </span>

        <span
            class="hidden h-[24px] shrink-0 items-center gap-1.5 text-[13px] text-fg-muted sm:flex"
        >
            {#if asked}
                <span class="max-w-[160px] truncate">{person}</span>
                {#if late || when}·{/if}
            {/if}
            {@render day()}
        </span>
    </button>

    <!-- The menu's place is kept on every row, so the dates line up. -->
    <span
        class={`shrink-0 sm:-my-[4px] sm:w-[32px] ${menu ? '' : 'max-sm:hidden'}`}
    >
        {#if menu}
            <RowMenu assignment={menu} {title} {today} />
        {/if}
    </span>
</div>
