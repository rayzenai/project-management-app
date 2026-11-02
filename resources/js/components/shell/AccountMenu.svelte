<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { LogOut, Palette, Search } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import { currentHref } from '../../lib/navigation';
    import type { NavGroup, NavLink } from '../../lib/navigation';
    import Avatar from '../Avatar.svelte';
    import Popover from '../Popover.svelte';

    /**
     * Everything the sidebar doesn't carry: your notifications, emails and
     * the guide, the office pages and reports you may open, Appearance and
     * Sign out. On a desktop it opens upward from the account button in a
     * popover; on a phone (`sheet`) it rises from the bottom, under the
     * Me tab, and also carries Search and the pages the bar has no tab for.
     */
    let {
        variant,
        open = $bindable(false),
        trigger,
        triggerLabel,
        triggerClass = '',
        groups,
        path,
        user,
        role,
        extra = [],
        onsearch,
        onsettings,
        onlogout,
    }: {
        variant: 'popover' | 'sheet';
        open?: boolean;
        /** The popover's button (the sidebar's account row). */
        trigger?: Snippet<[{ open: boolean }]>;
        triggerLabel?: string;
        triggerClass?: string;
        groups: NavGroup[];
        path: string;
        user: { name: string; email?: string } | null;
        /** The line under the name. */
        role: string;
        /** Pages shown first, on a phone: those without a tab. */
        extra?: NavLink[];
        /** Given on a phone, where the sidebar's Search isn't. */
        onsearch?: () => void;
        onsettings: () => void;
        onlogout: () => void;
    } = $props();

    const sheet = $derived(variant === 'sheet');
    const current = $derived(
        currentHref(path, [...extra, ...groups.flatMap((g) => g.links)]),
    );

    function close(): void {
        open = false;
    }

    const iconClass = $derived(
        `shrink-0 ${sheet ? 'h-[18px] w-[18px]' : 'h-4 w-4'}`,
    );

    function itemClass(active: boolean): string {
        return `menu-item ${sheet ? 'min-h-[44px] gap-3 text-[15px]' : ''} ${
            active
                ? 'bg-accent-soft text-accent hover:bg-accent-soft hover:text-accent'
                : ''
        }`;
    }

    function onWindowKey(e: KeyboardEvent): void {
        if (sheet && open && e.key === 'Escape') {
            e.preventDefault();
            close();
        }
    }
</script>

{#snippet link(item: NavLink)}
    {@const active = current === item.href}
    {@const Icon = item.icon}
    <a
        href={item.href}
        use:inertia
        data-popover-item
        aria-current={active ? 'page' : undefined}
        class={itemClass(active)}
        onclick={close}
    >
        <Icon class={iconClass} />
        <span class="min-w-0 flex-1 truncate">{item.label}</span>
        {#if (item.count ?? 0) > 0}
            <span
                class={`text-xs tabular-nums ${active ? 'text-accent' : 'text-fg-faint'}`}
                >{(item.count ?? 0) > 99 ? '99+' : item.count}</span
            >
        {/if}
    </a>
{/snippet}

{#snippet body()}
    <div
        class={`max-h-[min(560px,80vh)] overflow-y-auto ${sheet ? 'px-[12px] pt-3 pb-3' : ''}`}
    >
        {#if sheet && user}
            <div class="flex items-center gap-3 px-2 pt-1 pb-3">
                <Avatar name={user.name} size="lg" />
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-[15px] font-medium text-fg">
                        {user.name}
                    </div>
                    <div class="mt-0.5 truncate text-[13px] text-fg-muted">
                        {role}
                    </div>
                </div>
            </div>
        {/if}

        {#if onsearch || extra.length > 0}
            <div class="pb-1">
                {#if onsearch}
                    <button
                        type="button"
                        data-popover-item
                        class={itemClass(false)}
                        onclick={() => {
                            close();
                            onsearch?.();
                        }}
                    >
                        <Search class={iconClass} />
                        <span class="flex-1">Search</span>
                    </button>
                {/if}
                {#each extra as item (item.href)}
                    {@render link(item)}
                {/each}
            </div>
        {/if}

        {#each groups as group, i (group.label)}
            <div
                role="group"
                aria-labelledby={`account-${variant}-${i}`}
                class={i > 0 || onsearch || extra.length > 0
                    ? 'mt-1 border-t border-line-soft pt-1'
                    : ''}
            >
                <div
                    id={`account-${variant}-${i}`}
                    class={`px-2 pt-1.5 pb-1 font-medium text-fg-faint ${sheet ? 'text-[12.5px]' : 'text-xs'}`}
                >
                    {group.label}
                </div>
                {#each group.links as item (item.href)}
                    {@render link(item)}
                {/each}
            </div>
        {/each}

        <div class="mt-1 border-t border-line-soft pt-1">
            <button
                type="button"
                data-popover-item
                class={itemClass(false)}
                onclick={() => {
                    close();
                    onsettings();
                }}
            >
                <Palette class={iconClass} />
                <span class="flex-1">Appearance</span>
            </button>
            <button
                type="button"
                data-popover-item
                class={itemClass(false)}
                onclick={onlogout}
            >
                <LogOut class={iconClass} />
                <span class="flex-1">Sign out</span>
            </button>
        </div>
    </div>
{/snippet}

{#if variant === 'popover' && trigger}
    <Popover
        bind:open
        {trigger}
        {triggerLabel}
        {triggerClass}
        wrapperClass="w-full"
        panelClass="w-[240px]"
    >
        {@render body()}
    </Popover>
{:else if sheet && open}
    <div class="fixed inset-0 z-50 md:hidden">
        <button
            type="button"
            aria-label="Close the menu"
            class="absolute inset-0 cursor-default bg-black/40"
            onclick={close}
        ></button>
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Me"
            class="popover absolute inset-x-0 bottom-0 rounded-b-none pb-[env(safe-area-inset-bottom)]"
        >
            {@render body()}
        </div>
    </div>
{/if}

<svelte:window onkeydown={onWindowKey} />
