<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ChevronDown, ChevronRight, ListTodo } from '@lucide/svelte';
    import { tick, untrack } from 'svelte';
    import { MediaQuery } from 'svelte/reactivity';
    import { msg, number, t } from '../../lib/i18n';
    import { portal } from '../../lib/portal';
    import { QUIET, toast } from '../../lib/toast.svelte';
    import type { Id, PersonalTodo, PersonalTodoLists } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import Spinner from '../Spinner.svelte';
    import TodoItem from './TodoItem.svelte';

    /**
     * The top bar's to-do list: your private to-dos, like a small to-do
     * app. The button carries the open count (`todoCount`); it opens a
     * popover on a desktop and a bottom sheet on a phone (T toggles it).
     * Inside: an add field (quick add with `personal`, so date words work),
     * then Doing, To do and a folded Done (the last 7 days). The list is
     * read with a fetch (GET /workspace/todos) each time it opens and after
     * each change; changes go one at a time (an Inertia visit would cancel
     * the one before) and reload My work's `groups` and the count.
     */
    let {
        open = $bindable(false),
        count = 0,
    }: {
        open?: boolean;
        /** Your open to-dos (To do and Doing). */
        count?: number;
    } = $props();

    // Tailwind's md: below it the list rises from the bottom as a sheet.
    const wide = new MediaQuery('(min-width: 48rem)', true);

    /** What a change reloads: My work's list, the count, a refusal's toast. */
    const RELOAD = ['groups', 'todoCount', 'flash'];

    let lists = $state<PersonalTodoLists | null>(null);
    let loadFailed = $state(false);
    let doneOpen = $state(false);
    let draft = $state('');
    let addError = $state<string | null>(null);

    let sheetPanel = $state<HTMLElement | null>(null);
    let sheetTrigger = $state<HTMLButtonElement | null>(null);
    let triggerWrap = $state<HTMLElement | null>(null);

    /** Changes sent or waiting; the list is read again once none are left. */
    let pending = 0;
    let chain: Promise<void> = Promise.resolve();
    let seq = 0;

    const empty = $derived(
        lists !== null && lists.doing.length + lists.open.length === 0,
    );

    async function load(): Promise<void> {
        const mine = ++seq;

        try {
            const response = await fetch('/workspace/todos', {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as PersonalTodoLists;

            // A change made meanwhile is shown already; its own read follows.
            if (mine === seq && pending === 0) {
                lists = json;
                loadFailed = false;
            }
        } catch {
            if (mine === seq && lists === null) {
                loadFailed = true;
            }
        }
    }

    $effect(() => {
        if (open) {
            untrack(() => void load());
        }
    });

    /**
     * One change to the server, after the ones before it: two Inertia
     * visits at once would cancel the first.
     */
    function send(visit: (finish: () => void) => void): void {
        pending++;
        chain = chain
            .then(() => new Promise<void>((resolve) => visit(resolve)))
            .then(() => {
                pending--;

                if (pending === 0) {
                    void load();
                }
            });
    }

    function quietly(finish: () => void, onSuccess?: () => void) {
        return {
            headers: QUIET,
            preserveScroll: true,
            preserveState: true,
            only: RELOAD,
            onSuccess,
            onFinish: finish,
        };
    }

    function patch(
        id: Id,
        data: Record<string, unknown>,
        onSuccess?: () => void,
    ): void {
        send((finish) =>
            router.patch(
                `/workspace/todos/${id}`,
                data as Record<string, string | boolean | null>,
                quietly(finish, onSuccess),
            ),
        );
    }

    // ---- The list as shown, changed at once; the next read corrects it ----

    function take(id: Id): PersonalTodo | null {
        if (!lists) {
            return null;
        }

        const found = [...lists.doing, ...lists.open, ...lists.done].find(
            (t) => t.id === id,
        );

        lists.doing = lists.doing.filter((t) => t.id !== id);
        lists.open = lists.open.filter((t) => t.id !== id);
        lists.done = lists.done.filter((t) => t.id !== id);

        return found ?? null;
    }

    /** Puts a to-do where the server would: Done first, Doing last, To do by date. */
    function place(todo: PersonalTodo): void {
        if (!lists) {
            return;
        }

        if (todo.done_at !== null) {
            lists.done = [todo, ...lists.done];
        } else if (todo.started_at !== null) {
            lists.doing = [...lists.doing, todo];
        } else {
            const key = (t: PersonalTodo) => t.due_on ?? '9999-12-31';
            const at = lists.open.findIndex((t) => key(t) > key(todo));

            lists.open =
                at === -1
                    ? [...lists.open, todo]
                    : [
                          ...lists.open.slice(0, at),
                          todo,
                          ...lists.open.slice(at),
                      ];
        }
    }

    function change(id: Id, fields: Partial<PersonalTodo>): void {
        const todo = take(id);

        if (todo) {
            place({ ...todo, ...fields });
        }
    }

    const now = () => new Date().toISOString();

    // ---- Actions ----

    function toggle(todo: PersonalTodo): void {
        if (todo.done_at !== null) {
            change(todo.id, { done_at: null });
            patch(todo.id, { done: false });

            return;
        }

        change(todo.id, { done_at: now() });
        patch(todo.id, { done: true }, () =>
            toast.show(t('Completed ":title"', { title: todo.title }), {
                undo: {
                    run: () => {
                        change(todo.id, { done_at: null });
                        patch(todo.id, { done: false });
                    },
                },
            }),
        );
    }

    function setDoing(todo: PersonalTodo, next: boolean): void {
        change(todo.id, { started_at: next ? now() : null });
        patch(todo.id, { doing: next });
    }

    function rename(todo: PersonalTodo, title: string): void {
        change(todo.id, { title });
        patch(todo.id, { title });
    }

    function setDate(todo: PersonalTodo, day: string | null): void {
        change(todo.id, { due_on: day });
        patch(todo.id, { due_on: day });
    }

    function remove(todo: PersonalTodo): void {
        take(todo.id);
        send((finish) =>
            router.delete(
                `/workspace/todos/${todo.id}`,
                quietly(finish, () =>
                    toast.show(t('To-do deleted.'), {
                        undo: { run: () => restore(todo) },
                    }),
                ),
            ),
        );
    }

    function restore(todo: PersonalTodo): void {
        take(todo.id);
        place(todo);
        send((finish) =>
            router.post(
                `/workspace/todos/${todo.id}/restore`,
                {},
                quietly(finish),
            ),
        );
    }

    /** The server lands on the new task, wherever it went. */
    function move(todo: PersonalTodo, projectId: Id): void {
        open = false;
        router.post(`/workspace/todos/${todo.id}/move`, {
            project_id: projectId,
        });
    }

    /**
     * Quick add's own path, so "भोलि" or "fri" sets the date. The field
     * clears at once; a refused one comes back into it.
     */
    function add(e: SubmitEvent): void {
        e.preventDefault();
        const title = draft.trim();

        if (title === '') {
            return;
        }

        draft = '';
        addError = null;
        send((finish) =>
            router.post(
                '/workspace/quick-add',
                { title, personal: true },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: RELOAD,
                    onError: (errors) => {
                        addError =
                            Object.values(errors)[0] ??
                            t('That was not added.');
                        draft = draft.trim() ? `${title} ${draft}` : title;
                    },
                    onFinish: finish,
                },
            ),
        );
    }

    function onAddKey(e: KeyboardEvent): void {
        // Nepali typing: Enter picks the IME's word, not the to-do.
        if (e.key === 'Enter' && (e.isComposing || e.keyCode === 229)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    // Closed with the focus inside (T, a row gone): back to the button.
    let wasOpen = false;
    $effect(() => {
        if (open) {
            wasOpen = true;

            return;
        }

        if (wasOpen) {
            wasOpen = false;
            void tick().then(() => {
                if (
                    document.activeElement === null ||
                    document.activeElement === document.body
                ) {
                    (wide.current
                        ? triggerWrap?.querySelector('button')
                        : sheetTrigger
                    )?.focus();
                }
            });
        }
    });

    /** T on the button itself (after Escape gave it the focus) toggles too. */
    function onTriggerKey(e: KeyboardEvent): void {
        if (
            (e.key === 't' || e.key === 'T') &&
            !e.metaKey &&
            !e.ctrlKey &&
            !e.altKey
        ) {
            e.preventDefault();
            e.stopPropagation();
            open = !open;
        }
    }

    // ---- The phone's sheet ----

    const FOCUSABLE =
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

    // The sheet opens with the focus on itself: no keyboard over the list.
    $effect(() => {
        if (open && !wide.current && sheetPanel) {
            sheetPanel.focus();
        }
    });

    async function closeSheet(): Promise<void> {
        open = false;
        await tick();
        sheetTrigger?.focus();
    }

    function onWindowKey(e: KeyboardEvent): void {
        if (!open || wide.current) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            void closeSheet();

            return;
        }

        // Tab goes round the sheet and never behind it; a menu opened from
        // it keeps its own keys.
        if (e.key === 'Tab' && sheetPanel) {
            const active = document.activeElement as HTMLElement | null;

            if (
                active &&
                active !== document.body &&
                !sheetPanel.contains(active)
            ) {
                return;
            }

            const items = Array.from(
                sheetPanel.querySelectorAll<HTMLElement>(FOCUSABLE),
            );
            const at = items.indexOf(active as HTMLElement);

            if (items.length === 0) {
                return;
            }

            if (at === -1 || (e.shiftKey && at === 0)) {
                e.preventDefault();
                items[e.shiftKey ? items.length - 1 : 0].focus();
            } else if (!e.shiftKey && at === items.length - 1) {
                e.preventDefault();
                items[0].focus();
            }
        }
    }

    const label = $derived(
        count > 0 ? t('To-dos, :count', { count }) : t('To-dos'),
    );
    const triggerClass = $derived(
        `btn-ghost shrink-0 ${open ? 'bg-accent-soft text-accent hover:bg-accent-soft hover:text-accent' : ''}`,
    );
</script>

{#snippet face()}
    <ListTodo class="h-4 w-4" />
    {#if count > 0}
        <span class="tabular-nums">{number(count)}</span>
    {/if}
{/snippet}

{#snippet section(title: string, todos: PersonalTodo[], today: string)}
    <h3 class="section-title px-2 pt-2 pb-1">
        {t(title)}
        <span class="section-count">{number(todos.length)}</span>
    </h3>
    {#each todos as todo (todo.id)}
        {@render item(todo, today, false)}
    {/each}
{/snippet}

{#snippet item(todo: PersonalTodo, today: string, done: boolean)}
    <TodoItem
        {todo}
        {today}
        {done}
        ontoggle={() => toggle(todo)}
        ondoing={(next) => setDoing(todo, next)}
        onrename={(title) => rename(todo, title)}
        ondate={(day) => setDate(todo, day)}
        ondelete={() => remove(todo)}
        onmove={(projectId) => move(todo, projectId)}
    />
{/snippet}

{#snippet body(sheet: boolean)}
    <div
        class={`flex flex-col ${sheet ? 'max-h-[min(640px,85vh)] px-3 pt-3 pb-2' : 'max-h-[min(560px,80vh)] px-1 pt-1.5 pb-1'}`}
    >
        <div class="flex h-[32px] shrink-0 items-center gap-2 px-2">
            <h2
                id={sheet ? 'todos-sheet-title' : 'todos-title'}
                class="flex-1 text-[15px] font-semibold text-fg"
            >
                {t('To-dos')}
            </h2>
            {#if !sheet}
                <kbd class="kbd" title={t('Press T to open or close')}>T</kbd>
            {/if}
        </div>

        <form class="shrink-0 px-2 pt-1.5 pb-2" onsubmit={add}>
            <input
                class="input"
                placeholder={t('Add a to-do')}
                aria-label={t('Add a to-do')}
                maxlength="255"
                enterkeyhint="enter"
                bind:value={draft}
                oninput={() => (addError = null)}
                onkeydown={onAddKey}
            />
            {#if addError}
                <p class="mt-1.5 text-[13px] text-danger" role="alert">
                    {addError}
                </p>
            {/if}
        </form>

        <div class="min-h-0 flex-1 overflow-y-auto pb-1">
            {#if lists === null}
                {#if loadFailed}
                    <p class="px-2 py-3 text-[14px] text-fg-muted">
                        {t("Couldn't load your to-dos.")}
                        <button
                            type="button"
                            class="text-accent hover:underline"
                            onclick={() => {
                                loadFailed = false;
                                void load();
                            }}>{t('Try again')}</button
                        >
                    </p>
                {:else}
                    <div class="flex justify-center py-6">
                        <Spinner label={t('Loading your to-dos')} />
                    </div>
                {/if}
            {:else}
                {#if empty}
                    <p class="px-2 py-3 text-[14px] text-fg-muted">
                        {t('Nothing on your list.')}
                    </p>
                {/if}
                {#if lists.doing.length > 0}
                    {@render section(msg('Doing'), lists.doing, lists.today)}
                {/if}
                {#if lists.open.length > 0}
                    {@render section(msg('To do'), lists.open, lists.today)}
                {/if}
                {#if lists.done.length > 0}
                    <h3 class="px-1 pt-1">
                        <button
                            type="button"
                            class={`section-title w-full rounded-md px-1 text-left hover:bg-hover ${sheet ? 'min-h-[44px]' : 'min-h-[32px]'} items-center`}
                            aria-expanded={doneOpen}
                            onclick={() => (doneOpen = !doneOpen)}
                        >
                            {t('Done')}
                            <span class="section-count"
                                >{number(lists.done.length)}</span
                            >
                            {#if doneOpen}
                                <ChevronDown
                                    class="size-4 shrink-0 self-center text-fg-faint"
                                />
                            {:else}
                                <ChevronRight
                                    class="size-4 shrink-0 self-center text-fg-faint"
                                />
                            {/if}
                        </button>
                    </h3>
                    {#if doneOpen}
                        {#each lists.done as todo (todo.id)}
                            {@render item(todo, lists.today, true)}
                        {/each}
                    {/if}
                {/if}
            {/if}
        </div>
    </div>
{/snippet}

{#if wide.current}
    <!-- The popover's button keeps its keys from the page: T is let in here. -->
    <span
        bind:this={triggerWrap}
        class="contents"
        onkeydowncapture={onTriggerKey}
    >
        <Popover
            bind:open
            role="dialog"
            align="right"
            label={t('To-dos')}
            triggerLabel={label}
            {triggerClass}
            panelClass="w-[380px] max-w-[calc(100vw-16px)]"
        >
            {#snippet trigger()}
                {@render face()}
            {/snippet}
            {@render body(false)}
        </Popover>
    </span>
{:else}
    <button
        bind:this={sheetTrigger}
        type="button"
        class={triggerClass}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={label}
        onclick={() => (open = !open)}
    >
        {@render face()}
    </button>
    {#if open}
        <div use:portal class="fixed inset-0 z-50">
            <button
                type="button"
                aria-label={t('Close the to-dos')}
                tabindex="-1"
                class="absolute inset-0 cursor-default bg-black/40"
                onclick={closeSheet}
            ></button>
            <div
                bind:this={sheetPanel}
                role="dialog"
                aria-modal="true"
                aria-labelledby="todos-sheet-title"
                tabindex="-1"
                class="popover absolute inset-x-0 bottom-0 rounded-b-none pb-[env(safe-area-inset-bottom)] focus:outline-none"
            >
                {@render body(true)}
            </div>
        </div>
    {/if}
{/if}

<svelte:window onkeydown={onWindowKey} />
