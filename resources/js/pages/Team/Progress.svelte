<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ProgressReport from '../../components/ProgressReport.svelte';
    import type { TeamProgress } from '../../lib/types';

    let { progress }: { progress: TeamProgress } = $props();

    const updatedAt = $derived(
        new Date(progress.generated_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    );
</script>

<svelte:head
    ><title>{progress.team.name} progress · Workspace</title></svelte:head
>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/team"
                use:inertia
                class="text-fg-muted hover:text-fg">Team</a
            >
            <span class="text-fg-faint">/</span>
            <span class="truncate font-medium">{progress.team.name}</span>
            {#if progress.team.is_cross_organization}
                <span class="chip">Cross-organization</span>
            {/if}
        </div>
        <span class="shrink-0 text-xs text-fg-muted"
            >Live · updated <span class="font-mono tabular-nums"
                >{updatedAt}</span
            ></span
        >
    {/snippet}

    <ProgressReport
        {progress}
        showOrganization={progress.team.is_cross_organization}
    />
</AppShell>
