<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Menu, X } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import type { AppearanceProps } from '../lib/appearance';
    import { areaOf, currentHref, navAreas } from '../lib/navigation';
    import type { AreaKey } from '../lib/navigation';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import { palette } from '../lib/palette.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import { toast } from '../lib/toast.svelte';
    import type { SharedProps } from '../lib/types';
    import AppearanceConfig from './AppearanceConfig.svelte';
    import ClockButton from './attendance/ClockButton.svelte';
    import CommandPalette from './CommandPalette.svelte';
    import QuickAddOverlay from './QuickAddOverlay.svelte';
    import NavPanel from './shell/NavPanel.svelte';
    import NavRail from './shell/NavRail.svelte';
    import TaskPeek from './TaskPeek.svelte';
    import ThemeToggle from './ThemeToggle.svelte';
    import Toasts from './Toasts.svelte';
    import WorkspaceNotesBoard from './WorkspaceNotesBoard.svelte';

    /**
     * The app frame: the sidebar (a rail of areas and the panel of the one
     * shown, see shell/) and a bordered content panel. Pages provide the
     * panel's 44px top bar through the `bar` snippet (breadcrumb on the
     * left, actions on the right); `flush` drops the content padding for
     * full-bleed registers and boards.
     */
    let {
        children,
        bar,
        title = '',
        flush = false,
    }: {
        children: Snippet;
        bar?: Snippet;
        title?: string;
        flush?: boolean;
    } = $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const flash = $derived(shared.flash ?? null);
    const path = $derived(page.url ?? '/workspace');
    const unreadNotifications = $derived(shared.unreadNotifications ?? 0);
    const appearance = $derived(
        (shared.appearance ?? null) as AppearanceProps | null,
    );
    const projects = $derived(shared.quickAddContext?.projects ?? []);
    const organizationSwitch = $derived(shared.organizationSwitch ?? null);
    // The sidebar lists the switched-to organization's projects (every one
    // while switched to all); quick add still offers them all.
    const sidebarProjects = $derived(
        organizationSwitch?.current == null
            ? projects
            : projects.filter(
                  (p) => p.organization_id === organizationSwitch.current,
              ),
    );
    const programs = $derived(shared.managedPrograms ?? []);
    const noteCount = $derived(shared.workspaceNotes?.length ?? 0);

    let mobileOpen = $state(false);

    const COLLAPSE_KEY = 'workspace.sidebar.collapsed';

    function initialCollapsed(): boolean {
        if (typeof window === 'undefined') {
            return false;
        }

        try {
            return window.localStorage.getItem(COLLAPSE_KEY) === '1';
        } catch {
            return false; // private mode / blocked storage
        }
    }

    let collapsed = $state(initialCollapsed());

    function toggleCollapsed(): void {
        collapsed = !collapsed;
        peeking = false;

        try {
            window.localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch {
            /* nothing to persist to; the session still works */
        }
    }

    let lastFlash = $state<string | null>(null);
    let settingsOpen = $state(false);

    $effect(() => {
        const message = flash?.message ?? null;

        if (message && message !== lastFlash) {
            lastFlash = message;
            const undo = flash?.undo;
            toast.show(message, {
                variant: flash?.success === false ? 'error' : 'success',
                undo: undo
                    ? {
                          label: undo.label,
                          run: () =>
                              router.post(
                                  undo.url,
                                  {},
                                  {
                                      preserveScroll: true,
                                      preserveState: false,
                                  },
                              ),
                      }
                    : undefined,
            });
        }
    });

    $effect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const id = setInterval(
            () => router.reload({ only: ['unreadNotifications'] }),
            30000,
        );

        return () => clearInterval(id);
    });

    // Navigation closes the drawer and a floating panel, and shows the
    // page's own area.
    $effect(() => {
        void path;
        shownArea = pageArea;
        mobileOpen = false;
        peeking = false;
    });

    const office = $derived(
        shared.office ?? {
            organogram: false,
            attendance: false,
            attendance_settings: false,
            leave: false,
            team_work: false,
            my_work_member_id: null,
            maintenance: false,
            maintenance_count: 0,
        },
    );

    const areas = $derived(
        navAreas(office, {
            gitEnabled: Boolean(shared.gitEnabled),
            isSuperAdmin: Boolean(shared.isSuperAdmin),
        }),
    );
    const current = $derived(currentHref(path, areas));
    const pageArea = $derived(areaOf(path, areas));

    /**
     * The area whose panel is shown: the page's, until the rail picks
     * another to look at. With the panel hidden, picking one floats its
     * panel over the content (`peeking`) until you choose or click away.
     * On phones the drawer always shows the rail and the panel.
     */
    let shownArea = $state<AreaKey>('work');
    let peeking = $state(false);

    const panelVisible = $derived(mobileOpen || !collapsed || peeking);
    const floating = $derived(!mobileOpen && collapsed && peeking);
    const panelArea = $derived(
        areas.find((a) => a.key === shownArea) ?? areas[0],
    );

    function pick(area: AreaKey): void {
        if (floating && shownArea === area) {
            peeking = false;

            return;
        }

        shownArea = area;
        peeking = !mobileOpen && collapsed;
    }

    const role = $derived(
        shared.isSuperAdmin
            ? 'Super admin'
            : shared.isOfficeAdmin
              ? `Organizational admin · ${shared.organization?.name ?? ''}`
              : (shared.organization?.name ?? user?.email ?? ''),
    );

    function logout() {
        router.post('/workspace/logout');
    }

    function isEditable(target: EventTarget | null): boolean {
        const el = target as HTMLElement | null;

        if (!el) {
            return false;
        }

        const tag = el.tagName;

        return (
            tag === 'INPUT' ||
            tag === 'TEXTAREA' ||
            tag === 'SELECT' ||
            el.isContentEditable
        );
    }

    const overlayOpen = $derived(
        quickAdd.isOpen ||
            palette.isOpen ||
            notesBoard.open ||
            settingsOpen ||
            peek.target !== null,
    );

    function openQuickAdd() {
        // On a project/task page, pre-select & lock that project so quick-add
        // never asks which project you're already working in.
        const proj = (page.props as Record<string, unknown>).project as
            { id?: number } | undefined;
        quickAdd.open(
            typeof proj?.id === 'number'
                ? { projectId: proj.id, lockProject: true }
                : {},
        );
    }

    function onGlobalKey(e: KeyboardEvent) {
        if (e.defaultPrevented) {
            return;
        }

        if (e.key === 'Escape') {
            if (peeking) {
                e.preventDefault();
                peeking = false;
            } else if (shownArea !== pageArea && !overlayOpen) {
                // Back to the page's own area after looking at another.
                e.preventDefault();
                shownArea = pageArea;
            } else if (palette.isOpen) {
                e.preventDefault();
                palette.close();
            } else if (quickAdd.isOpen) {
                e.preventDefault();
                quickAdd.close();
            } else if (settingsOpen) {
                e.preventDefault();
                settingsOpen = false;
            }

            return;
        }

        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();

            if (palette.isOpen) {
                palette.close();
            } else if (!quickAdd.isOpen && !notesBoard.open) {
                palette.open();
            }

            return;
        }

        if (isEditable(e.target) || overlayOpen) {
            return;
        }

        if (e.metaKey || e.ctrlKey || e.altKey) {
            return;
        }

        if (e.key === 'q' || e.key === 'Q' || e.key === 'n' || e.key === 'N') {
            e.preventDefault();
            openQuickAdd();

            return;
        }

        if (e.key === '/') {
            e.preventDefault();
            palette.open();

            return;
        }

        if (e.key === '[') {
            e.preventDefault();
            toggleCollapsed();
        }
    }
