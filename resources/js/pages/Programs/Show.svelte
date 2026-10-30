<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ProgramForm from '../../components/program/ProgramForm.svelte';
    import ProgressReport from '../../components/ProgressReport.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import { formatDate } from '../../lib/format';
    import type { Member, ProgramProgress } from '../../lib/types';

    let {
        progress,
        managerCandidates = [],
    }: {
        progress: ProgramProgress;
        /** Who it can be handed to; empty unless the viewer may manage it. */
        managerCandidates?: Member[];
    } = $props();

    let editing = $state(false);

    function destroy() {
        if (
            !confirm(
                `Delete the ${progress.program.name} program? Its projects stay, with no program.`,
            )
        ) {
            return;
        }

        router.delete(`/workspace/programs/${progress.program.slug}`);
    }

    const updatedAt = $derived(
        new Date(progress.generated_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    );

    const mixed = $derived(
        progress.members.some(
            (m) =>
                m.organization !== null &&
                m.organization !== progress.program.organization.name,
        ),
    );

    function percent(done: number, total: number): number {
        return total === 0 ? 0 : Math.round((done / total) * 100);
    }
</script>

<svelte:head><title>{progress.program.name} · Workspace</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/projects"
                use:inertia
                class="shrink-0 text-fg-muted hover:text-fg">Projects</a
            >
            <span class="text-fg-faint">/</span>
            <span class="truncate font-medium">{progress.program.name}</span>
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            <!-- From sm only: a phone's bar keeps the breadcrumb. -->
            <span class="hidden text-xs text-fg-muted sm:inline"
                >Live · updated <span class="font-mono tabular-nums"
                    >{updatedAt}</span
                ></span
            >
            {#if progress.program.can_manage && !editing}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (editing = true)}>Edit</button
                >
                <button type="button" class="btn-danger" onclick={destroy}
                    >Delete</button
                >
            {/if}
        </div>
    {/snippet}

    <div class="mb-8 space-y-6">
        {#if editing}
            <div class="border-b border-line pb-6">
                <ProgramForm
                    program={progress.program}
                    {managerCandidates}
                    onsaved={() => (editing = false)}
                    oncancel={() => (editing = false)}
                />
            </div>
        {/if}
        <header>
            <h1 class="text-lg font-semibold">{progress.program.name}</h1>
            {#if progress.program.description}
                <p class="mt-1 text-[13px] text-fg-muted">
                    {progress.program.description}
                </p>
            {/if}
            {#if progress.program.manager}
                <p
                    class="mt-1 flex flex-wrap items-baseline gap-x-2 text-xs text-fg-muted"
                >
                    <span
                        >Managed by <span class="font-medium text-fg"
                            >{progress.program.manager.name}</span
                        ></span
                    >
                    {#if progress.program.manager.title}
                        <span class="text-fg-faint"
                            >{progress.program.manager.title}</span
                        >
                    {/if}
                </p>
            {/if}
        </header>

        <section>
            <h2 class="section-title mb-2">
                Projects
                <span class="section-count">{progress.projects.length}</span>
            </h2>
            {#if progress.projects.length === 0}
                <p class="text-[13px] text-fg-muted">
                    No active projects in this program yet.
                </p>
            {:else}
                <!-- A phone drops Manager and Open (Done shows done of all);
                     between sm and the full width the table scrolls in here. -->
                <div class="overflow-x-auto">
                    <table class="w-full text-[13px] sm:min-w-[640px]">
                        <thead>
                            <tr class="border-b border-line text-left">
                                <th class="col-head py-1.5 pr-3 font-normal"
                                    >Project</th
                                >
                                <th
                                    class="col-head hidden px-3 font-normal sm:table-cell"
                                    >Manager</th
                                >
                                <th class="col-head px-3 font-normal">Done</th>
                                <th
                                    class="col-head hidden px-3 text-right font-normal sm:table-cell"
                                    >Open</th
                                >
                                <th class="col-head px-3 text-right font-normal"
                                    >Overdue</th
                                >
                                <th class="col-head py-1.5 pl-3 font-normal"
                                    >Next deadline</th
                                >
                            </tr>
                        </thead>
                        <tbody>
                            {#each progress.projects as project (project.slug)}
                                <tr class="border-b border-line-soft">
                                    <td class="py-2 pr-3">
                                        <a
                                            href={`/workspace/projects/${project.slug}`}
                                            use:inertia
                                            class="font-medium hover:text-accent"
                                            >{project.title}</a
                                        >
                                        <span
                                            class="ml-1.5 font-mono text-xs text-fg-faint"
                                            >{project.code}</span
                                        >
                                    </td>
                                    <td
                                        class="hidden px-3 text-fg-muted sm:table-cell"
                                        >{project.manager?.name ?? '-'}</td
                                    >
                                    <td class="px-3">
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <ProgressRing
                                                percent={percent(
                                                    project.done_tasks_count,
                                                    project.tasks_count,
                                                )}
                                            />
                                            <span
                                                class="font-mono text-xs tabular-nums text-fg-muted"
                                                >{project.done_tasks_count}/{project.tasks_count}</span
                                            >
                                        </span>
                                    </td>
                                    <td
                                        class="hidden px-3 text-right font-mono text-fg-muted tabular-nums sm:table-cell"
                                        >{project.tasks_count -
                                            project.done_tasks_count}</td
                                    >
                                    <td
                                        class={`px-3 text-right font-mono tabular-nums ${project.overdue_tasks_count > 0 ? 'text-danger' : 'text-fg-muted'}`}
                                        >{project.overdue_tasks_count}</td
                                    >
                                    <td
                                        class="py-2 pl-3 font-mono text-xs text-fg-muted"
                                        >{project.next_deadline_at
                                            ? formatDate(
                                                  project.next_deadline_at,
                                              )
                                            : '-'}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </section>
    </div>

    <ProgressReport {progress} showOrganization={mixed} />
</AppShell>
