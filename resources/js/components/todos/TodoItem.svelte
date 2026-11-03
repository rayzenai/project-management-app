<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { CalendarX, Ellipsis, FolderInput, Trash2 } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { dueLabel } from '../../lib/dates';
    import type { Id, PersonalTodo, SharedProps } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import ProjectPicker from '../ProjectPicker.svelte';
    import TodoCircle from './TodoCircle.svelte';

    /**
     * One row of the top bar's to-do list. The circle completes it (half
     * filled while you work on it), the title edits in place (Enter saves,
     * Escape cancels), the date sits under it (red once past), and on the
     * right, always on touch and on hover with a mouse: Start or Stop, Delete
     * and a menu (Change date, Move to a project, Delete). A done one only
     * opens again or deletes. The list does the saving.
     */
    let {
        todo,
        today,
        done = false,
        ontoggle,
        ondoing,
        onrename,
        ondate,
        ondelete,
        onmove,
    }: {
        todo: PersonalTodo;
        /** The office's today, Y-m-d. */
        today: string;
        /** In Done. */
        done?: boolean;
        ontoggle: () => void;
        ondoing: (next: boolean) => void;
        onrename: (title: string) => void;
        ondate: (day: string | null) => void;
        ondelete: () => void;
        onmove: (projectId: Id) => void;
    } = $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const context = $derived(shared.quickAddContext ?? null);

    const doing = $derived(todo.started_at !== null && todo.done_at === null);
    const date = $derived(
        !done && todo.due_on ? dueLabel(todo.due_on, today) : '',
    );
    const past = $derived(!done && todo.due_on !== null && todo.due_on < today);

    let editing = $state(false);
    let draft = $state('');
    let field = $state<HTMLInputElement | null>(null);
    let titleButton = $state<HTMLButtonElement | null>(null);

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

    async function edit(): Promise<void> {
        draft = todo.title;
        editing = true;
        await tick();
        field?.select();
    }

    /** Enter or leaving the field saves; an empty or unchanged title doesn't. */
    function save(): void {
        if (!editing) {
            return;
        }

        editing = false;
        const next = draft.trim();

        if (next !== '' && next !== todo.title) {
            onrename(next);
        }
    }

    async function cancel(): Promise<void> {
        editing = false;
        await tick();
        titleButton?.focus();
    }

    function onFieldKey(e: KeyboardEvent): void {
        // Nepali typing: Enter picks the IME's word, not the title.
        if (e.isComposing || e.keyCode === 229) {
            return;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            save();
            void tick().then(() => titleButton?.focus());
        } else if (e.key === 'Escape') {
            // Cancels the edit only; the list stays open.
            e.preventDefault();
            e.stopPropagation();
            void cancel();
        }
    }

    function setDay(next: string | null): void {
        menuOpen = false;
        ondate(next);
    }

    function startMove(): void {
        menuOpen = false;
        moving = true;
    }

    function moveTo(projectId: Id | 'general' | 'personal'): void {
        if (typeof projectId === 'number') {
            onmove(projectId);
        }
    }

    /** Arrow keys belong to the date field, not the menu's item focus. */
    function keepKeys(event: KeyboardEvent): void {
        if (event.key !== 'Escape') {
            event.stopPropagation();
        }
    }

    const reveal = $derived(
        menuOpen
            ? ''
            : 'pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:group-focus-within:opacity-100',
    );
</script>

<div
    class="group flex min-h-[48px] items-start gap-3 rounded-md px-2 py-3 transition hover:bg-hover sm:min-h-[44px]"
>
    <span class="flex h-[24px] shrink-0 items-center sm:h-[20px]">
        <TodoCircle title={todo.title} {done} {doing} onclick={ontoggle} />
    </span>

    <div class="relative min-w-0 flex-1">
        {#if moving && context}
            <!-- Opened from the menu, under the title: its trigger is never
                 seen and takes no room. -->
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

        {#if editing}
            <input
                bind:this={field}
                bind:value={draft}
                class="input -my-[8px]"
                maxlength="255"
                aria-label="Title"
                onkeydown={onFieldKey}
                onblur={save}
            />
        {:else if done}
            <span
                class="block text-[15px] leading-[24px] break-words text-fg-muted sm:text-[14px] sm:leading-[20px]"
                >{todo.title}</span
            >
        {:else}
            <button
                bind:this={titleButton}
                type="button"
                class="block w-full rounded-sm text-left text-[15px] leading-[24px] break-words text-fg sm:text-[14px] sm:leading-[20px]"
                aria-label={`Edit "${todo.title}"`}
                onclick={edit}>{todo.title}</button
            >
        {/if}

        {#if date && !editing}
            <span
                class={`mt-0.5 block text-[13px] leading-[18px] ${past ? 'text-danger' : 'text-fg-muted'}`}
                >{date}</span
            >
        {/if}
    </div>

    <div class={`-my-[10px] flex shrink-0 items-center sm:-my-[4px] ${reveal}`}>
        {#if !done}
            <button
                type="button"
                class="btn-ghost h-[44px] px-2.5 text-[13.5px] sm:h-[28px] sm:px-2 sm:text-[13px]"
                aria-label={doing
                    ? `Stop working on "${todo.title}"`
                    : `Start "${todo.title}"`}
                onclick={() => ondoing(!doing)}
            >
                {doing ? 'Stop' : 'Start'}
            </button>
        {/if}
        <button
            type="button"
            class="btn-icon size-[44px] hover:text-danger sm:size-[28px]"
            aria-label={`Delete "${todo.title}"`}
            title="Delete"
            onclick={ondelete}
        >
            <Trash2 class="size-4" />
        </button>
        {#if !done}
            <span bind:this={menuWrap} class="inline-flex">
                <Popover
                    bind:open={menuOpen}
                    align="right"
                    triggerLabel={`Options for ${todo.title}`}
                    triggerClass={`btn-icon size-[44px] sm:size-[28px] ${menuOpen ? 'bg-hover text-fg' : ''}`}
                    panelClass="w-64"
                >
                    {#snippet trigger()}
                        <Ellipsis class="size-4" />
                    {/snippet}

                    <form
                        class="px-2 pt-1 pb-1.5"
                        onsubmit={(e) => {
                            e.preventDefault();
                            setDay(day);
                        }}
                    >
                        <label class="label" for={`todo-panel-day-${todo.id}`}
                            >Change date</label
                        >
                        <div class="mt-1 flex items-center gap-1.5">
                            <input
                                id={`todo-panel-day-${todo.id}`}
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
                        class="menu-item"
                        onclick={startMove}
                    >
                        <FolderInput class="size-4 shrink-0" />
                        Move to a project
                    </button>
                    <button
                        type="button"
                        data-popover-item
                        class="menu-item hover:text-danger"
                        onclick={() => {
                            menuOpen = false;
                            ondelete();
                        }}
                    >
                        <Trash2 class="size-4 shrink-0" />
                        Delete
                    </button>
                </Popover>
            </span>
        {/if}
    </div>
</div>
