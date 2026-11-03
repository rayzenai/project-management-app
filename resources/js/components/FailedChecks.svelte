<script lang="ts">
    import { ExternalLink } from '@lucide/svelte';
    import type { Checks } from '../lib/types';

    /**
     * Which checks failed, each opening its job's log on the forge, where
     * the failing step and its error are. The forge's API gives a check's
     * name, result and link but never its log, so this is as close to the
     * reason as the tracker gets. Nothing unless something failed.
     */
    let {
        checks,
        class: className = '',
    }: {
        checks: Checks | null | undefined;
        class?: string;
    } = $props();

    const failed = $derived(
        checks?.state === 'failed'
            ? checks.checks.filter((c) => c.state === 'failed')
            : [],
    );
</script>

{#if failed.length > 0}
    <span class={`flex gap-x-2 text-xs text-fg-muted ${className}`}>
        <span class="shrink-0 text-fg-faint">Failed:</span>
        <span class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5">
            {#each failed as check (check.name)}
                {#if check.url}
                    <a
                        href={check.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={`Open the log of ${check.name} on the forge`}
                        class="inline-flex items-center gap-1 hover:text-accent"
                        >{check.name}<ExternalLink
                            class="h-3 w-3 shrink-0 text-fg-faint"
                        /></a
                    >
                {:else}
                    <span>{check.name}</span>
                {/if}
            {/each}
        </span>
    </span>
{/if}
