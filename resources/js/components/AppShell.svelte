<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import {
        Bell,
        Folder,
        LayoutGrid,
        LogOut,
        Menu,
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
        { label: 'Overview', href: '/workspace', icon: LayoutGrid },
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
        class="fixed inset-y-0 left-0 z-40 flex w-[232px] -translate-x-full flex-col gap-0.5 bg-bg px-2.5 pt-3 pb-2.5 transition-transform lg:static lg:translate-x-0"
        class:translate-x-0={mobileOpen}
    >
        <div class="mb-1.5 flex items-center gap-2 px-2 py-1.5">
            <span
                class="grid h-[18px] w-[18px] shrink-0 place-items-center rounded-[5px] bg-accent text-[10px] font-semibold text-white"
                >W</span
            >
            <span class="truncate font-semibold">Workspace</span>
            <span class="ml-auto flex items-center gap-0.5">
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    title="Search (⌘K)"
                    aria-label="Search"
                    onclick={() => palette.open()}
                >
                    <Search class="h-[15px] w-[15px]" />
                </button>
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    title="New task (N)"
                    aria-label="New task"
                    onclick={openQuickAdd}
                >
                    <Plus class="h-[15px] w-[15px]" />
                </button>
                <button
                    type="button"
                    aria-label="Close sidebar"
                    class="btn-icon h-6 w-6 lg:hidden"
                    onclick={() => (mobileOpen = false)}
                >
                    <X class="h-[15px] w-[15px]" />
                </button>
            </span>
        </div>

        <nav class="flex flex-col gap-0.5">
            {#each nav as item (item.href)}
                {@const active = isActive(item.href)}
                {@const Icon = item.icon}
                <a
                    href={item.href}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    class={`flex items-center gap-2.5 rounded-md px-2 py-[5px] font-medium transition ${
                        active
                            ? 'bg-accent-soft text-accent'
                            : 'text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    <Icon
                        class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    <span class="truncate">{item.label}</span>
                    {#if item.href === '/workspace/notifications' && unreadNotifications > 0}
                        <span
                            class="ml-auto rounded-full bg-accent px-1.5 text-[11px] leading-4 font-medium text-white tabular-nums"
                            >{unreadNotifications > 99
                                ? '99+'
                                : unreadNotifications}</span
                        >
                    {/if}
                </a>
            {/each}
        </nav>

        <div
            class="mt-3 flex items-center justify-between px-2 pb-1 text-xs font-medium text-fg-faint"
        >
            <a href="/workspace/projects" use:inertia class="hover:text-fg"
                >Projects</a
            >
            <span class="tabular-nums">{projects.length}</span>
        </div>
        <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
            {#each projects as project (project.id)}
                {@const active = projectActive(project.slug)}
                <a
                    href={`/workspace/projects/${project.slug}`}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    class={`flex items-center gap-2.5 rounded-md px-2 py-[5px] transition ${
                        active
                            ? 'bg-accent-soft text-accent'
                            : 'text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    <Folder
                        class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    <span class="truncate">{project.title}</span>
                </a>
            {:else}
                <span class="px-2 py-1 text-xs text-fg-faint"
                    >No projects yet</span
                >
            {/each}
        </nav>

        <button
            type="button"
            onclick={() => notesBoard.toggle()}
            aria-expanded={notesBoard.open}
            class="mt-2 flex items-center gap-2.5 rounded-md px-2 py-[5px] font-medium text-fg-muted transition hover:bg-hover hover:text-fg"
        >
            <StickyNote class="h-[15px] w-[15px] shrink-0 text-fg-faint" />
            <span class="truncate">Sticky notes</span>
            {#if noteCount > 0}
                <span class="ml-auto text-xs text-fg-faint tabular-nums"
                    >{noteCount}</span
                >
            {/if}
        </button>

        {#if user}
            <div
                class="mt-1.5 flex items-center gap-2.5 border-t border-line pt-2.5 pl-1"
            >
                <Avatar name={user.name} size="lg" />
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate font-medium">{user.name}</div>
                    <div class="truncate text-xs text-fg-muted">
                        {shared.isSuperAdmin ? 'Super admin' : user.email}
                    </div>
                </div>
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    title="Settings"
                    aria-label="Settings"
                    onclick={() => (settingsOpen = true)}
                >
                    <Settings class="h-[15px] w-[15px]" />
                </button>
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    title="Sign out"
                    aria-label="Sign out"
                    onclick={logout}
                >
                    <LogOut class="h-[15px] w-[15px]" />
                </button>
            </div>
        {/if}
    </aside>

    <div class="flex min-w-0 flex-1 flex-col lg:p-2 lg:pl-0">
        <div
            class="flex min-h-full flex-1 flex-col bg-surface lg:rounded-lg lg:border lg:border-line"
        >
            <div
                class="sticky top-0 z-20 flex h-11 shrink-0 items-center gap-2 border-b border-line bg-surface/90 px-3 backdrop-blur lg:rounded-t-lg lg:px-4"
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
                class={`flex min-w-0 flex-1 flex-col ${flush ? '' : 'px-4 py-5 lg:px-8 lg:py-6'}`}
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
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
        >
            <button
                type="button"
                aria-label="Close"
                class="fixed inset-0 cursor-default"
                onclick={() => (settingsOpen = false)}
            ></button>
            <div class="popover relative my-12 w-full max-w-md px-5 py-4">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold">Settings</h2>
                    <button
                        type="button"
                        aria-label="Close"
                        class="btn-icon"
                        onclick={() => (settingsOpen = false)}
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <AppearanceConfig
                    {appearance}
                    onsaved={() => (settingsOpen = false)}
                />
            </div>
        </div>
    {/if}
</div>

<svelte:window onkeydown={onGlobalKey} />
