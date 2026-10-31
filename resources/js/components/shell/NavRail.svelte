<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import {
        Bell,
        BookOpen,
        LogOut,
        Plus,
        Search,
        Settings,
        StickyNote,
    } from '@lucide/svelte';
    import type { AreaKey, NavArea } from '../../lib/navigation';
    import Avatar from '../Avatar.svelte';
    import Popover from '../Popover.svelte';
    import Logo from './Logo.svelte';

    /**
     * The slim rail on the left edge: the areas (Work, Office, Reports,
     * Admin) that switch the panel beside it, and what is wanted from
     * anywhere (notifications, sticky notes, the guide, your account). When
     * the panel is hidden it also carries New task and Search.
     */
    let {
        areas,
        selected,
        here,
        panelHidden,
        path,
        unread,
        noteCount,
        notesOpen,
        user,
        role,
        onpick,
        onnewtask,
        onsearch,
        onnotes,
        onsettings,
        onlogout,
    }: {
        areas: NavArea[];
        /** The area whose panel is shown, or the page's when none is. */
        selected: AreaKey;
        /** The page's own area, tinted while another is shown. */
        here: AreaKey;
        panelHidden: boolean;
        path: string;
        unread: number;
        noteCount: number;
        notesOpen: boolean;
        user: { name: string } | null;
        /** The line under the name in the account menu. */
        role: string;
        onpick: (area: AreaKey) => void;
        onnewtask: () => void;
        onsearch: () => void;
        onnotes: () => void;
        onsettings: () => void;
        onlogout: () => void;
    } = $props();

    let accountOpen = $state(false);

    function on(href: string): boolean {
        return path.split('?')[0] === href;
    }

    function count(n: number): string {
        return n > 99 ? '99+' : String(n);
    }
</script>

<!-- A count on a rail icon: a small pill on its corner. -->
{#snippet pill(n: number)}
    {#if n > 0}
        <span
            class="absolute -top-1 -right-1.5 min-w-4 rounded-full bg-accent px-1 text-center text-[10px] leading-4 font-semibold text-white tabular-nums ring-2 ring-surface-alt"
            >{count(n)}</span
        >
    {/if}
{/snippet}

<div
    class="flex h-full w-14 shrink-0 flex-col items-center border-r border-line bg-surface-alt py-3"
>
    <a
        href="/workspace"
        use:inertia
        class="mb-3 rounded-md"
        title="Home"
        aria-label="Home"
    >
        <Logo class="h-7 w-7" />
    </a>

    {#if panelHidden}
        <div class="mb-2 flex flex-col items-center gap-1">
            <button
                type="button"
                class="btn-primary h-8 w-8 justify-center px-0"
                title="New task (N)"
                aria-label="New task"
                onclick={onnewtask}
            >
                <Plus class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="btn-icon h-8 w-8"
                title="Search (⌘K)"
                aria-label="Search"
                onclick={onsearch}
            >
                <Search class="h-[15px] w-[15px]" />
            </button>
        </div>
        <div class="mb-2 h-px w-8 bg-line"></div>
    {/if}

    <nav aria-label="Areas" class="flex flex-col items-center gap-1">
        {#each areas as area (area.key)}
            {@const active = selected === area.key}
            {@const home = !active && here === area.key}
            {@const Icon = area.icon}
            <button
                type="button"
                aria-pressed={active}
                title={area.label}
                onclick={() => onpick(area.key)}
                class={`group flex w-12 flex-col items-center gap-1 rounded-md py-1 text-[10.5px] font-medium transition ${
                    active || home
                        ? 'text-accent'
                        : 'text-fg-muted hover:text-fg'
                }`}
            >
                <span
                    class={`relative flex h-8 w-8 items-center justify-center rounded-md transition ${
                        active ? 'bg-accent-soft' : 'group-hover:bg-hover'
                    }`}
                >
                    <Icon class="h-[18px] w-[18px]" />
                    {@render pill(area.count)}
                </span>
                {area.label}
            </button>
        {/each}
    </nav>

    <div class="mt-auto flex flex-col items-center gap-1">
        <a
            href="/workspace/notifications"
            use:inertia
            aria-current={on('/workspace/notifications') ? 'page' : undefined}
            title="Notifications"
            aria-label={unread > 0
                ? `Notifications, ${unread} unread`
                : 'Notifications'}
            class={`relative flex h-8 w-8 items-center justify-center rounded-md transition ${
                on('/workspace/notifications')
                    ? 'bg-accent-soft text-accent'
                    : 'text-fg-muted hover:bg-hover hover:text-fg'
            }`}
        >
            <Bell class="h-[17px] w-[17px]" />
            {@render pill(unread)}
        </a>
        <button
            type="button"
            title={noteCount > 0
                ? `Sticky notes (${noteCount})`
                : 'Sticky notes'}
            aria-label="Sticky notes"
            aria-pressed={notesOpen}
            onclick={onnotes}
            class={`flex h-8 w-8 items-center justify-center rounded-md transition ${
                notesOpen
                    ? 'bg-accent-soft text-accent'
                    : 'text-fg-muted hover:bg-hover hover:text-fg'
            }`}
        >
            <StickyNote class="h-[17px] w-[17px]" />
        </button>
        <a
            href="/workspace/guide"
            use:inertia
            aria-current={on('/workspace/guide') ? 'page' : undefined}
            title="Guide"
            aria-label="Guide"
            class={`flex h-8 w-8 items-center justify-center rounded-md transition ${
                on('/workspace/guide')
                    ? 'bg-accent-soft text-accent'
                    : 'text-fg-muted hover:bg-hover hover:text-fg'
            }`}
        >
            <BookOpen class="h-[17px] w-[17px]" />
        </a>

        {#if user}
            <div class="mt-2">
                <Popover
                    bind:open={accountOpen}
                    triggerLabel={`Account: ${user.name}`}
                    triggerClass="rounded-md p-0.5 transition hover:bg-hover"
                >
                    {#snippet trigger()}
                        <Avatar name={user.name} size="lg" />
                    {/snippet}
                    <div class="w-56">
                        <div class="px-3 py-2 leading-tight">
                            <div class="truncate font-medium text-fg">
                                {user.name}
                            </div>
                            <div class="truncate text-xs text-fg-muted">
                                {role}
                            </div>
                        </div>
                        <div class="my-1 border-t border-line-soft"></div>
                        <div class="px-1">
                            <button
                                type="button"
                                data-popover-item
                                class="menu-item"
                                onclick={() => {
                                    accountOpen = false;
                                    onsettings();
                                }}
                            >
                                <Settings class="h-3.5 w-3.5" />
                                Settings
                            </button>
                            <button
                                type="button"
                                data-popover-item
                                class="menu-item"
                                onclick={onlogout}
                            >
                                <LogOut class="h-3.5 w-3.5" />
                                Sign out
                            </button>
                        </div>
                    </div>
                </Popover>
            </div>
        {/if}
    </div>
</div>
