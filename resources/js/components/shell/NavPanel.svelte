<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        Building2,
        Check,
        ChevronsUpDown,
        Folder,
        FolderTree,
        PanelLeft,
        Plus,
        Search,
        X,
    } from '@lucide/svelte';
    import type { NavArea, NavLink } from '../../lib/navigation';
    import type { Id, OrganizationSwitch } from '../../lib/types';
    import Popover from '../Popover.svelte';

    /**
     * The panel beside the rail: the shown area's title, New task and
     * Search in the same place for every area, then the area. Work lists
     * your pages, the programs you manage and every project, filterable
     * once there are many; the other areas list their pages, each with a
     * line on what it is for. A super-admin's organization switch sits under
     * the title: it picks whose projects are listed and which organization
     * the office pages open on.
     */
    let {
        area,
        current,
        path,
        projects,
        programs,
        organizationSwitch = null,
        mobile,
        floating,
        onnewtask,
        onsearch,
        ontoggle,
        onclose,
    }: {
        area: NavArea;
        /** The link of the page the user is on. */
        current: string | null;
        path: string;
        projects: {
            id: number;
            slug: string;
            title: string;
            program?: string | null;
        }[];
        programs: { slug: string; name: string }[];
        organizationSwitch?: OrganizationSwitch | null;
        /** In the phone drawer: a close button instead of hide. */
        mobile: boolean;
        /** Over the content while the panel is hidden: its button keeps it. */
        floating: boolean;
        onnewtask: () => void;
        onsearch: () => void;
        /** Hides the panel, or keeps it when floating. */
        ontoggle: () => void;
        onclose: () => void;
    } = $props();

    /** A filter once the list is longer than a glance. */
    const FILTER_FROM = 9;

    let query = $state('');
    let switchOpen = $state(false);

    const switchChoices = $derived(
        organizationSwitch
            ? [
                  { id: null as Id | null, name: 'All organizations' },
                  ...organizationSwitch.organizations,
              ]
            : [],
    );
    const switchedName = $derived(
        organizationSwitch?.organizations.find(
            (organization) => organization.id === organizationSwitch.current,
        )?.name ?? 'All organizations',
    );

    function switchTo(id: Id | null) {
        switchOpen = false;

        if (id === (organizationSwitch?.current ?? null)) {
            return;
        }

        router.put(
            '/workspace/organization',
            { organization: id ?? 'all' },
            { preserveScroll: true },
        );
    }

    /**
     * Forgiving, as a filter should be: the projects holding what was typed
     * come first, then those holding its letters in order ("pms" finds
     * PMIS), each group in list order.
     */
    const matching = $derived.by(() => {
        const needle = query.trim().toLowerCase().replace(/\s+/g, ' ');

        if (needle === '') {
            return projects;
        }

        const inOrder = (text: string) => {
            let at = 0;

            for (const ch of text) {
                if (ch === needle[at] && ++at === needle.length) {
                    return true;
                }
            }

            return false;
        };
        const text = (p: (typeof projects)[number]) =>
            `${p.title} ${p.program ?? ''}`.toLowerCase();

        return [
            ...projects.filter((p) => text(p).includes(needle)),
            ...projects.filter(
                (p) => !text(p).includes(needle) && inOrder(text(p)),
            ),
        ];
    });

    function projectHref(slug: string): string {
        return `/workspace/projects/${slug}`;
    }

    function onFilterKey(e: KeyboardEvent) {
        if (e.key === 'Enter' && matching.length > 0) {
            e.preventDefault();
            router.visit(projectHref(matching[0].slug));
        } else if (e.key === 'Escape' && query !== '') {
            e.stopPropagation();
            query = '';
        }
    }

    function linkClass(active: boolean): string {
        return `flex items-center gap-2.5 rounded-md px-2.5 transition ${
            active
                ? 'bg-accent-soft text-accent'
                : 'text-fg-muted hover:bg-hover hover:text-fg'
        }`;
    }
</script>

