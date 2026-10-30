<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Search, X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import type {
        GitRepository,
        Id,
        RepositoryAccess,
        RepositoryGrant,
    } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * Who may pull and push a linked repository on the forge, under its row
     * on the Code tab: its maintainers, whose access follows their role, and
     * the people given read or write access, whom whoever runs the project
     * adds, changes and removes here. Every change goes through the server,
     * which answers with a flash; the list is then asked for again.
     */
    let {
        repository,
        url,
    }: {
        repository: GitRepository;
        /** The repository's access route. */
        url: string;
    } = $props();

    interface Candidate {
        id: Id;
        name: string;
        title: string | null;
    }

    type Permission = RepositoryGrant['permission'];

    const uid = $props.id();
    const WAITING = "Hasn't signed in to SDOC Git yet";

    let access = $state<RepositoryAccess | null>(null);
    let failed = $state(false);
    let saving = $state(false);
    let error = $state<string | null>(null);
    let seq = 0;

    let query = $state('');
    let permission = $state<Permission>('write');
    let results = $state<Candidate[]>([]);
    let searched = $state(false);
    let searchFailed = $state(false);
    let adding = $state<Id | null>(null);
    let searchSeq = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    async function load(address: string) {
        const mine = ++seq;
        failed = false;

        try {
            const response = await fetch(address, {
                headers,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as { data: RepositoryAccess };

            if (mine === seq) {
                access = json.data;
            }
        } catch {
            if (mine === seq) {
                failed = true;
            }
        }
    }

    $effect(() => {
        const address = url;
        untrack(() => void load(address));
    });

    /**
     * People who could be given access, asked of the server as the person
     * types (debounced); a stale answer never overwrites a newer one.
     */
    function search() {
        clearTimeout(timer);
        const q = query.trim();

        if (q.length < 2) {
            searchSeq++;
            results = [];
            searched = searchFailed = false;

            return;
        }

        timer = setTimeout(async () => {
            const mine = ++searchSeq;

            try {
                const response = await fetch(
                    `${url}/candidates?q=${encodeURIComponent(q)}`,
                    { headers, credentials: 'same-origin' },
                );

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const json = (await response.json()) as { data: Candidate[] };

                if (mine === searchSeq) {
                    results = json.data;
                    searched = true;
                    searchFailed = false;
                }
            } catch {
                if (mine === searchSeq) {
                    results = [];
                    searched = false;
                    searchFailed = true;
                }
            }
        }, 200);
    }

    /**
     * One change at a time. Nothing on the page changes but the flash, so
     * only that is reloaded; the list is asked for again either way.
     */
    function visit(after?: () => void, refused?: () => void) {
        saving = true;
        error = null;

        return {
            only: ['flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => after?.(),
            onError: (errors: Record<string, string>) => {
                error =
                    errors.member_id ??
                    errors.permission ??
                    errors.__global ??
                    "That change didn't save.";
                refused?.();
            },
            onFinish: () => {
                saving = false;
                adding = null;
                void load(url);
            },
        };
    }

    function add(candidate: Candidate) {
        adding = candidate.id;
        router.post(
            url,
            { member_id: candidate.id, permission },
            visit(() => {
                query = '';
                search();
            }),
        );
    }

    /** A refused change puts the select back as it was. */
    function change(grant: RepositoryGrant, select: HTMLSelectElement) {
        router.patch(
            `${url}/${grant.id}`,
            { permission: select.value },
            visit(undefined, () => (select.value = grant.permission)),
        );
    }

    function remove(grant: RepositoryGrant) {
        router.delete(`${url}/${grant.id}`, visit());
    }
</script>

{#snippet person(name: string, title: string | null, waiting: boolean)}
    <Avatar {name} size="md" />
    <div class="min-w-0 flex-1 leading-tight">
        <div class="truncate font-medium text-fg">{name}</div>
        {#if title}
            <div class="truncate text-xs text-fg-muted">{title}</div>
        {/if}
        {#if waiting}
            <div class="text-xs text-warn">{WAITING}</div>
        {/if}
    </div>
{/snippet}

<section
    class="mt-2 rounded-md border border-line px-3 py-2.5"
    aria-label={`Who can reach ${repository.full_name}`}
>
    {#if failed}
        <div class="mb-2">
            <p class="text-xs text-danger">Access didn't load.</p>
            <button
                type="button"
                class="btn-ghost mt-1 -ml-2"
                onclick={() => void load(url)}>Try again</button
            >
        </div>
    {/if}

    {#if access === null}
        {#if !failed}
            <p class="flex items-center gap-2 text-xs text-fg-muted">
                <Spinner size={14} />Loading
            </p>
        {/if}
    {:else}
        <h3 class="text-xs font-medium text-fg-muted">Maintainers</h3>
        {#if access.maintainers.length === 0}
            <p class="mt-0.5 text-xs text-fg-faint">
                None: the project has no manager.
            </p>
        {:else}
            <ul>
                {#each access.maintainers as maintainer (maintainer.id)}
                    <li class="flex min-h-10 items-center gap-2.5 py-1">
                        {@render person(
                            maintainer.name,
                            // The role first: on a phone the title gives way.
                            [
                                maintainer.role === 'manager'
                                    ? 'Manager'
                                    : 'Program manager',
                                maintainer.title,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                            maintainer.waiting,
                        )}
                        <span class="shrink-0 text-xs text-fg-muted"
                            >Manages the repository</span
                        >
                    </li>
                {/each}
            </ul>
        {/if}

        <h3 class="mt-3 text-xs font-medium text-fg-muted">
            People with access <span class="section-count"
                >{access.grants.length}</span
            >
        </h3>
        {#if access.grants.length === 0}
            <p class="mt-0.5 text-xs text-fg-faint">Nobody else yet.</p>
        {:else}
            <ul>
                {#each access.grants as grant (grant.id)}
                    <li class="flex min-h-10 items-center gap-2.5 py-1">
                        {@render person(
                            grant.member.name,
                            grant.member.title,
                            grant.waiting,
                        )}
                        <select
                            class="input h-7 w-auto shrink-0 py-0"
                            aria-label={`${grant.member.name}'s access`}
                            value={grant.permission}
                            disabled={saving}
                            onchange={(e) => change(grant, e.currentTarget)}
                        >
                            <option value="read">Read</option>
                            <option value="write">Write</option>
                        </select>
                        <button
                            type="button"
                            class="btn-icon shrink-0 disabled:opacity-50"
                            title="Remove access"
                            aria-label={`Remove ${grant.member.name}'s access`}
                            disabled={saving}
                            onclick={() => remove(grant)}
                            ><X class="h-3.5 w-3.5" /></button
                        >
                    </li>
                {/each}
            </ul>
        {/if}

        <label
            for="{uid}-q"
            class="mt-3 block text-xs font-medium text-fg-muted"
            >Add someone</label
        >
        <div class="mt-1 flex gap-2">
            <div class="relative min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                />
                <input
                    id="{uid}-q"
                    type="search"
                    autocomplete="off"
                    bind:value={query}
                    oninput={search}
                    placeholder="Name or email"
                    class="input pl-8"
                />
            </div>
            <select
                class="input w-auto shrink-0"
                aria-label="Access to give"
                bind:value={permission}
            >
                <option value="read">Read</option>
                <option value="write">Write</option>
            </select>
        </div>
        {#if results.length > 0}
            <ul class="mt-1 rounded-md border border-line">
                {#each results as candidate (candidate.id)}
                    <li
                        class="flex min-h-10 items-center gap-2.5 border-b border-line-soft px-2 py-1 last:border-b-0"
                    >
                        {@render person(candidate.name, candidate.title, false)}
                        <button
                            type="button"
                            class="btn-ghost shrink-0"
                            disabled={saving}
                            onclick={() => add(candidate)}
                            >{adding === candidate.id
                                ? 'Adding'
                                : 'Add'}</button
                        >
                    </li>
                {/each}
            </ul>
        {:else if searchFailed}
            <p class="mt-1 text-xs text-danger">The search didn't load.</p>
        {:else if searched}
            <p class="mt-1 text-xs text-fg-muted">
                Nobody matches who can be given access.
            </p>
        {/if}

        {#if error}
            <p role="alert" class="mt-2 text-xs text-danger">{error}</p>
        {/if}

        <p class="mt-3 text-xs text-fg-faint">
            Organizational admins and super-admins reach every repository.
        </p>
    {/if}
</section>
