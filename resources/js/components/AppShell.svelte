<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { Bell, Menu, Settings, StickyNote, X } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import { themesToList } from '../lib/appearance';
    import type { AppearanceProps } from '../lib/appearance';
    import { initials } from '../lib/format';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import { palette } from '../lib/palette.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import { toast } from '../lib/toast.svelte';
    import type { SharedProps } from '../lib/types';
    import AppearanceConfig from './AppearanceConfig.svelte';
    import CommandPalette from './CommandPalette.svelte';
    import QuickAddOverlay from './QuickAddOverlay.svelte';
    import TaskPeek from './TaskPeek.svelte';
    import Toasts from './Toasts.svelte';
    import WorkspaceNotesBoard from './WorkspaceNotesBoard.svelte';

    let { children }: { children: Snippet } = $props();

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const flash = $derived(shared.flash ?? null);
    const path = $derived(page.url ?? '/workspace');
    const unreadNotifications = $derived(shared.unreadNotifications ?? 0);
    const appearance = $derived(
        (shared.appearance ?? null) as AppearanceProps | null,
    );

    // Theme catalogue shared from `config/themes.php` (web only). Feeding it as a
    // prop lets AppearanceConfig skip the Sanctum-protected `GET /api/v1/themes`
    // fetch, which 401s in the session context the web UI runs in.
    const catalogue = $derived(shared.themeCatalogue ?? null);
    const catalogueThemes = $derived(
        catalogue ? themesToList(catalogue.themes) : undefined,
    );
    const catalogueFonts = $derived(catalogue?.fontAllowList);

    let mobileOpen = $state(false);
    let lastFlash = $state<string | null>(null);

    // First-run gate: blocks the workspace until the user saves preferences.
    // Local flag lets us hide the overlay immediately on save; the next Inertia
    // prop refresh carries `configured: true`.
    let onboardingDismissed = $state(false);
    const showOnboarding = $derived(
        appearance?.configured === false && !onboardingDismissed,
    );

    // Settings → Appearance, reachable any time from the header as a modal panel.
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

    const nav = [
        { label: 'Overview', href: '/workspace', icon: '▦' },
        { label: 'My Workspace', href: '/workspace/my', icon: '✦' },
        { label: 'Projects', href: '/workspace/projects', icon: '▤' },
        { label: 'Team', href: '/workspace/team', icon: '◎' },
    ];

    function isActive(href: string) {
        return (
            path === href ||
            (href !== '/workspace' && path.startsWith(href + '/'))
        );
    }

    function logout() {
        router.post('/workspace/logout');
    }

    function skipOnboarding() {
        // Save the defaults so `configured` flips true — never trap the user.
        router.patch(
            '/workspace/preferences',
            {
                theme: 'system',
                font_override: { display: null, body: null, mono: null },
                email_notifications: true,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (onboardingDismissed = true),
            },
        );
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

    function onGlobalKey(e: KeyboardEvent) {
        if (e.defaultPrevented) {
            return;
        }

        // Esc fallback — the overlays handle Esc themselves when focused; this
        // catches the case where focus drifted back to the document.
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

        // ⌘K / Ctrl+K toggles the palette even from inside an input.
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();

            if (palette.isOpen) {
                palette.close();
            } else if (!quickAdd.isOpen && !notesBoard.open) {
                palette.open();
            }

            return;
        }

        // Bare-key shortcuts only when nothing editable is focused, no overlay
        // is open, and no modifier is held.
        if (isEditable(e.target) || overlayOpen) {
            return;
        }

        if (e.metaKey || e.ctrlKey || e.altKey) {
            return;
        }

        if (e.key === 'q' || e.key === 'Q') {
            e.preventDefault();
            // On a project/task page, pre-select & lock that project so quick-add
            // never asks which project you're already working in.
            const proj = (page.props as Record<string, unknown>).project as
                { id?: number } | undefined;
            quickAdd.open(
                typeof proj?.id === 'number'
                    ? { projectId: proj.id, lockProject: true }
                    : {},
            );

            return;
        }

        if (e.key === '/') {
            e.preventDefault();
            palette.open();
        }
    }
</script>

