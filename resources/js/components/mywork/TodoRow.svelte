<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        CalendarX,
        Ellipsis,
        FolderInput,
        Play,
        Square,
        Trash2,
    } from '@lucide/svelte';
    import { dueLabel, shiftDay, shortDay } from '../../lib/dates';
    import { formatDay, isNepali, t } from '../../lib/i18n';
    import { QUIET, toast } from '../../lib/toast.svelte';
    import type {
        Id,
        MyWorkGroup,
        MyWorkTodoRow,
        SharedProps,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import ProjectPicker from '../ProjectPicker.svelte';
    import TodoCircle from '../todos/TodoCircle.svelte';

    /**
     * One of your private to-dos on My work ("Just for me"): the circle
     * ticks it done (one toast, with Undo) and is half filled while you
     * work on it, the title is plain text, "Only you" stands where a task's
     * project would, then its date. Its menu starts or stops it, moves it
     * into a project as a task, changes its date or deletes it (with Undo;
     * in Done, it only deletes it). Nobody else ever sees it.
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

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const context = $derived(shared.quickAddContext ?? null);

    const todo = $derived(row.todo);
    const url = $derived(`/workspace/todos/${row.todo.id}`);
    const inDone = $derived(group.key === 'done');

    /** dueLabel in the reader's language (Nepali names and digits). */
    function dayText(iso: string): string {
        if (!isNepali()) {
            return dueLabel(iso, today);
        }

        if (iso === today) {
            return t('Today');
        }

        if (iso === shiftDay(today, 1)) {
            return t('Tomorrow');
        }

        return iso > today && iso <= shiftDay(today, 6)
            ? formatDay(iso, { weekday: 'short' })
            : shortText(iso);
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

    /** The day beside the row; left out when the group already says it. */
    const when = $derived.by(() => {
        if (inDone) {
            return todo.done_at ? dayText(todo.done_at.slice(0, 10)) : '';
        }

        // The server's group names are English, as dueLabel's are.
        return row.due && dueLabel(row.due, today) !== group.label
            ? dayText(row.due)
            : '';
    });

    const quietly = {
        headers: QUIET,
        preserveScroll: true,
        preserveState: true,
    };

    let optimistic = $state<boolean | null>(null);
    const checked = $derived(optimistic ?? todo.done_at !== null);
    const doing = $derived(todo.started_at !== null);
    /** A second click while the first is on its way would undo it. */
    let toggling = false;

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
        if (toggling) {
            return;
        }

        const next = !checked;
        const title = todo.title;
        const target = url;

        toggling = true;
        optimistic = next;
        router.patch(
            target,
            { done: next },
            {
                ...quietly,
                onSuccess: () =>
                    toast.show(
                        next
                            ? t('Completed ":title"', { title })
                            : t('Reopened ":title"', { title }),
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
                onFinish: () => {
                    toggling = false;
                    optimistic = null;
                },
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
                        next
                            ? t('Date: :day', { day: shortText(next) })
                            : t('Date cleared'),
                    ),
            },
        );
    }

    /** Start working on it, or stop: the circle says which. */
    function setDoing(next: boolean): void {
        menuOpen = false;
        router.patch(url, { doing: next }, quietly);
    }

    function remove(): void {
        const target = url;

        menuOpen = false;
        router.delete(target, {
            ...quietly,
            onSuccess: () =>
                toast.show(t('To-do deleted.'), {
                    undo: {
                        run: () =>
                            router.post(`${target}/restore`, {}, quietly),
                    },
                }),
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
    class="group flex min-h-[44px] items-start gap-[14px] border-b border-line-soft py-3 pr-2 pl-5 transition hover:bg-hover sm:-mx-[10px] sm:gap-3 sm:rounded-lg sm:border-0 sm:px-[10px] sm:py-[10px]"
>
    <span
        class="flex size-[24px] shrink-0 items-center justify-center sm:mt-[3px] sm:size-[18px]"
    >
        <TodoCircle
            title={todo.title}
            done={checked}
            {doing}
            onclick={toggle}
        />
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
                    >{t('Only you')}</span
                >
            </span>
            <span
                class="mt-[3px] block truncate text-[13.5px] leading-[19px] text-fg-muted sm:hidden"
                >{#if when}{when}{DOT}{/if}{t('Only you')}</span
            >
        </span>

        <span
            class="hidden h-[24px] shrink-0 items-center text-[13px] text-fg-muted sm:flex"
            >{when}</span
        >
    </div>

    <!-- The menu's place is kept on every row, so the dates line up. A
         done to-do's menu only deletes it. -->
    <span bind:this={menuWrap} class="shrink-0 sm:-my-[4px] sm:w-[32px]">
        <Popover
            bind:open={menuOpen}
            align="right"
            triggerLabel={t('Options for :title', { title: todo.title })}
            triggerClass={`btn-icon size-[44px] sm:size-[32px] ${menuOpen ? 'bg-hover text-fg' : 'pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:group-focus-within:opacity-100'}`}
            panelClass="w-64"
        >
            {#snippet trigger()}
                <Ellipsis class="size-4" />
            {/snippet}

            {#if !inDone}
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={() => setDoing(!doing)}
                >
                    {#if doing}
                        <Square class="size-4 shrink-0" />
                        {t('Stop')}
                    {:else}
                        <Play class="size-4 shrink-0" />
                        {t('Start')}
                    {/if}
                </button>
                <button
                    type="button"
                    data-popover-item
                    class="menu-item"
                    onclick={startMove}
                >
                    <FolderInput class="size-4 shrink-0" />
                    {t('Move to a project')}
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
                        >{t('Change date')}</label
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
                        <button type="submit" class="btn">{t('Set')}</button>
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
                        {t('Clear the date')}
                    </button>
                {/if}

                <div class="my-1 border-t border-line-soft"></div>
            {/if}

            <button
                type="button"
                data-popover-item
                class="menu-item hover:text-danger"
                onclick={remove}
            >
                <Trash2 class="size-4 shrink-0" />
                {t('Delete')}
            </button>
        </Popover>
    </span>
</div>
