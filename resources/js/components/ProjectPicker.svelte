<script lang="ts">
    import { Check, ChevronDown, Folder, Search } from '@lucide/svelte';
    import { tick, untrack } from 'svelte';
    import { MediaQuery } from 'svelte/reactivity';
    import { forgivingFilter } from '../lib/forgivingFilter';
    import { projectLabel } from '../lib/projectLabel';
    import type { Id, OrganizationOption, QuickAddProject } from '../lib/types';
    import Popover from './Popover.svelte';

    /**
     * Which project a new task goes in: the add field's Project chip and
     * the New task dialog's Project field. It opens on "Your projects" (the
     * teams you are on, else your organization's projects); when there is
     * more to reach, a dropdown lists each organization's projects, and the
     * search looks through every project you may add to. A popover on a
     * desktop, a bottom sheet on a phone.
     */
    let {
        projects,
        organizations = [],
        currentMemberId,
        value = $bindable(null),
        homeOrganizationId = null,
        general = false,
        disabled = false,
        field = false,
        triggerClass,
        placeholder = 'Project',
        onpick,
    }: {
        projects: QuickAddProject[];
        /** The organizations of `projects`, by name. */
        organizations?: OrganizationOption[];
        currentMemberId: Id | null;
        /** A project's id, or "general" for the organization's first project. */
        value?: Id | 'general' | null;
        /** The viewer's own organization: listed first. */
        homeOrganizationId?: Id | null;
        /** Offer "General (new)": an organizational admin's first project. */
        general?: boolean;
        disabled?: boolean;
        /** A form field (the dialog) rather than a chip (the add field). */
        field?: boolean;
        triggerClass: string;
        /** The trigger's words while nothing is chosen. */
        placeholder?: string;
        /** After a pick, e.g. to put the caret back in the add field. */
        onpick?: (value: Id | 'general') => void;
    } = $props();

    const uid = $props.id();
    const YOURS = 'yours';
    type View = typeof YOURS | Id;
    type Row = { value: Id | 'general'; title: string; detail: string | null };

    const GENERAL_ROW: Row = {
        value: 'general',
        title: 'General (new)',
        detail: "Your organization's first project",
    };

    // Tailwind's md: below it the picker rises from the bottom as a sheet.
    const wide = new MediaQuery('(min-width: 48rem)', true);

    let open = $state(false);
    let query = $state('');
    let view = $state<View>(YOURS);
    let active = $state(0);
    let orgOpen = $state(false);
    let orgPlacement = $state('');
    let sheetHeight = $state(0);
    let root = $state<HTMLElement | null>(null);
    let searchEl = $state<HTMLInputElement | null>(null);
    let sheetEl = $state<HTMLElement | null>(null);
    let orgTrigger = $state<HTMLButtonElement | null>(null);
    let orgMenu = $state<HTMLElement | null>(null);

    const organizationName = $derived(
        new Map(organizations.map((o) => [o.id, o.name])),
    );

    /** The teams you are on; with none, your organization's projects. */
    const yours = $derived.by(() => {
        const onTeam =
            currentMemberId === null
                ? []
                : projects.filter((p) =>
                      p.member_ids?.includes(currentMemberId),
                  );

        return onTeam.length > 0
            ? onTeam
            : projects.filter((p) => p.organization_id === homeOrganizationId);
    });

    /** "Your projects", then your organization, then the rest by name. */
    const views = $derived<{ id: View; name: string }[]>([
        { id: YOURS, name: 'Your projects' },
        ...organizations.filter((o) => o.id === homeOrganizationId),
        ...organizations.filter((o) => o.id !== homeOrganizationId),
    ]);

    /** A dropdown only when it reaches something "Your projects" doesn't. */
    const hasViews = $derived(
        organizations.length > 1 || yours.length < projects.length,
    );

    const chosen = $derived(
        typeof value === 'number'
            ? (projects.find((p) => p.id === value) ?? null)
            : null,
    );
    const label = $derived(
        chosen
            ? projectLabel(chosen)
            : value === 'general'
              ? GENERAL_ROW.title
              : placeholder,
    );

    /**
     * Where the list opens: "Your projects", or the organization of a
     * chosen project that isn't one of yours, so going back to that
     * organization's list is one step.
     */
    const startView = $derived<View>(
        hasViews &&
            chosen?.organization_id !== undefined &&
            !yours.some((p) => p.id === chosen.id)
            ? chosen.organization_id
            : YOURS,
    );

    const searching = $derived(query.trim() !== '');

    function detailOf(project: QuickAddProject): string | null {
        // Another organization's project says whose it is, where the list
        // mixes organizations.
        const organization =
            (searching || view === YOURS) &&
            organizations.length > 1 &&
            project.organization_id !== homeOrganizationId
                ? organizationName.get(project.organization_id ?? -1)
                : null;

        return (
            [project.program, organization].filter(Boolean).join(' · ') || null
        );
    }

    const rows = $derived.by((): Row[] => {
        const listed = searching
            ? forgivingFilter(
                  projects,
                  query,
                  (p) => `${p.title} ${p.program ?? ''}`,
              )
            : view === YOURS
              ? yours
              : projects.filter((p) => p.organization_id === view);
        const offersGeneral =
            general &&
            (searching
                ? forgivingFilter([GENERAL_ROW], query, (r) => r.title).length >
                  0
                : view === YOURS || view === homeOrganizationId);

        return [
            ...(offersGeneral ? [GENERAL_ROW] : []),
            ...listed.map((p) => ({
                value: p.id,
                title: p.title,
                detail: detailOf(p),
            })),
        ];
    });

    const viewName = $derived(
        views.find((v) => v.id === view)?.name ?? 'Your projects',
    );

    // Closed, the picker gets ready to open where the chosen project is.
    $effect(() => {
        const start = startView;

        if (!open) {
            untrack(() => {
                view = start;
                query = '';
                orgOpen = false;
                sheetHeight = 0;
            });
        }
    });

    // The keys start on the chosen project, or on the best match.
    $effect(() => {
        const list = rows;
        const isOpen = open;

        untrack(() => {
            active = searching
                ? 0
                : Math.max(
                      0,
                      list.findIndex((r) => r.value === value),
                  );

            if (isOpen) {
                tick().then(() => reveal());
            }
        });
    });

    function rowId(index: number): string {
        return `${uid}-row-${index}`;
    }

    function reveal(): void {
        document
            .getElementById(rowId(active))
            ?.scrollIntoView({ block: 'nearest' });
    }

    function triggerButton(): HTMLElement | null {
        return root?.querySelector('button') ?? null;
    }

    function pick(next: Id | 'general'): void {
        value = next;
        open = false;
        triggerButton()?.focus();
        onpick?.(next);
    }

    function close(): void {
        open = false;
        triggerButton()?.focus();
    }

    function onSearchKey(e: KeyboardEvent): void {
        if (e.isComposing || e.keyCode === 229) {
            return;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();

            if (rows.length > 0) {
                active =
                    (active + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) %
                    rows.length;
                reveal();
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();

            if (rows[active]) {
                pick(rows[active].value);
            }
        }
    }

    /** Typing anywhere in the picker goes to the search (Space still presses). */
    function onPanelKey(e: KeyboardEvent): void {
        if (
            e.key.length === 1 &&
            e.key !== ' ' &&
            !e.ctrlKey &&
            !e.metaKey &&
            !e.altKey &&
            e.target !== searchEl &&
            searchEl
        ) {
            e.stopPropagation();
            orgOpen = false;
            searchEl.focus();
        }
    }

    /**
     * The organization menu, under its button and over the list; above
     * the button when there is more room there (a short sheet on a phone).
     */
    function placeOrg(): void {
        const anchor = orgTrigger?.getBoundingClientRect();

        if (!anchor) {
            return;
        }

        const width = Math.min(260, window.innerWidth - 16);
        const height = orgMenu?.scrollHeight ?? 0;
        const below = window.innerHeight - anchor.bottom - 12;
        const above = anchor.top - 12;
        const flip = height > below && above > below;
        const left = Math.max(
            8,
            Math.min(anchor.left, window.innerWidth - width - 8),
        );
        const top = flip
            ? Math.max(8, anchor.top - 4 - Math.min(height, above))
            : anchor.bottom + 4;

        orgPlacement =
            `left:${Math.round(left)}px;top:${Math.round(top)}px;` +
            `width:${Math.round(width)}px;` +
            `max-height:${Math.round(Math.max(flip ? above : below, 120))}px;`;
    }

    function orgItems(): HTMLElement[] {
        return Array.from(
            orgMenu?.querySelectorAll<HTMLElement>('[role="option"]') ?? [],
        );
    }

    async function openOrg(): Promise<void> {
        orgOpen = true;
        await tick();
        placeOrg();
        orgMenu?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus();
    }

    function chooseView(next: View): void {
        view = next;
        query = '';
        orgOpen = false;
        // On a phone the search would raise the keyboard over the list.
        (wide.current ? searchEl : orgTrigger)?.focus();
    }

    function onOrgKey(e: KeyboardEvent): void {
        if (e.key === 'Escape' || e.key === 'Tab') {
            e.preventDefault();
            e.stopPropagation();
            orgOpen = false;
            orgTrigger?.focus();

            return;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            e.stopPropagation();

            const items = orgItems();
            const at = items.indexOf(document.activeElement as HTMLElement);

            items[
                (at + (e.key === 'ArrowDown' ? 1 : -1) + items.length) %
                    items.length
            ]?.focus();
        }
    }

    $effect(() => {
        if (!orgOpen) {
            return;
        }

        function onPointerDown(event: PointerEvent): void {
            const target = event.target as Node;

            if (
                orgMenu?.contains(target) !== true &&
                orgTrigger?.contains(target) !== true
            ) {
                orgOpen = false;
            }
        }

        document.addEventListener('pointerdown', onPointerDown, true);
        window.addEventListener('scroll', placeOrg, true);
        window.addEventListener('resize', placeOrg);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            window.removeEventListener('scroll', placeOrg, true);
            window.removeEventListener('resize', placeOrg);
        };
    });

    // The sheet opens with the focus on itself: no keyboard over the list.
    // It keeps its opening height, so searching never shrinks it: a long
    // list's sheet stays tall, its search near the top and the matches
    // above a phone's keyboard.
    $effect(() => {
        if (open && !wide.current && sheetEl) {
            sheetEl.focus();
            sheetHeight = untrack(() => sheetEl?.offsetHeight ?? 0);
        }
    });

    const FOCUSABLE =
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function onSheetKey(e: KeyboardEvent): void {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();

            return;
        }

        // Tab goes round the sheet and never behind it.
        if (e.key === 'Tab') {
            const items = Array.from(
                sheetEl?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [],
            );
            const at = items.indexOf(document.activeElement as HTMLElement);

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

    /** The sheet sits on <body>, so no container can clip or cover it. */
    function portal(node: HTMLElement) {
        document.body.appendChild(node);

        return {
            destroy() {
                node.remove();
            },
        };
    }
</script>

{#snippet face()}
    <Folder class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
    <span class={field ? 'min-w-0 flex-1 truncate' : 'max-w-[16rem] truncate'}
        >{label}</span
    >
    <ChevronDown class="h-3.5 w-3.5 shrink-0 text-fg-faint" />
{/snippet}

{#snippet body(sheet: boolean)}
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div
        class={`flex flex-col ${sheet ? 'max-h-[min(640px,85vh)] px-3 pt-3 pb-2' : 'max-h-[min(420px,60vh)]'}`}
        onkeydown={onPanelKey}
    >
        <div class="px-1 pt-1 pb-1.5">
            <label class="relative block">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                />
                <input
                    bind:this={searchEl}
                    type="text"
                    class="input pl-8"
                    placeholder="Search projects"
                    aria-label="Search projects"
                    role="combobox"
                    aria-expanded="true"
                    aria-autocomplete="list"
                    aria-controls={`${uid}-list`}
                    aria-activedescendant={rows[active]
                        ? rowId(active)
                        : undefined}
                    autocomplete="off"
                    bind:value={query}
                    onkeydown={onSearchKey}
                />
            </label>
        </div>

        {#if hasViews && !searching}
            <div class="px-1 pb-1">
                <button
                    bind:this={orgTrigger}
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={orgOpen}
                    aria-label={`Show: ${viewName}`}
                    class={`inline-flex max-w-full items-center gap-1 rounded-md px-1.5 font-medium text-fg transition hover:bg-hover ${sheet ? 'h-[40px] text-[15px]' : 'h-7 text-[13px]'}`}
                    onclick={() => (orgOpen ? (orgOpen = false) : openOrg())}
                    onkeydown={(e) => {
                        if (e.key === 'ArrowDown' && !orgOpen) {
                            e.preventDefault();
                            openOrg();
                        }
                    }}
                >
                    <span class="truncate">{viewName}</span>
                    <ChevronDown
                        class={`h-3.5 w-3.5 shrink-0 text-fg-faint transition ${orgOpen ? 'rotate-180' : ''}`}
                    />
                </button>

                {#if orgOpen}
                    <!-- Fixed, so the list's scrolling never clips it; inside
                         the panel, so a press on it keeps the picker open. -->
                    <div
                        bind:this={orgMenu}
                        role="listbox"
                        tabindex="-1"
                        aria-label="Organizations"
                        class="popover fixed z-10 overflow-y-auto px-1"
                        style={orgPlacement}
                        onkeydown={onOrgKey}
                    >
                        {#each views as choice, i (choice.id)}
                            {@const selected = choice.id === view}
                            {#if i === 1}
                                <!-- Your projects, then the organizations. -->
                                <div
                                    aria-hidden="true"
                                    class="mx-1 my-1 border-t border-line-soft"
                                ></div>
                            {/if}
                            <button
                                type="button"
                                role="option"
                                aria-selected={selected}
                                class={`menu-item ${sheet ? 'min-h-[44px] text-[15px]' : ''} ${selected ? 'text-fg' : ''}`}
                                onclick={() => chooseView(choice.id)}
                            >
                                <span class="min-w-0 flex-1 truncate"
                                    >{choice.name}</span
                                >
                                {#if selected}
                                    <Check
                                        class="h-3.5 w-3.5 shrink-0 text-accent"
                                    />
                                {/if}
                            </button>
                        {/each}
                    </div>
                {/if}
            </div>
        {/if}

        <ul
            id={`${uid}-list`}
            role="listbox"
            aria-label="Projects"
            class="min-h-0 flex-1 overflow-y-auto border-t border-line-soft px-1 pt-1"
        >
            {#each rows as row, i (row.value)}
                {@const selected = row.value === value}
                <!-- The search keeps the focus and the keys
                     (aria-activedescendant); a press here must not take it. -->
                <!-- svelte-ignore a11y_click_events_have_key_events -->
                <li
                    id={rowId(i)}
                    role="option"
                    aria-selected={selected}
                    class={`flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 ${sheet ? 'min-h-[48px]' : 'min-h-[40px]'} ${i === active ? 'bg-hover' : ''}`}
                    onmousedown={(e) => e.preventDefault()}
                    onmousemove={() => (active = i)}
                    onclick={() => pick(row.value)}
                >
                    <span class="min-w-0 flex-1 leading-tight">
                        <span
                            class={`block truncate font-medium text-fg ${sheet ? 'text-[15px]' : 'text-[14px]'}`}
                            >{row.title}</span
                        >
                        {#if row.detail}
                            <span
                                class={`mt-0.5 block truncate text-fg-muted ${sheet ? 'text-[13px]' : 'text-xs'}`}
                                >{row.detail}</span
                            >
                        {/if}
                    </span>
                    {#if selected}
                        <Check class="h-3.5 w-3.5 shrink-0 text-accent" />
                    {/if}
                </li>
            {:else}
                <li class="px-2 py-3 text-[13px] text-fg-faint">
                    {searching
                        ? `No project matches "${query.trim()}"`
                        : 'No projects here yet.'}
                </li>
            {/each}
        </ul>
    </div>
{/snippet}

<span bind:this={root} class="contents">
    {#if disabled}
        <button
            type="button"
            disabled
            aria-label={`Project: ${label}`}
            class={`${triggerClass} cursor-default opacity-60`}
        >
            {@render face()}
        </button>
    {:else if wide.current}
        <Popover
            bind:open
            role="dialog"
            triggerLabel={`Project: ${label}`}
            {triggerClass}
            wrapperClass={field ? 'w-full' : 'min-w-0 max-w-full'}
            panelClass="w-[340px] max-w-[calc(100vw-16px)]"
        >
            {#snippet trigger()}
                {@render face()}
            {/snippet}
            {@render body(false)}
        </Popover>
    {:else}
        <span class={`inline-flex ${field ? 'w-full' : 'min-w-0 max-w-full'}`}>
            <button
                type="button"
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={`Project: ${label}`}
                class={triggerClass}
                onclick={() => (open = true)}
            >
                {@render face()}
            </button>
        </span>
        {#if open}
            <div use:portal class="fixed inset-0 z-50">
                <button
                    type="button"
                    aria-label="Close the projects"
                    tabindex="-1"
                    class="absolute inset-0 cursor-default bg-black/40"
                    onclick={close}
                ></button>
                <div
                    bind:this={sheetEl}
                    role="dialog"
                    aria-modal="true"
                    aria-label="Project"
                    tabindex="-1"
                    class="popover absolute inset-x-0 bottom-0 rounded-b-none pb-[env(safe-area-inset-bottom)] focus:outline-none"
                    style={sheetHeight > 0
                        ? `min-height:${sheetHeight}px`
                        : undefined}
                    onkeydown={onSheetKey}
                >
                    {@render body(true)}
                </div>
            </div>
        {/if}
    {/if}
</span>