<div class="ws-canvas bg-bg text-fg flex min-h-screen">
    {#if mobileOpen}
        <button
            type="button"
            aria-label="Close menu"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            onclick={() => (mobileOpen = false)}
        ></button>
    {/if}

    <aside
        class="bg-surface fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-line px-4 py-6 transition-transform lg:static lg:translate-x-0"
        class:translate-x-0={mobileOpen}
    >
        <div class="mb-8 flex items-center justify-between">
            <div>
                <div class="font-display text-lg font-bold tracking-tight">
                    Workspace
                </div>
            </div>
            <button
                type="button"
                aria-label="Close sidebar"
                class="text-fg-muted hover:bg-surface-alt rounded-md p-1 lg:hidden"
                onclick={() => (mobileOpen = false)}
                ><X class="h-5 w-5" /></button
            >
        </div>

        <nav class="flex flex-1 flex-col gap-1">
            {#each nav as item (item.href)}
                {@const active = isActive(item.href)}
                <a
                    href={item.href}
                    class={`flex items-center gap-3 rounded-lg border-l-2 px-3 py-2 font-mono text-[0.8rem] font-medium tracking-wide transition ${
                        active
                            ? 'border-accent bg-accent/10 text-accent'
                            : 'text-fg-muted hover:bg-surface-alt border-transparent'
                    }`}
                >
                    <span class="w-5 text-center text-base">{item.icon}</span>
                    <span>{item.label}</span>
                </a>
            {/each}
        </nav>

        <div class="mt-6 border-t border-line pt-4">
            {#if user}
                <div class="flex items-center gap-3">
                    <div
                        class="bg-surface-alt text-fg-muted flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold"
                    >
                        {initials(user.name)}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">
                            {user.name}
                        </div>
                        <div class="text-fg-muted truncate text-xs">
                            {user.email}
                        </div>
                    </div>
                    <button
                        type="button"
                        aria-label="Sign out"
                        class="text-fg-muted hover:bg-surface-alt hover:text-fg rounded-md p-1"
                        onclick={logout}
                        title="Sign out">↩</button
                    >
                </div>
            {/if}
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header
            class="bg-surface/80 sticky top-0 z-20 flex h-14 items-center justify-between border-b border-line px-4 backdrop-blur-md lg:px-8"
        >
            <button
                type="button"
                aria-label="Open menu"
                class="text-fg-muted hover:bg-surface-alt rounded-md p-2 lg:hidden"
                onclick={() => (mobileOpen = true)}
                ><Menu class="h-5 w-5" /></button
            >
            <div class="flex-1"></div>
            <div class="flex items-center gap-2">
                <a
                    href="/workspace/notifications"
                    use:inertia
                    aria-label={`Notifications${unreadNotifications ? ` (${unreadNotifications} unread)` : ''}`}
                    title="Notifications"
                    class="text-fg-muted hover:bg-surface-alt relative rounded-md p-2"
                >
                    <Bell class="h-5 w-5" />
                    {#if unreadNotifications > 0}
                        <span
                            class="bg-accent text-bg absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold"
                            >{unreadNotifications > 99
                                ? '99+'
                                : unreadNotifications}</span
                        >
                    {/if}
                </a>
                <button
                    type="button"
                    onclick={() => notesBoard.toggle()}
                    aria-label="My notes"
                    aria-expanded={notesBoard.open}
                    title="My notes"
                    class="text-fg-muted hover:bg-surface-alt rounded-md p-2"
                >
                    <StickyNote class="h-5 w-5" />
                </button>
                <button
                    type="button"
                    onclick={() => (settingsOpen = true)}
                    aria-label="Appearance settings"
                    title="Appearance"
                    class="text-fg-muted hover:bg-surface-alt rounded-md p-2"
                >
                    <Settings class="h-5 w-5" />
                </button>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 lg:px-8">
            {@render children()}
        </main>
    </div>

    <WorkspaceNotesBoard
        open={notesBoard.open}
        onClose={() => notesBoard.hide()}
    />
    <TaskPeek />
    <QuickAddOverlay />
    <CommandPalette />
    <Toasts />

    <!-- Settings → Appearance: a modal panel reachable any time from the header -->
    {#if settingsOpen && appearance}
        <div
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 backdrop-blur-sm"
        >
            <button
                type="button"
                aria-label="Close"
                class="fixed inset-0"
                onclick={() => (settingsOpen = false)}
            ></button>
            <div
                class="bg-bg text-fg relative my-8 w-full max-w-2xl rounded-2xl border border-line p-6 shadow-2xl"
            >
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="font-display text-xl font-bold tracking-tight">
                        Appearance
                    </h2>
                    <button
                        type="button"
                        aria-label="Close"
                        class="text-fg-muted hover:bg-surface-alt hover:text-fg rounded-md p-1"
                        onclick={() => (settingsOpen = false)}>✕</button
                    >
                </div>
                <AppearanceConfig
                    {appearance}
                    themes={catalogueThemes}
                    fontAllowList={catalogueFonts}
                    onsaved={() => (settingsOpen = false)}
                />
            </div>
        </div>
    {/if}

    <!-- First-run gate: full-screen blocking overlay until the user configures -->
    {#if showOnboarding && appearance}
        <div class="bg-bg text-fg fixed inset-0 z-50 overflow-y-auto">
            <div class="mx-auto w-full max-w-2xl px-4 py-12">
                <div class="mb-8 text-center">
                    <div class="ws-eyebrow text-accent">Welcome</div>
                    <h1 class="font-display text-3xl font-bold tracking-tight">
                        Make it yours
                    </h1>
                    <p class="text-fg-muted mt-2 text-sm">
                        Choose a theme and fonts before you start. You can
                        change these any time from the ⚙ menu.
                    </p>
                </div>
                <AppearanceConfig
                    {appearance}
                    themes={catalogueThemes}
                    fontAllowList={catalogueFonts}
                    onsaved={() => (onboardingDismissed = true)}
                />
                <div class="mt-6 text-center">
                    <button
                        type="button"
                        onclick={skipOnboarding}
                        class="text-fg-faint hover:text-fg-muted text-sm underline-offset-4 hover:underline"
                    >
                        Skip — use defaults
                    </button>
                </div>
            </div>
        </div>
    {/if}
</div>

<svelte:window onkeydown={onGlobalKey} />
