<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ProgressReport from '../../components/ProgressReport.svelte';
    import type { ProjectProgress } from '../../lib/types';

    let { progress }: { progress: ProjectProgress } = $props();

    const updatedAt = $derived(
        new Date(progress.generated_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    );

    // People from other organizations (on cross-organization teams) say
    // where they are from.
    const mixed = $derived(
        progress.members.some(
            (m) =>
                m.organization !== null &&
                m.organization !== progress.project.organization,
        ),
    );
</script>

<svelte:head
    ><title>{progress.project.title} progress · Workspace</title></svelte:head
>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/projects"
                use:inertia
                class="shrink-0 text-fg-muted hover:text-fg">Projects</a
            >
            <span class="text-fg-faint">/</span>
            <a
                href={`/workspace/projects/${progress.project.slug}`}
                use:inertia
                class="truncate text-fg-muted hover:text-fg"
                >{progress.project.title}</a
            >
            <span class="text-fg-faint">/</span>
            <span class="shrink-0 font-medium">Progress</span>
            {#if progress.project.is_archived}
                <span class="chip">Archived</span>
            {/if}
        </div>
        <!-- From sm only: a phone's bar keeps the breadcrumb. -->
        <span class="hidden shrink-0 text-xs text-fg-muted sm:inline"
            >Live · updated <span class="font-mono tabular-nums"
                >{updatedAt}</span
            ></span
        >
    {/snippet}

    <ProgressReport {progress} showOrganization={mixed} />
</AppShell>
