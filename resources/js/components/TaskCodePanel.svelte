<script lang="ts">
    import {
        Copy,
        GitBranch,
        GitCommitHorizontal,
        GitMerge,
        GitPullRequest,
        GitPullRequestClosed,
    } from '@lucide/svelte';
    import { writeToClipboard } from '../lib/clipboard';
    import { formatTimeAgo } from '../lib/format';
    import { toast } from '../lib/toast.svelte';
    import type { TaskCode } from '../lib/types';

    /**
     * A task's Code section, in the Peek and on the task page: the pull
     * requests, branches and latest commits that mention its code, each
     * opening on the forge, and the branch name to start work on. Shown only
     * when there is git.
     */
    let {
        code,
        heading = 'h3',
    }: {
        code: TaskCode;
        /** h2 on the task page, h3 inside the Peek. */
        heading?: 'h2' | 'h3';
    } = $props();

    const subheading = $derived(heading === 'h2' ? 'h3' : 'h4');

    const STATES = {
        open: { label: 'Open', tone: 'text-accent' },
        merged: { label: 'Merged', tone: 'text-success' },
        closed: { label: 'Closed', tone: 'text-fg-faint' },
    } as const;

    const empty = $derived(
        code.pull_requests.length === 0 &&
            code.branches.length === 0 &&
            code.commits.length === 0,
    );

    function copyBranch() {
        writeToClipboard(code.branch_name);
        toast.show(`Copied ${code.branch_name}`, { duration: 2000 });
    }
</script>

<section class="mt-8">
    <div class="flex items-center justify-between gap-2">
        <svelte:element this={heading} class="section-title text-[15px]"
            >Code</svelte:element
        >
        <button
            type="button"
            class="btn-ghost"
            title={code.branch_name}
            onclick={copyBranch}
            ><Copy class="h-3.5 w-3.5" />Copy branch name</button
        >
    </div>

    {#if empty}
        <p class="mt-1 text-[13px] text-fg-muted">
            No pull requests, branches or commits mention this task yet. Start
            work on <span class="font-mono text-fg">{code.branch_name}</span>.
        </p>
    {/if}

    {#if code.pull_requests.length > 0}
        <svelte:element
            this={subheading}
            class="mt-2 text-xs font-medium text-fg-muted"
            >Pull requests</svelte:element
        >
        <ul>
            {#each code.pull_requests as pull (pull.id)}
                {@const state = STATES[pull.state]}
                <li class="flex min-h-[30px] items-center gap-2 text-[12.5px]">
                    {#if pull.state === 'merged'}
                        <GitMerge
                            class={`h-3.5 w-3.5 shrink-0 ${state.tone}`}
                        />
                    {:else if pull.state === 'closed'}
                        <GitPullRequestClosed
                            class={`h-3.5 w-3.5 shrink-0 ${state.tone}`}
                        />
                    {:else}
                        <GitPullRequest
                            class={`h-3.5 w-3.5 shrink-0 ${state.tone}`}
                        />
                    {/if}
                    <a
                        href={pull.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 flex-1 truncate text-fg hover:text-accent"
                        >{pull.title}</a
                    >
                    <span
                        class="hidden shrink-0 font-mono text-[11px] text-fg-faint tabular-nums sm:inline"
                        >{pull.repository}#{pull.number}</span
                    >
                    <span class="w-12 shrink-0 text-right text-xs text-fg-muted"
                        >{state.label}</span
                    >
                </li>
            {/each}
        </ul>
    {/if}

    {#if code.branches.length > 0}
        <svelte:element
            this={subheading}
            class="mt-2 text-xs font-medium text-fg-muted"
            >Branches</svelte:element
        >
        <ul>
            {#each code.branches as branch (branch.id)}
                <li class="flex min-h-[30px] items-center gap-2 text-[12.5px]">
                    <GitBranch class="h-3.5 w-3.5 shrink-0 text-fg-faint" />
                    <a
                        href={branch.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 flex-1 truncate font-mono text-[12px] text-fg hover:text-accent"
                        >{branch.name}</a
                    >
                    <span class="shrink-0 text-xs text-fg-faint"
                        >{branch.repository}</span
                    >
                </li>
            {/each}
        </ul>
    {/if}

    {#if code.commits.length > 0}
        <svelte:element
            this={subheading}
            class="mt-2 text-xs font-medium text-fg-muted"
            >Latest commits</svelte:element
        >
        <ul>
            {#each code.commits as commit (commit.id)}
                <li
                    class="flex min-h-[30px] items-center gap-2 text-[12.5px]"
                    title={commit.unreachable
                        ? `${commit.repository}: No longer on any branch (force-pushed, or squash-merged and deleted)`
                        : commit.repository}
                >
                    <GitCommitHorizontal
                        class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                    />
                    <span
                        class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                        >{commit.short_sha}</span
                    >
                    <a
                        href={commit.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class={`min-w-0 flex-1 truncate hover:text-accent ${commit.unreachable ? 'text-fg-faint' : 'text-fg'}`}
                        >{commit.subject}</a
                    >
                    {#if commit.unreachable}
                        <span class="chip shrink-0">Unreachable</span>
                    {/if}
                    <span class="shrink-0 text-xs text-fg-muted"
                        >{commit.author} · {formatTimeAgo(
                            commit.committed_at,
                        )}</span
                    >
                </li>
            {/each}
        </ul>
    {/if}
</section>