</script>

<div class="flex min-h-screen bg-bg text-fg">
    {#if mobileOpen}
        <button
            type="button"
            aria-label="Close menu"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            onclick={() => (mobileOpen = false)}
        ></button>
    {:else if floating}
        <!-- A click beside the floating panel puts it away. -->
        <button
            type="button"
            aria-label="Close the panel"
            class="fixed inset-0 z-30 cursor-default"
            onclick={() => (peeking = false)}
        ></button>
    {/if}

    <aside
        class="fixed inset-y-0 left-0 z-40 flex -translate-x-full bg-bg transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:self-start"
        class:translate-x-0={mobileOpen}
    >
        <NavRail
            {areas}
            selected={panelVisible ? shownArea : pageArea}
            here={pageArea}
            panelHidden={!panelVisible}
            {path}
            unread={unreadNotifications}
            {noteCount}
            notesOpen={notesBoard.open}
            {user}
            {role}
            onpick={pick}
            onnewtask={openQuickAdd}
            onsearch={() => palette.open()}
            onnotes={() => notesBoard.toggle()}
            onsettings={() => (settingsOpen = true)}
            onlogout={logout}
        />
        {#if panelVisible}
            <div
                class={floating
                    ? 'popover fixed top-2 bottom-2 left-[60px] z-40 py-0'
                    : 'h-full'}
            >
                <NavPanel
                    area={panelArea}
                    {current}
                    {path}
                    projects={sidebarProjects}
                    {programs}
                    {organizationSwitch}
                    mobile={mobileOpen}
                    {floating}
                    onnewtask={openQuickAdd}
                    onsearch={() => palette.open()}
                    ontoggle={toggleCollapsed}
                    onclose={() => (mobileOpen = false)}
                />
            </div>
        {/if}
    </aside>

    <div class="flex min-w-0 flex-1 flex-col lg:p-2 lg:pl-0">
        <div
            class="flex min-h-full flex-1 flex-col bg-surface lg:rounded-lg lg:border lg:border-line"
        >
            <div
                class="sticky top-0 z-20 flex h-12 shrink-0 items-center gap-2 border-b border-line bg-surface/90 px-4 backdrop-blur lg:rounded-t-lg lg:px-6"
            >
                <button
                    type="button"
                    aria-label="Open menu"
                    class="btn-icon lg:hidden"
                    onclick={() => (mobileOpen = true)}
                >
                    <Menu class="h-4 w-4" />
                </button>
                {#if bar}
                    {@render bar()}
                {:else}
                    <div class="flex-1 truncate font-medium">{title}</div>
                {/if}
                <ClockButton />
                <ThemeToggle />
            </div>

            <main
                class={`flex min-w-0 flex-1 flex-col ${flush ? '' : 'px-5 py-6 lg:px-8 lg:py-8'}`}
            >
                {@render children()}
            </main>
        </div>
    </div>

    <WorkspaceNotesBoard
        open={notesBoard.open}
        onClose={() => notesBoard.hide()}
    />
    <TaskPeek />
    <QuickAddOverlay />
    <CommandPalette />
    <Toasts />

    {#if settingsOpen && appearance}
        <div
            class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
            onclick={() => (settingsOpen = false)}
            role="presentation"
        >
            <!-- svelte-ignore a11y_click_events_have_key_events -->
            <div
                class="popover mx-auto mt-[10vh] mb-12 w-[min(560px,94vw)] overflow-hidden p-0"
                onclick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label="Settings"
                tabindex="-1"
            >
                <div
                    class="flex h-14 items-center justify-between gap-2 border-b border-line px-6"
                >
                    <h2 class="text-[15px] font-semibold text-fg">Settings</h2>
                    <div class="flex items-center gap-2">
                        <kbd class="kbd">esc</kbd>
                        <button
                            type="button"
                            aria-label="Close"
                            class="btn-icon"
                            onclick={() => (settingsOpen = false)}
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <AppearanceConfig
                    {appearance}
                    onsaved={() => (settingsOpen = false)}
                    oncancel={() => (settingsOpen = false)}
                />
            </div>
        </div>
    {/if}
</div>

<svelte:window onkeydown={onGlobalKey} />
