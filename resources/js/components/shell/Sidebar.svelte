<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import { ChevronsUpDown, Plus, Search } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import { forgivingFilter } from '../../lib/forgivingFilter';
    import { number, t } from '../../lib/i18n';
    import { currentHref, under } from '../../lib/navigation';
    import type { NavGroup, NavLink } from '../../lib/navigation';
    import type { OrganizationSwitch } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import AccountMenu from './AccountMenu.svelte';
    import Logo from './Logo.svelte';
    import OrganizationSwitcher from './OrganizationSwitcher.svelte';

    /**
     * The one sidebar, from 768px: the organization (a super-admin's
     * switch), New task and Search, the few pages everyone uses, the
     * programs you manage and the projects, then your account at the
     * bottom, which opens the menu with everything else. The project list
     * scrolls inside it, filterable once there are many.
     */
    let {
        links,
        groups,
        path,
        projects,
        programs,
        organizationName,
        organizationSwitch = null,
        user,
        role,
        onnewtask,
        onsearch,
        onsettings,
        onlogout,
    }: {
        links: NavLink[];
        groups: NavGroup[];
        path: string;
        projects: {
            id: number;
            slug: string;
            title: string;
            program?: string | null;
        }[];
        programs: { slug: string; name: string }[];
        /** The viewer's organization, named at the top for everyone else. */
        organizationName: string;
        organizationSwitch?: OrganizationSwitch | null;
        user: { name: string; email?: string } | null;
        role: string;
        onnewtask: () => void;
        onsearch: () => void;
        onsettings: () => void;
        onlogout: () => void;
    } = $props();

    /** A filter once the list is longer than a glance. */
    const FILTER_FROM = 9;

    let query = $state('');
    let accountOpen = $state(false);
    let list = $state<HTMLElement | null>(null);

    function projectHref(slug: string): string {
        return `/workspace/projects/${slug}`;
    }

    function programHref(slug: string): string {
        return `/workspace/programs/${slug}`;
    }

    // On a project or program the list lights it, not Projects above.
    const inList = $derived(
        projects.some((p) => under(path, projectHref(p.slug))) ||
            programs.some((p) => under(path, programHref(p.slug))),
    );
    const current = $derived(inList ? null : currentHref(path, links));

    /** Forgiving, as a filter should be ("pms" finds PMIS). */
    const matching = $derived(
        forgivingFilter(
            projects,
            query,
            (p) => `${p.title} ${p.program ?? ''}`,
        ),
    );

    function onFilterKey(e: KeyboardEvent) {
        if (e.key === 'Enter' && matching.length > 0) {
            e.preventDefault();
            router.visit(projectHref(matching[0].slug));
        } else if (e.key === 'Escape' && query !== '') {
            e.stopPropagation();
            query = '';
        }
    }

    // Every page draws the sidebar afresh: keep the open project in view.
    onMount(() => {
        list?.querySelector('[aria-current="page"]')?.scrollIntoView({
            block: 'nearest',
        });
    });

    function rowClass(active: boolean): string {
        return `flex h-[36px] w-full items-center gap-[10px] rounded-lg px-[10px] text-left text-[14px] font-medium transition ${
            active ? 'bg-accent-soft text-accent' : 'text-fg hover:bg-hover'
        }`;
    }

    function subClass(active: boolean): string {
        return `flex h-[32px] w-full shrink-0 items-center rounded-lg pr-[10px] pl-[36px] text-[13.5px] transition ${
            active
                ? 'bg-accent-soft text-accent'
                : 'text-fg-muted hover:bg-hover hover:text-fg'
        }`;
    }

    const headClass =
        'flex h-7 items-center gap-2 pr-1 pl-[10px] text-[12.5px] font-medium text-fg-faint';
</script>