{#snippet badge(n: number | undefined)}
    {#if (n ?? 0) > 0}
        <span
            class="ml-auto rounded-full bg-accent px-1.5 text-[11px] leading-4 font-medium text-white tabular-nums"
            >{(n ?? 0) > 99 ? '99+' : n}</span
        >
    {/if}
{/snippet}

{#snippet page(link: NavLink)}
    {@const active = current === link.href}
    {@const Icon = link.icon}
    <a
        href={link.href}
        use:inertia
        aria-current={active ? 'page' : undefined}
        class={`${linkClass(active)} py-1.5 font-medium`}
    >
        <Icon
            class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
        />
        <span class="truncate">{link.label}</span>
        {@render badge(link.count)}
    </a>
{/snippet}

<div class="flex h-full w-56 flex-col px-2.5 pt-3 pb-2">
    <div class="flex h-7 items-center gap-2 pl-1">
        <h2 class="truncate text-[15px] font-semibold text-fg">
            {area.label}
        </h2>
        {#if mobile}
            <button
                type="button"
                class="btn-icon ml-auto h-7 w-7"
                aria-label="Close menu"
                onclick={onclose}
            >
                <X class="h-[15px] w-[15px]" />
            </button>
        {:else}
            <button
                type="button"
                class="btn-icon ml-auto h-7 w-7"
                title={floating
                    ? 'Keep the panel open ([)'
                    : 'Hide the panel ([)'}
                aria-label={floating ? 'Keep the panel open' : 'Hide the panel'}
                onclick={ontoggle}
            >
                <PanelLeft class="h-[15px] w-[15px]" />
            </button>
        {/if}
    </div>

    {#if organizationSwitch}
        <div class="mt-2">
            <Popover
                bind:open={switchOpen}
                role="listbox"
                triggerLabel={`Organization: ${switchedName}`}
                wrapperClass="w-full"
                triggerClass="flex h-8 w-full items-center gap-2 rounded-md border border-line bg-surface px-2.5 text-[13px] text-fg transition hover:bg-hover"
                panelClass="w-52"
            >
                {#snippet trigger()}
                    <Building2
                        class="h-[15px] w-[15px] shrink-0 text-fg-faint"
                    />
                    <span class="min-w-0 flex-1 truncate text-left"
                        >{switchedName}</span
                    >
                    <ChevronsUpDown
                        class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                    />
                {/snippet}
                <div class="py-1">
                    {#each switchChoices as choice (choice.id ?? 'all')}
                        {@const selected =
                            choice.id === (organizationSwitch.current ?? null)}
                        <button
                            type="button"
                            role="option"
                            aria-selected={selected}
                            class={`menu-item ${selected ? 'text-fg' : ''}`}
                            onclick={() => switchTo(choice.id)}
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
            </Popover>
        </div>
    {/if}

    <div class="mt-3 flex gap-1.5">
        <button
            type="button"
            class="btn-primary h-8 flex-1 justify-center"
            onclick={onnewtask}
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
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-line bg-surface text-fg-muted transition hover:bg-hover hover:text-fg"
            title="Search (⌘K)"
            aria-label="Search"
            onclick={onsearch}
        >
            <Search class="h-[15px] w-[15px]" />
        </button>
    </div>

    {#if area.key === 'work'}
        <nav aria-label="Your pages" class="mt-3 flex flex-col gap-0.5">
            {#each area.links as link (link.href)}
                {@render page(link)}
            {/each}
        </nav>

        {#if programs.length > 0}
            <div
                class="mt-4 mb-1 flex h-6 items-center gap-2 px-2.5 text-xs font-medium text-fg-faint"
            >
                Programs
                <span class="tabular-nums">{programs.length}</span>
            </div>
            <nav aria-label="Programs" class="flex flex-col gap-0.5">
                {#each programs as program (program.slug)}
                    {@const href = `/workspace/programs/${program.slug}`}
                    {@const active = path.startsWith(href)}
                    <a
                        {href}
                        use:inertia
                        aria-current={active ? 'page' : undefined}
                        class={`${linkClass(active)} py-1.5`}
                    >
                        <FolderTree
                            class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                        />
                        <span class="truncate">{program.name}</span>
                    </a>
                {/each}
            </nav>
        {/if}

        <div
            class="mt-4 mb-1 flex h-6 items-center gap-2 pl-2.5 text-xs font-medium text-fg-faint"
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
        {#if projects.length >= FILTER_FROM}
            <label class="relative mb-1.5 block">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                />
                <input
                    type="search"
                    class="input h-7 py-0 pl-8 text-xs"
                    placeholder="Filter projects"
                    aria-label="Filter projects"
                    bind:value={query}
                    onkeydown={onFilterKey}
                />
            </label>
        {/if}
        <nav
            aria-label="Projects"
            class="-mx-1 flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-1"
        >
            {#each matching as project (project.id)}
                {@const href = projectHref(project.slug)}
                {@const active = path.startsWith(href)}
                <a
                    {href}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    title={project.program
                        ? `${project.title} (${project.program})`
                        : project.title}
                    class={`${linkClass(active)} py-1.5`}
                >
                    <Folder
                        class={`h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    <span class="truncate"
                        >{project.title}{#if project.program}
                            <span class="ml-1 text-fg-faint"
                                >{project.program}</span
                            >{/if}</span
                    >
                </a>
            {:else}
                <p class="px-2.5 py-1 text-xs text-fg-faint">
                    {query.trim() === ''
                        ? 'No projects yet'
                        : `No project matches "${query.trim()}"`}
                </p>
            {/each}
        </nav>
    {:else}
        <nav aria-label={area.label} class="mt-3 flex flex-col gap-0.5">
            {#each area.links as link (link.href)}
                {@const active = current === link.href}
                {@const Icon = link.icon}
                <a
                    href={link.href}
                    use:inertia
                    aria-current={active ? 'page' : undefined}
                    class={`${linkClass(active)} items-start py-2`}
                >
                    <Icon
                        class={`mt-px h-[15px] w-[15px] shrink-0 ${active ? 'text-accent' : 'text-fg-faint'}`}
                    />
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate font-medium"
                            >{link.label}</span
                        >
                        {#if link.hint}
                            <span
                                class="mt-0.5 block truncate text-xs text-fg-faint"
                                >{link.hint}</span
                            >
                        {/if}
                    </span>
                    {@render badge(link.count)}
                </a>
            {/each}
        </nav>
    {/if}
</div>
