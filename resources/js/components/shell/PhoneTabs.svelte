<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { Bell, FolderKanban, ListChecks } from '@lucide/svelte';
    import { MediaQuery } from 'svelte/reactivity';
    import { msg, number, t } from '../../lib/i18n';
    import { currentHref, under } from '../../lib/navigation';
    import type { NavGroup, NavLink } from '../../lib/navigation';
    import type { OrganizationSwitch } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import AccountMenu from './AccountMenu.svelte';

    /**
     * The phone's menu, below 768px: a bar fixed to the bottom with My
     * work, Projects, Updates (the notifications, with the unread count)
     * and Me, which raises the account menu as a sheet. The sheet also
     * carries New task, Search and the sidebar's pages that have no tab
     * here.
     */
    let {
        links,
        groups,
        path,
        unread,
        user,
        role,
        organizationSwitch = null,
        onnewtask,
        onsearch,
        onsettings,
        onlogout,
    }: {
        /** The sidebar's pages; those without a tab go in the sheet. */
        links: NavLink[];
        groups: NavGroup[];
        path: string;
        unread: number;
        user: { name: string; email?: string } | null;
        role: string;
        organizationSwitch?: OrganizationSwitch | null;
        onnewtask: () => void;
        onsearch: () => void;
        onsettings: () => void;
        onlogout: () => void;
    } = $props();

    const TABS = [
        { label: msg('My work'), href: '/workspace/my', icon: ListChecks },
        {
            label: msg('Projects'),
            href: '/workspace/projects',
            icon: FolderKanban,
        },
        {
            label: msg('Updates'),
            href: '/workspace/notifications',
            icon: Bell,
        },
    ];

    let meOpen = $state(false);
    let meButton = $state<HTMLButtonElement | null>(null);

    // Tailwind's md: from there the sidebar shows and the bar is gone, so
    // a sheet left open must not come back when the window narrows again.
    const wide = new MediaQuery('(min-width: 48rem)', false);

    $effect(() => {
        if (wide.current) {
            meOpen = false;
        }
    });

    // The sheet's groups already carry some (Code activity): once is enough.
    const extra = $derived(
        links.filter(
            (l) =>
                !TABS.some((item) => item.href === l.href) &&
                !groups.some((g) => g.links.some((gl) => gl.href === l.href)),
        ),
    );
    // A project's pages, programs and tasks are all under Projects.
    const tab = $derived(
        under(path, '/workspace/programs')
            ? '/workspace/projects'
            : currentHref(path, TABS),
    );
    const meHere = $derived(
        meOpen ||
            (tab === null &&
                currentHref(path, [
                    ...extra,
                    ...groups.flatMap((g) => g.links),
                ]) !== null),
    );

    function tabClass(active: boolean): string {
        return `flex min-h-[56px] flex-1 flex-col items-center justify-center gap-1 text-[11.5px] font-medium transition ${
            active ? 'text-accent' : 'text-fg-muted'
        }`;
    }
</script>

<nav
    aria-label={t('Main')}
    class="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-surface px-2 pb-[env(safe-area-inset-bottom)] md:hidden"
>
    {#each TABS as item (item.href)}
        {@const active = !meOpen && tab === item.href}
        {@const Icon = item.icon}
        <a
            href={item.href}
            use:inertia
            aria-current={active ? 'page' : undefined}
            aria-label={item.href === '/workspace/notifications' && unread > 0
                ? t('Updates, :count unread', { count: unread })
                : undefined}
            class={tabClass(active)}
        >
            <span class="relative inline-flex">
                <Icon class="h-[20px] w-[20px]" />
                {#if item.href === '/workspace/notifications' && unread > 0}
                    <span
                        class="absolute -top-[4px] -right-[8px] h-[16px] min-w-[16px] rounded-full bg-accent px-[4px] text-center text-[10.5px] leading-[16px] font-bold text-white tabular-nums"
                        >{unread > 99 ? `${number(99)}+` : number(unread)}</span
                    >
                {/if}
            </span>
            {t(item.label)}
        </a>
    {/each}
    <button
        bind:this={meButton}
        type="button"
        aria-label={t('Me')}
        aria-haspopup="dialog"
        aria-expanded={meOpen}
        class={tabClass(meHere)}
        onclick={() => (meOpen = !meOpen)}
    >
        <Avatar
            name={user?.name}
            size="md"
            class={meHere ? 'border-accent text-accent' : ''}
        />
        {t('Me')}
    </button>
</nav>

<AccountMenu
    variant="sheet"
    bind:open={meOpen}
    {groups}
    {path}
    {user}
    {role}
    {extra}
    {organizationSwitch}
    {onnewtask}
    {onsearch}
    {onsettings}
    {onlogout}
    onclose={() => meButton?.focus()}
/>
