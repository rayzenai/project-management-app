<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        Copy,
        ExternalLink,
        GitBranch,
        GitPullRequest,
        Link,
        Unlink,
    } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { writeToClipboard } from '../../lib/clipboard';
    import { formatTimeAgo } from '../../lib/format';
    import { toast } from '../../lib/toast.svelte';
    import type {
        GitRepository,
        Project,
        ProjectGit,
        RepositoryCandidate,
    } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * A project's Code tab: its linked forge repositories, how to clone each
     * one and its open pull requests. Whoever runs the project links and
     * unlinks them; unlinking leaves the repository on the forge.
     */
    let {
        project,
        git,
    }: {
        project: Project;
        git: ProjectGit;
    } = $props();

    /** The most candidates the server returns for one search. */
    const CANDIDATE_LIMIT = 50;
    const FAILED = "The forge's repositories didn't load.";

    const uid = $props.id();
    let linkOpen = $state(false);
    let query = $state('');
    // What the forge offered when the popover opened.
    let candidates = $state<RepositoryCandidate[] | null>(null);
    // The forge's answer to what was typed, once that first list was cut short.
    let searched = $state<RepositoryCandidate[] | null>(null);
    let searching = $state(false);
    let loadError = $state<string | null>(null);
    let linkError = $state<string | null>(null);
    let linking = $state<string | null>(null);
    let seq = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const base = $derived(`/workspace/projects/${project.slug}/repositories`);
    const example = $derived(`${project.code ?? 'TP'}-12`);
    // A full first list may be cut short, so typing asks the forge instead.
    const capped = $derived((candidates?.length ?? 0) >= CANDIDATE_LIMIT);

    const matches = $derived.by(() => {
        const q = query.trim().toLowerCase();

        if (capped && q !== '') {
            return searched ?? [];
        }

        return (candidates ?? []).filter(
            (c) =>
                q === '' ||
                c.full_name.toLowerCase().includes(q) ||
                (c.description ?? '').toLowerCase().includes(q),
        );
    });

    // Every candidate search asks the forge, so ask once per opening and
    // filter what came back as the person types.
    $effect(() => {
        if (linkOpen) {
            untrack(() => void load());
        }
    });

    async function fetchCandidates(q: string): Promise<RepositoryCandidate[]> {
        const response = await fetch(
            `${base}/candidates?q=${encodeURIComponent(q)}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const json = (await response.json().catch(() => ({}))) as {
            data?: RepositoryCandidate[];
            message?: string;
        };

        if (!response.ok) {
            throw new Error(json.message ?? FAILED);
        }

        return json.data ?? [];
    }

    async function load() {
        const mine = ++seq;
        clearTimeout(timer);
        candidates = searched = null;
        loadError = linkError = null;
        searching = false;
        query = '';

        try {
            const list = await fetchCandidates('');

            if (mine === seq) {
                candidates = list;
            }
        } catch (e) {
            if (mine === seq) {
                loadError = e instanceof Error ? e.message : FAILED;
            }
        }
    }

    /** Past the first list, what was typed goes to the forge (debounced). */
    function search() {
        clearTimeout(timer);
        const q = query.trim();

        // The whole list is here: the browser filters it.
        if (!capped) {
            return;
        }

        // Back to the first list; drop any answer still on its way.
        if (q === '') {
            seq++;
            searched = null;
            searching = false;

            return;
        }

        searching = true;
        timer = setTimeout(async () => {
            const mine = ++seq;

            try {
                const list = await fetchCandidates(q);

                if (mine === seq) {
                    searched = list;
                    loadError = null;
                }
            } catch (e) {
                if (mine === seq) {
                    loadError = e instanceof Error ? e.message : FAILED;
                }
            } finally {
                if (mine === seq) {
                    searching = false;
                }
            }
        }, 250);
    }

    function link(candidate: RepositoryCandidate) {
        linking = candidate.full_name;
        linkError = null;
        router.post(
            base,
            { full_name: candidate.full_name },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (linkOpen = false),
                // The toast says it too; this keeps it beside the search.
                onError: (errors) =>
                    (linkError =
                        errors.full_name ??
                        errors.__global ??
                        "That repository couldn't be linked."),
                onFinish: () => (linking = null),
            },
        );
    }

    function unlink(repository: GitRepository) {
        if (
            !confirm(
                `Unlink ${repository.full_name}? Its commits and pull requests leave this project's tasks. The repository stays on the forge.`,
            )
        ) {
            return;
        }

        router.delete(`${base}/${repository.id}`, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function copy(address: string, kind: string) {
        writeToClipboard(address);
        toast.show(`Copied the ${kind} address`, { duration: 2000 });
    }
</script>

<section class="max-w-3xl">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="section-title mb-1">
                Repositories <span class="section-count"
                    >{git.repositories.length}</span
                >
            </h2>
            <p class="text-xs text-fg-muted">
                Start a branch or write a commit with a task's code, such as {example},
                and it shows on that task.
            </p>
        </div>
        {#if git.can_manage}
            <Popover
                bind:open={linkOpen}
                role="dialog"
                align="right"
                triggerClass="btn shrink-0"
                panelClass="w-80"
            >
                {#snippet trigger()}
                    <Link class="h-3.5 w-3.5" />Link repository
                {/snippet}

                <div class="px-1 pt-1 pb-1.5">
                    <label for="{uid}-q" class="sr-only"
                        >Find a repository on the forge</label
                    >
                    <input
                        id="{uid}-q"
                        type="search"
                        autocomplete="off"
                        bind:value={query}
                        oninput={search}
                        placeholder="Find a repository"
                        class="input"
                    />
                    {#if linkError}
                        <p role="alert" class="mt-1 px-1 text-xs text-danger">
                            {linkError}
                        </p>
                    {/if}
                </div>
                <div class="max-h-64 overflow-auto pb-1">
                    {#if loadError}
                        <div class="px-2 py-1.5">
                            <p class="text-xs text-danger">{loadError}</p>
                            <button
                                type="button"
                                class="btn-ghost mt-1 -ml-2"
                                onclick={() =>
                                    candidates === null
                                        ? void load()
                                        : search()}>Try again</button
                            >
                        </div>
                    {:else if candidates === null || (capped && query.trim() !== '' && searched === null)}
                        <p
                            class="flex items-center gap-2 px-2 py-1.5 text-xs text-fg-muted"
                        >
                            <Spinner size={14} />Asking the forge
                        </p>
                    {:else}
                        {#each matches as candidate (candidate.full_name)}
                            <button
                                type="button"
                                data-popover-item
                                class="menu-item"
                                disabled={linking !== null}
                                onclick={() => link(candidate)}
                            >
                                <span class="min-w-0 flex-1 leading-tight">
                                    <span
                                        class="block truncate font-medium text-fg"
                                        >{candidate.full_name}</span
                                    >
                                    {#if candidate.description}
                                        <span
                                            class="block truncate text-xs text-fg-muted"
                                            >{candidate.description}</span
                                        >
                                    {/if}
                                </span>
                                {#if linking === candidate.full_name}
                                    <Spinner size={14} label="Linking" />
                                {/if}
                            </button>
                        {:else}
                            <p class="px-2 py-1.5 text-xs text-fg-faint">
                                {candidates.length === 0
                                    ? 'No repository on the forge is left to link.'
                                    : 'No repository matches.'}
                            </p>
                        {/each}
                        {#if capped && query.trim() === ''}
                            <p class="px-2 pt-1 text-xs text-fg-faint">
                                Showing the first {CANDIDATE_LIMIT}. Type to
                                search them all.
                            </p>
                        {:else if searching}
                            <p
                                class="flex items-center gap-2 px-2 pt-1 text-xs text-fg-faint"
                            >
                                <Spinner size={12} />Searching
                            </p>
                        {/if}
                    {/if}
                </div>
            </Popover>
        {/if}
    </div>

    {#if git.repositories.length === 0}
        <p class="mt-3 text-[13px] text-fg-muted">
            No repositories linked yet.{git.can_manage
                ? ' Link one to see its commits and pull requests on tasks.'
                : ''}
        </p>
    {:else}
        <ul class="mt-3 border-t border-line">
            {#each git.repositories as repository (repository.id)}
                {@const pulls = repository.open_pull_requests ?? []}
                {@const openCount =
                    repository.open_pull_requests_count ?? pulls.length}
                <li class="border-b border-line py-3">
                    <div class="flex min-h-7 items-center gap-2">
                        <a
                            href={repository.html_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex min-w-0 items-center gap-1.5 font-medium text-fg hover:text-accent"
                        >
                            <span class="truncate">{repository.full_name}</span>
                            <ExternalLink
                                class="h-3 w-3 shrink-0 text-fg-faint"
                            />
                        </a>
                        <span class="flex-1"></span>
                        {#if git.can_manage}
                            <button
                                type="button"
                                class="btn-ghost shrink-0"
                                onclick={() => unlink(repository)}
                                ><Unlink class="h-3.5 w-3.5" />Unlink</button
                            >
                        {/if}
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-muted"
                    >
                        <span class="inline-flex items-center gap-1">
                            <GitBranch class="h-3 w-3 text-fg-faint" />
                            <span class="font-mono"
                                >{repository.default_branch}</span
                            >
                        </span>
                        <span
                            >{repository.last_pushed_at
                                ? `Last push ${formatTimeAgo(repository.last_pushed_at)}`
                                : 'No pushes yet'}</span
                        >
                    </div>

                    <dl
                        class="mt-2 grid grid-cols-[3rem_1fr_auto] items-center gap-x-2"
                    >
                        {#each [{ kind: 'HTTPS', address: repository.clone_url }, { kind: 'SSH', address: repository.ssh_url }] as clone (clone.kind)}
                            <dt class="text-xs text-fg-faint">{clone.kind}</dt>
                            <dd
                                class="min-w-0 truncate font-mono text-xs text-fg-muted"
                            >
                                {clone.address}
                            </dd>
                            <dd>
                                <button
                                    type="button"
                                    class="btn-icon h-6 w-6"
                                    title={`Copy the ${clone.kind} address`}
                                    aria-label={`Copy the ${clone.kind} address of ${repository.full_name}`}
                                    onclick={() =>
                                        copy(clone.address, clone.kind)}
                                    ><Copy class="h-3.5 w-3.5" /></button
                                >
                            </dd>
                        {/each}
                    </dl>

                    <h3 class="mt-3 text-xs font-medium text-fg-muted">
                        Open pull requests <span class="section-count"
                            >{openCount}</span
                        >
                    </h3>
                    {#if pulls.length > 0}
                        <ul class="mt-0.5">
                            {#each pulls as pull (pull.id)}
                                <li
                                    class="flex min-h-7 items-center gap-2 text-[12.5px]"
                                >
                                    <GitPullRequest
                                        class="h-3.5 w-3.5 shrink-0 text-accent"
                                    />
                                    <span
                                        class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                                        >#{pull.number}</span
                                    >
                                    <a
                                        href={pull.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="min-w-0 flex-1 truncate text-fg hover:text-accent"
                                        >{pull.title}</a
                                    >
                                    <span
                                        class="hidden max-w-48 shrink-0 truncate font-mono text-[11px] text-fg-faint sm:inline"
                                        >{pull.head_branch}</span
                                    >
                                    {#if pull.author_username}
                                        <span
                                            class="shrink-0 text-xs text-fg-muted"
                                            >{pull.author_username}</span
                                        >
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                        {#if openCount > pulls.length}
                            <a
                                href={`${repository.html_url}/pulls`}
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-0.5 inline-flex items-center gap-1 text-xs text-fg-muted hover:text-accent"
                                >The newest {pulls.length} of {openCount}; see
                                them all on the forge<ExternalLink
                                    class="h-3 w-3"
                                /></a
                            >
                        {/if}
                    {:else}
                        <p class="mt-0.5 text-xs text-fg-faint">None open.</p>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}
</section>
