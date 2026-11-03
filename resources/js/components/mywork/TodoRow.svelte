<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        CalendarX,
        Check,
        Ellipsis,
        FolderInput,
        Trash2,
    } from '@lucide/svelte';
    import { dueLabel, shortDay } from '../../lib/dates';
    import { QUIET, toast } from '../../lib/toast.svelte';
    import type {
        Id,
        MyWorkGroup,
        MyWorkTodoRow,
        SharedProps,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import ProjectPicker from '../ProjectPicker.svelte';

    /**
     * One of your private to-dos on My work ("Just for me"): the circle
     * ticks it done (one toast, with Undo), the title is plain text, "Only
     * you" stands where a task's project would, then its date. Its menu
     * moves it into a project as a task, changes its date or deletes it.
     * Nobody else ever sees it.
     */
    let {
        row,
        group,
        today,
    }: {
        row: MyWorkTodoRow;
        group: MyWorkGroup;
        today: string;
    } = $props();

    /** Kept in a constant: a bare ' · ' at the start of a block loses its spaces. */
    const DOT = ' · ';
    const WHERE = 'Only you';

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const context = $derived(shared.quickAddContext ?? null);

    const todo = $derived(row.todo);
    const url = $derived(`/workspace/todos/${row.todo.id}`);
    const inDone = $derived(group.key === 'done');

    /** The day beside the row; left out when the group already says it. */
    const when = $derived.by(() => {
        if (inDone) {
            return todo.done_at
                ? dueLabel(todo.done_at.slice(0, 10), today)
                : '';
        }

        const label = row.due ? dueLabel(row.due, today) : '';

        return label === group.label ? '' : label;
    });

    const quietly = {
        headers: QUIET,
        preserveScroll: true,
        preserveState: true,
    };

    let optimistic = $state<boolean | null>(null);
    const checked = $derived(optimistic ?? todo.done_at !== null);

    let menuOpen = $state(false);
    let moving = $state(false);
    let day = $state('');
    let menuWrap = $state<HTMLElement | null>(null);

    $effect(() => {
        if (menuOpen) {
            day = todo.due_on ?? '';
        }
    });

    // The project list closes on its own (a pick, Escape, a press outside):
    // the focus goes back to the row's menu.
    let wasMoving = false;
    $effect(() => {
        if (moving) {
            wasMoving = true;
        } else if (wasMoving) {
            wasMoving = false;
            menuWrap?.querySelector('button')?.focus();
        }
    });

    /** Done, or open again from Done: the page says which itself. */
    function toggle(): void {
        const next = !checked;
        const title = todo.title;
        const target = url;

        optimistic = next;
        router.patch(
            target,
            { done: next },
            {
                ...quietly,
                onSuccess: () =>
                    toast.show(
                        next ? `Completed "${title}"` : `Reopened "${title}"`,
                        next
                            ? {
                                  undo: {
                                      run: () =>
                                          router.patch(
                                              target,
                                              { done: false },
                                              quietly,
                                          ),
                                  },
                              }
                            : {},
                    ),
                onFinish: () => (optimistic = null),
            },
        );
    }

    function setDay(next: string | null): void {
        menuOpen = false;
        router.patch(
            url,
            { due_on: next },
            {
                ...quietly,
                onSuccess: () =>
                    toast.show(
                        next ? `Date: ${shortDay(next)}` : 'Date cleared',
                    ),
            },
        );
    }

    function remove(): void {
        menuOpen = false;
        router.delete(url, {
            ...quietly,
            onSuccess: () => toast.show('To-do deleted.'),
        });
    }

    function startMove(): void {
        menuOpen = false;
        moving = true;
    }

    /** The server lands on the new task, wherever it went. */
    function moveTo(projectId: Id | 'general' | 'personal'): void {
        if (typeof projectId === 'number') {
            router.post(`${url}/move`, { project_id: projectId });
        }
    }

    /** Arrow keys belong to the date field, not the menu's item focus. */
    function keepKeys(event: KeyboardEvent): void {
        if (event.key !== 'Escape') {
            event.stopPropagation();
        }
    }
</script>

<div
    class={`group flex min-h-[44px] items-start gap-[14px] border-b border-line-soft py-3 pl-5 transition hover:bg-hover sm:-mx-[10px] sm:gap-3 sm:rounded-lg sm:border-0 sm:px-[10px] sm:py-[10px] ${inDone ? 'pr-5' : 'pr-2'}`}
>
    <span
        class="flex size-[24px] shrink-0 items-center justify-center sm:mt-[3px] sm:size-[18px]"
    >
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={checked
                ? `Mark "${todo.title}" not done`
                : `Mark "${todo.title}" done`}
            class={`relative flex size-[24px] items-center justify-center rounded-full border-[1.5px] transition before:absolute before:-inset-[10px] before:content-[''] sm:size-[18px] sm:before:-inset-[13px] ${
                checked
                    ? 'border-success bg-success text-white'
                    : 'border-fg-faint text-transparent hover:border-success hover:text-success'
            }`}
            onclick={toggle}
        >
            <Check class="size-[14px] sm:size-[11px]" strokeWidth={3} />
        </button>
    </span>

    <div class="relative flex min-w-0 flex-1 items-start gap-3">
        {#if moving && context}
            <!-- Opened from the menu, under the title: its trigger is
                 never seen and takes no room. -->
            <span class="absolute bottom-0 left-0 size-0">
                <ProjectPicker
                    bind:open={moving}
                    projects={context.projects}
                    organizations={context.organizations ?? []}
                    currentMemberId={context.currentMemberId}
                    homeOrganizationId={shared.organization?.id ?? null}
                    triggerClass="sr-only"
                    onpick={moveTo}
                />
            </span>
        {/if}
        <span class="block min-w-0 flex-1">
            <span class="flex items-baseline gap-3">
                <span
                    class={`line-clamp-2 min-w-0 text-[16px] leading-[22px] sm:line-clamp-1 sm:text-[15px] sm:leading-[24px] ${inDone ? 'text-fg-muted' : 'text-fg'}`}
                    >{todo.title}</span
                >
                <span
                    class="hidden min-w-0 flex-1 truncate text-[13px] text-fg-muted sm:block"
                    >{WHERE}</span
                >
            </span>
            <span
                class="mt-[3px] block truncate text-[13.5px] leading-[19px] text-fg-muted sm:hidden"
                >{#if when}{when}{DOT}{/if}{WHERE}</span
            >
        </span>

        <span
            class="hidden h-[24px] shrink-0 items-center text-[13px] text-fg-muted sm:flex"
            >{when}</span
        >
    </div>

    <!-- The menu's place is kept on every row, so the dates line up. -->
    <span
        bind:this={menuWrap}
        class={`shrink-0 sm:-my-[4px] sm:w-[32px] ${inDone ? 'max-sm:hidden' : ''}`}
    >
        {#if !inDone}
            <Popover
                bind:open={menuOpen}
                align="right"
                triggerLabel={`Options for ${todo.title}`}
                triggerClass={`btn-icon size-[44px] sm:size-[32px] ${menuOpen ? 'bg-hover text-fg' : 'pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:group-focus-within:opacity-100'}`}
                panelClass="w-64"
            >
                {#snippet trigger()}
                    <Ellipsis class="size-4" />
                {/snippet}

                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={startMove}
                >
                    <FolderInput class="size-4 shrink-0" />
                    Move to a project
                </button>

                <div class="my-1 border-t border-line-soft"></div>

                <form
                    class="px-2 pt-1 pb-1.5"
                    onsubmit={(e) => {
                        e.preventDefault();
                        setDay(day);
                    }}
                >
                    <label class="label" for={`todo-day-${todo.id}`}
                        >Change date</label
                    >
                    <div class="mt-1 flex items-center gap-1.5">
                        <input
                            id={`todo-day-${todo.id}`}
                            type="date"
                            class="input min-w-0 flex-1"
                            required
                            bind:value={day}
                            onkeydown={keepKeys}
                        />
                        <button type="submit" class="btn">Set</button>
                    </div>
                </form>
                {#if todo.due_on}
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item"
                        onclick={() => setDay(null)}
                    >
                        <CalendarX class="size-4 shrink-0" />
                        Clear the date
                    </button>
                {/if}

                <div class="my-1 border-t border-line-soft"></div>

                <button
                    type="button"
                    data-popover-item
                    class="menu-item hover:text-danger"
                    onclick={remove}
                >
                    <Trash2 class="size-4 shrink-0" />
                    Delete
                </button>
            </Popover>
        {/if}
    </span>
</div>