{#snippet accountButton()}
    {#if user}
        <Avatar name={user.name} size="lg" />
        <span class="min-w-0 flex-1 leading-tight">
            <span class="block truncate text-[13.5px] font-medium text-fg"
                >{user.name}</span
            >
            <span class="mt-0.5 block truncate text-[12px] text-fg-muted"
                >{role}</span
            >
        </span>
        <ChevronsUpDown class="h-[16px] w-[16px] shrink-0 text-fg-faint" />
    {/if}
{/snippet}

<div
    class="flex h-full w-[240px] flex-col border-r border-line-soft bg-surface-alt px-3 pt-3.5 pb-2"
>
    <div class="mb-2 flex h-[40px] items-center gap-0.5">
        {#if organizationSwitch}
            <a
                href="/workspace/my"
                use:inertia
                class="shrink-0 rounded-md p-[7px] pr-[3px]"
                title={t('My work')}
                aria-label={t('My work')}
            >
                <Logo class="h-[26px] w-[26px]" />
            </a>
            <OrganizationSwitcher {organizationSwitch} />
        {:else}
            <a
                href="/workspace/my"
                use:inertia
                class="flex h-[40px] min-w-0 flex-1 items-center gap-[10px] rounded-lg px-[7px] text-[14.5px] font-semibold text-fg"
                title={organizationName}
            >
                <Logo class="h-[26px] w-[26px]" />
                <span class="truncate">{organizationName}</span>
            </a>
        {/if}
    </div>

    <button
        type="button"
        class="btn-primary mb-2 h-[34px] w-full justify-center"
        onclick={onnewtask}
    >
        <Plus class="h-4 w-4" />
        {t('New task')}
        <kbd class="kbd ml-auto border-white/30 bg-transparent text-white/80"
            >N</kbd
        >
    </button>

    <nav aria-label={t('Main')} class="flex flex-col gap-0.5">
        <button
            type="button"
            class="flex h-[36px] w-full items-center gap-[10px] rounded-lg px-[10px] text-left text-[14px] text-fg-muted transition hover:bg-hover hover:text-fg"
            title={t('Search (/)')}
            onclick={onsearch}
        >
            <Search class="h-[16px] w-[16px] shrink-0" />
            <span class="flex-1">{t('Search')}</span>
            <kbd class="kbd">/</kbd>
        </button>
        {#each links as link (link.href)}
            {@const active = current === link.href}
            {@const Icon = link.icon}
            <a
                href={link.href}
                use:inertia
                aria-current={active ? 'page' : undefined}
                class={rowClass(active)}
            >
                <Icon
                    class={`h-[16px] w-[16px] shrink-0 ${active ? '' : 'text-fg-muted'}`}
                />
                <span class="min-w-0 flex-1 truncate">{t(link.label)}</span>
            </a>
        {/each}
    </nav>

    <div
        bind:this={list}
        class="-mx-3 mt-4 flex min-h-0 flex-1 flex-col overflow-y-auto px-3"
    >
        {#if programs.length > 0}
            <div class={headClass}>
                {t('Programs')}
                <span class="tabular-nums">{number(programs.length)}</span>
            </div>
            <nav aria-label={t('Programs')} class="mb-3 flex flex-col gap-0.5">
                {#each programs as program (program.slug)}
                    {@const href = programHref(program.slug)}
                    {@const active = under(path, href)}
                    <a
                        {href}
                        use:inertia
                        aria-current={active ? 'page' : undefined}
                        class={subClass(active)}
                    >
                        <span class="truncate">{program.name}</span>
                    </a>
                {/each}
            </nav>
        {/if}

        <!-- Stays in view while the list scrolls under it. -->
        <div class="sticky top-0 z-10 bg-surface-alt pb-1">
            <div class={headClass}>
                {t('Projects')}
                <span class="tabular-nums">{number(projects.length)}</span>
                <a
                    href="/workspace/projects?new=1"
                    use:inertia
                    class="btn-icon ml-auto h-6 w-6"
                    title={t('New project')}
                    aria-label={t('New project')}
                >
                    <Plus class="h-3.5 w-3.5" />
                </a>
            </div>
            {#if projects.length >= FILTER_FROM}
                <label class="relative mt-0.5 block">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                    />
                    <input
                        type="search"
                        class="input h-[30px] py-0 pl-8 text-[13px]"
                        placeholder={t('Filter projects')}
                        aria-label={t('Filter projects')}
                        bind:value={query}
                        onkeydown={onFilterKey}
                    />
                </label>
            {/if}
        </div>
        <nav aria-label={t('Projects')} class="flex flex-col gap-0.5 pb-2">
            {#each matching as project (project.id)}
                {@const href = projectHref(project.slug)}
                {@const active = under(path, href)}
                <a
                    {href}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    title={project.program
                        ? `${project.title} (${project.program})`
                        : project.title}
                    class={subClass(active)}
                >
                    <span class="truncate"
                        >{project.title}{#if project.program}
                            <span class="ml-1 text-fg-faint"
                                >{project.program}</span
                            >{/if}</span
                    >
                </a>
            {:else}
                <p class="py-1 pl-[36px] text-[13px] text-fg-faint">
                    {query.trim() === ''
                        ? t('No projects yet')
                        : t('No project matches ":query"', {
                              query: query.trim(),
                          })}
                </p>
            {/each}
        </nav>
    </div>

    {#if user}
        <div class="-mx-1 border-t border-line pt-2">
            <AccountMenu
                variant="popover"
                bind:open={accountOpen}
                trigger={accountButton}
                triggerLabel={t('Account: :name', { name: user.name })}
                triggerClass="flex w-full items-center gap-[10px] rounded-lg px-2 py-1.5 text-left transition hover:bg-hover"
                {groups}
                {path}
                {user}
                {role}
                {onsettings}
                {onlogout}
            />
        </div>
    {/if}
</div>
