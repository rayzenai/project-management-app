<script lang="ts">
    import { ExternalLink, X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { fly } from 'svelte/transition';
    import type {
        CodeActivityCommit,
        CodeActivityPerson,
    } from '../../lib/types';
    import Avatar from '../Avatar.svelte';

    /**
     * One person's latest commits in the report's range and filters
     * (`query`, the report's own query string), from the person route.
     * Read-only; each commit links to the forge.
     */
    let {
        person,
        query,
        onclose,
    }: {
        person: CodeActivityPerson | null;
        query: string;
        onclose: () => void;
    } = $props();

    /** The person route answers at most this many. */
    const LIMIT = 50;

    let commits = $state<CodeActivityCommit[] | null>(null);
    let failed = $state(false);
    let panel = $state<HTMLElement | null>(null);
    let seq = 0;

    async function load(userId: number, search: string) {
        const mine = ++seq;
        failed = false;

        try {
            const response = await fetch(
                `/workspace/reports/code/people/${userId}?${search}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as {
                data: CodeActivityCommit[];
            };

            if (mine === seq) {
                commits = json.data;
            }
        } catch {
            if (mine === seq) {
                failed = true;
            }
        }
    }

    // Another person, or the filters changed: load afresh. Tracks the id,
    // not the row, which every visit rebuilds.
    const userId = $derived(person?.user_id ?? null);
    let wasOpen = false;

    $effect(() => {
        const id = userId;
        const search = query;

        untrack(() => {
            commits = null;

            if (id === null) {
                seq++;
                wasOpen = false;

                return;
            }

            void load(id, search);

            if (!wasOpen) {
                wasOpen = true;
                requestAnimationFrame(() => panel?.focus());
            }
        });
    });

    function onKey(event: KeyboardEvent) {
        if (person && event.key === 'Escape' && !event.defaultPrevented) {
            event.preventDefault();
            onclose();
        }
    }

    const number = new Intl.NumberFormat();
    const when = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    const NOT_COUNTED: Record<string, string> = {
        too_large: 'Too large to count',
        missing: 'Gone from the forge',
    };
</script>

<svelte:window onkeydown={onKey} />

{#if person}
    <aside
        bind:this={panel}
        tabindex="-1"
        aria-label={`${person.name}'s commits`}
        transition:fly={{ x: 40, duration: 180 }}
        class="fixed inset-y-0 right-0 z-40 flex w-[min(460px,94vw)] flex-col border-l border-line bg-surface outline-none"
    >
        <header
            class="flex h-11 shrink-0 items-center gap-2 border-b border-line px-4"
        >
            <Avatar name={person.name} size="md" />
            <span class="min-w-0 flex-1 truncate font-medium"
                >{person.name}</span
            >
            <button
                type="button"
                aria-label="Close"
                class="btn-icon"
                onclick={onclose}><X class="h-4 w-4" /></button
            >
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="border-b border-line px-5 py-3 text-[13px]">
                {#if person.title}
                    <div class="text-fg-muted">{person.title}</div>
                {/if}
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-fg-muted">
                    <span
                        ><span class="font-mono text-fg tabular-nums"
                            >{number.format(person.commits)}</span
                        >
                        {person.commits === 1 ? 'commit' : 'commits'}</span
                    >
                    <span
                        >Code <span class="font-mono text-fg tabular-nums"
                            >+{number.format(person.code_added)}</span
                        >
                        <span class="font-mono tabular-nums"
                            >&minus;{number.format(person.code_removed)}</span
                        ></span
                    >
                    <span
                        >Comments <span class="font-mono text-fg tabular-nums"
                            >+{number.format(person.comment_added)}</span
                        >
                        <span class="font-mono tabular-nums"
                            >&minus;{number.format(
                                person.comment_removed,
                            )}</span
                        ></span
                    >
                </div>
            </div>

            {#if failed}
                <div class="px-5 py-4 text-[13px] text-fg-muted">
                    The commits could not be loaded.
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => userId !== null && load(userId, query)}
                        >Try again</button
                    >
                </div>
            {:else if commits === null}
                <div class="space-y-2 px-5 py-4" aria-busy="true">
                    <div
                        class="h-3 w-2/3 animate-pulse rounded-sm bg-surface-alt"
                    ></div>
                    <div
                        class="h-3 w-1/2 animate-pulse rounded-sm bg-surface-alt"
                    ></div>
                    <div
                        class="h-3 w-3/5 animate-pulse rounded-sm bg-surface-alt"
                    ></div>
                </div>
            {:else if commits.length === 0}
                <p class="px-5 py-4 text-[13px] text-fg-muted">
                    No commits in this range.
                </p>
            {:else}
                <ul>
                    {#each commits as commit (commit.sha + commit.repository)}
                        <li class="border-b border-line-soft">
                            <a
                                href={commit.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                class="group block px-5 py-2.5 hover:bg-hover"
                            >
                                <span class="flex items-baseline gap-2">
                                    <span
                                        class="min-w-0 flex-1 truncate text-[13px] text-fg group-hover:text-accent"
                                        >{commit.message ||
                                            commit.sha.slice(0, 10)}</span
                                    >
                                    <ExternalLink
                                        class="h-3 w-3 shrink-0 self-center text-fg-faint"
                                    />
                                </span>
                                <span
                                    class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-fg-muted"
                                >
                                    <span class="truncate font-mono"
                                        >{commit.repository}</span
                                    >
                                    <span
                                        >{when.format(
                                            new Date(commit.committed_at),
                                        )}</span
                                    >
                                    {#if commit.stats_status === null}
                                        <span class="text-fg-faint"
                                            >Still being counted</span
                                        >
                                    {:else if NOT_COUNTED[commit.stats_status]}
                                        <span class="text-fg-faint"
                                            >{NOT_COUNTED[
                                                commit.stats_status
                                            ]}</span
                                        >
                                    {:else}
                                        <span
                                            >Code <span
                                                class="font-mono text-fg tabular-nums"
                                                >+{number.format(
                                                    commit.code_added,
                                                )}</span
                                            >
                                            <span class="font-mono tabular-nums"
                                                >&minus;{number.format(
                                                    commit.code_removed,
                                                )}</span
                                            ></span
                                        >
                                        <span
                                            >Comments <span
                                                class="font-mono text-fg tabular-nums"
                                                >+{number.format(
                                                    commit.comment_added,
                                                )}</span
                                            >
                                            <span class="font-mono tabular-nums"
                                                >&minus;{number.format(
                                                    commit.comment_removed,
                                                )}</span
                                            ></span
                                        >
                                    {/if}
                                </span>
                            </a>
                        </li>
                    {/each}
                </ul>
                {#if commits.length >= LIMIT}
                    <p class="px-5 py-3 text-xs text-fg-muted">
                        The latest {LIMIT} commits. Narrow the dates to see older
                        ones.
                    </p>
                {/if}
            {/if}
        </div>
    </aside>
{/if}
