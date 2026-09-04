<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import {
        Bell,
        Folder,
        LayoutGrid,
        LogOut,
        Menu,
        PanelLeft,
        Plus,
        Search,
        Settings,
        StickyNote,
        UserRound,
        Users,
        X,
    } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import type { AppearanceProps } from '../lib/appearance';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import { palette } from '../lib/palette.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import { toast } from '../lib/toast.svelte';
    import type { SharedProps } from '../lib/types';
    import AppearanceConfig from './AppearanceConfig.svelte';
    import Avatar from './Avatar.svelte';
    import CommandPalette from './CommandPalette.svelte';
    import QuickAddOverlay from './QuickAddOverlay.svelte';
    import TaskPeek from './TaskPeek.svelte';
    import Toasts from './Toasts.svelte';
    import WorkspaceNotesBoard from './WorkspaceNotesBoard.svelte';

    /**
     * The app frame: a 232px sidebar and a bordered content panel. Pages
     * provide the panel's 44px top bar through the `bar` snippet (breadcrumb
     * on the left, actions on the right); `flush` drops the content padding
     * for full-bleed registers and boards.
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

    /**
     * The rail is a desktop affordance. On small screens the sidebar is a
     * slide-over drawer, and a drawer of bare icons helps nobody — so whenever
     * the drawer is open it renders in full regardless of the setting.
     */
    const rail = $derived(collapsed && !mobileOpen);

    function toggleCollapsed(): void {
        collapsed = !collapsed;

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

    // Close the mobile drawer on navigation.
    $effect(() => {
        void path;
        mobileOpen = false;
    });

    const nav = [
        { label: 'Home', href: '/workspace', icon: LayoutGrid },
        { label: 'My Workspace', href: '/workspace/my', icon: UserRound },
        {
            label: 'Notifications',
            href: '/workspace/notifications',
            icon: Bell,
        },
        { label: 'Team', href: '/workspace/team', icon: Users },
    ];

    function isActive(href: string) {
        return (
            path === href ||
            (href !== '/workspace' && path.startsWith(href + '/'))
        );
    }

    function projectActive(slug: string) {
        return path.startsWith(`/workspace/projects/${slug}`);
    }

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
            if (palette.isOpen) {
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
    {/if}

    <aside
        class={`fixed inset-y-0 left-0 z-40 flex -translate-x-full flex-col bg-bg pt-3 pb-3 transition-[width,transform] lg:sticky lg:top-0 lg:bottom-auto lg:h-screen lg:translate-x-0 lg:self-start ${
            rail ? 'w-[248px] px-3 lg:w-[60px] lg:px-2' : 'w-[248px] px-3'
        }`}
        class:translate-x-0={mobileOpen}
    >
        <!-- Brand + the collapse control. -->
        <div
            class={`flex h-8 items-center gap-2 ${rail ? 'justify-center px-0' : 'px-1'}`}
        >
            {#if !rail}
                <svg
                    class="h-5 w-5 shrink-0"
                    viewBox="0 0 32 32"
                    role="img"
                    aria-label="Workspace"
                >
                    <rect width="32" height="32" rx="8" class="fill-accent" />
                    <circle
                        cx="16"
                        cy="16"
                        r="9"
                        fill="none"
                        stroke="#fff"
                        stroke-width="2.4"
                    />
                    <path d="M16 16V7a9 9 0 1 1-7.28 14.29z" fill="#fff" />
                </svg>
                <span class="truncate font-semibold">Workspace</span>
            {/if}
            <span class={rail ? '' : 'ml-auto'}>
                <button
                    type="button"
                    class="btn-icon hidden h-7 w-7 lg:inline-flex"
                    title={`${collapsed ? 'Expand' : 'Collapse'} sidebar ([)`}
                    aria-label={collapsed
                        ? 'Expand sidebar'
                        : 'Collapse sidebar'}
                    aria-expanded={!collapsed}
                    onclick={toggleCollapsed}
                >
                    <PanelLeft class="h-[15px] w-[15px]" />
                </button>
                <button
                    type="button"
                    aria-label="Close sidebar"
                    class="btn-icon h-7 w-7 lg:hidden"
                    onclick={() => (mobileOpen = false)}
                >
                    <X class="h-[15px] w-[15px]" />
                </button>
            </span>
        </div>

        <!-- The two things you reach for most, as real buttons rather than
             icons tucked into the brand row. -->
        {#if rail}
            <div class="mt-3 flex flex-col items-center gap-1">
                <button
                    type="button"
                    class="btn-primary h-8 w-8 justify-center px-0"
                    title="New task (N)"
                    aria-label="New task"
                    onclick={openQuickAdd}
                >
                    <Plus class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="btn-icon h-8 w-8"
                    title="Search (⌘K)"
                    aria-label="Search"
                    onclick={() => palette.open()}
                >
                    <Search class="h-[15px] w-[15px]" />
                </button>
            </div>
        {:else}
            <div class="mt-3 flex flex-col gap-1.5">
                <button
                    type="button"
                    class="btn-primary h-9 w-full justify-center"
                    onclick={openQuickAdd}
                >
                    <Plus class="h-4 w-4" />
                    New task
                    <kbd
                        class="kbd ml-auto border-white/30 bg-transparent text-white/80"
                        >N</kbd
                    >
                </button>
                <button
                    type="button"
                    class="flex h-8 w-full items-center gap-2.5 rounded-md border border-line bg-surface px-2.5 text-fg-muted transition hover:bg-hover hover:text-fg"
                    onclick={() => palette.open()}
                >
                    <Search class="h-[15px] w-[15px] shrink-0 text-fg-faint" />
                    Search
                    <kbd class="kbd ml-auto">⌘K</kbd>
                </button>
            </div>
        {/if}

        <div class="my-3 border-t border-line"></div>

        <nav class="flex flex-col gap-0.5">
            {#each nav as item (item.href)}
                {@const active = isActive(item.href)}
                {@const Icon = item.icon}
                <a
                    href={item.href}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    title={rail ? item.label : undefined}
                    class={`relative flex items-center gap-2.5 rounded-md py-2 font-medium transition ${
                        rail ? 'justify-center px-0' : 'px-2.5'
                    } ${
                        active
                            ? 'bg-accent-soft text-accent'
                            : 'text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    <Icon
                        class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    {#if !rail}
                        <span class="truncate">{item.label}</span>
                    {/if}
                    {#if item.href === '/workspace/notifications' && unreadNotifications > 0}
                        {#if rail}
                            <!-- No room for a count on the rail; a dot still says "unread". -->
                            <span
                                class="absolute top-1 right-1 h-1.5 w-1.5 rounded-full bg-accent"
                                aria-hidden="true"
                            ></span>
                        {:else}
                            <span
                                class="ml-auto rounded-full bg-accent px-1.5 text-[11px] leading-4 font-medium text-white tabular-nums"
                                >{unreadNotifications > 99
                                    ? '99+'
                                    : unreadNotifications}</span
                            >
                        {/if}
                    {/if}
                </a>
            {/each}
        </nav>

        <div class="my-3 border-t border-line"></div>

        {#if !rail}
            <div
                class="mb-1 flex items-center gap-2 px-2.5 text-xs font-medium text-fg-faint"
            >
                <a href="/workspace/projects" use:inertia class="hover:text-fg"
                    >Projects</a
                >
                <span class="tabular-nums">{projects.length}</span>
                <a
                    href="/workspace/projects?new=1"
                    use:inertia
                    class="btn-icon ml-auto h-6 w-6"
                    title="New project"
                    aria-label="New project"
                >
                    <Plus class="h-3.5 w-3.5" />
                </a>
            </div>
        {/if}
        <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
            {#each projects as project (project.id)}
                {@const active = projectActive(project.slug)}
                <a
                    href={`/workspace/projects/${project.slug}`}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    title={rail ? project.title : undefined}
                    class={`flex items-center gap-2.5 rounded-md py-2 transition ${
                        rail ? 'justify-center px-0' : 'px-2.5'
                    } ${
                        active
                            ? 'bg-accent-soft text-accent'
                            : 'text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    <Folder
                        class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    {#if !rail}
                        <span class="truncate">{project.title}</span>
                    {/if}
                </a>
            {:else}
                {#if !rail}
                    <span class="px-2.5 py-1 text-xs text-fg-faint"
                        >No projects yet</span
                    >
                {/if}
            {/each}
        </nav>

        <div class="mt-3 border-t border-line pt-3">
            <button
                type="button"
                onclick={() => notesBoard.toggle()}
                aria-expanded={notesBoard.open}
                title={rail ? 'Sticky notes' : undefined}
                class={`flex w-full items-center gap-2.5 rounded-md py-2 font-medium text-fg-muted transition hover:bg-hover hover:text-fg ${
                    rail ? 'justify-center px-0' : 'px-2.5'
                }`}
            >
                <StickyNote class="h-[15px] w-[15px] shrink-0 text-fg-faint" />
                {#if !rail}
                    <span class="truncate">Sticky notes</span>
                    {#if noteCount > 0}
                        <span class="ml-auto text-xs text-fg-faint tabular-nums"
                            >{noteCount}</span
                        >
                    {/if}
                {/if}
            </button>

            {#if user}
                <div
                    class={`mt-2 flex rounded-md ${
                        rail
                            ? 'flex-col items-center gap-1.5'
                            : 'items-center gap-2.5 border border-line bg-surface px-2.5 py-2'
                    }`}
                >
                    <Avatar name={user.name} size="lg" />
                    {#if !rail}
                        <div class="min-w-0 flex-1 leading-tight">
                            <div class="truncate font-medium">{user.name}</div>
                            <div class="truncate text-xs text-fg-muted">
                                {shared.isSuperAdmin
                                    ? 'Super admin'
                                    : user.email}
                            </div>
                        </div>
                    {/if}
                    <button
                        type="button"
                        class="btn-icon h-7 w-7"
                        title={rail ? `Settings — ${user.name}` : 'Settings'}
                        aria-label="Settings"
                        onclick={() => (settingsOpen = true)}
                    >
                        <Settings class="h-[15px] w-[15px]" />
                    </button>
                    <button
                        type="button"
                        class="btn-icon h-7 w-7"
                        title="Sign out"
                        aria-label="Sign out"
                        onclick={logout}
                    >
                        <LogOut class="h-[15px] w-[15px]" />
                    </button>
                </div>
            {/if}
        </div>
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
                    <div class="truncate font-medium">{title}</div>
                {/if}
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
