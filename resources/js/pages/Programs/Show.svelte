<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ProgressReport from '../../components/ProgressReport.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import { formatDate } from '../../lib/format';
    import type { ProgramProgress } from '../../lib/types';

    let { progress }: { progress: ProgramProgress } = $props();

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
        <span class="shrink-0 text-xs text-fg-muted"
            >Live · updated <span class="font-mono tabular-nums"
                >{updatedAt}</span
            ></span
        >
    {/snippet}

    <div class="mb-8 space-y-6">
        <header>
            <h1 class="text-lg font-semibold">{progress.program.name}</h1>
            {#if progress.program.description}
                <p class="mt-1 text-[13px] text-fg-muted">
                    {progress.program.description}
                </p>
            {/if}
            {#if progress.program.manager}
                <p class="mt-1 text-xs text-fg-muted">
                    Managed by {progress.program.manager.name}{progress.program
                        .manager.title
                        ? `, ${progress.program.manager.title}`
                        : ''}
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
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13px]">
                        <thead>
                            <tr class="border-b border-line text-left">
                                <th class="col-head py-1.5 pr-3 font-normal"
                                    >Project</th
                                >
                                <th class="col-head px-3 font-normal"
                                    >Manager</th
                                >
                                <th class="col-head px-3 font-normal">Done</th>
                                <th class="col-head px-3 text-right font-normal"
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
                                    <td class="px-3 text-fg-muted"
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
                                        class="px-3 text-right font-mono text-fg-muted tabular-nums"
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
