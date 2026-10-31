<script lang="ts">
    import { Circle, CircleCheck, CircleDashed, CircleX } from '@lucide/svelte';
    import type { Checks } from '../lib/types';

    /**
     * A commit's or pull request's checks at a glance: a green tick when they
     * passed, a red cross when one failed, an amber dashed ring while they
     * run, a faint ring when nothing checks it, and nothing until the tracker
     * knows. Hovering lists every check; the mark opens the failing run (else
     * the first) on the forge, unless `link` is off (inside another link).
     * `label` adds the words beside it.
     */
    let {
        checks,
        label = false,
        link = true,
    }: {
        checks: Checks | null | undefined;
        label?: boolean;
        link?: boolean;
    } = $props();

    const WORDS: Record<Checks['state'], string> = {
        passed: 'Tests passed',
        failed: 'Tests failed',
        running: 'Tests running',
        none: 'No tests',
    };

    const title = $derived(
        checks
            ? [
                  WORDS[checks.state],
                  ...checks.checks.map((c) => `${c.name}: ${c.state}`),
              ].join('\n')
            : '',
    );

    const href = $derived(
        link
            ? (checks?.checks.find((c) => c.state === 'failed' && c.url)?.url ??
                  checks?.checks.find((c) => c.url)?.url ??
                  null)
            : null,
    );
</script>

{#snippet mark(state: Checks['state'])}
    {#if state === 'passed'}
        <CircleCheck class="h-3.5 w-3.5 shrink-0 text-success" />
    {:else if state === 'failed'}
        <CircleX class="h-3.5 w-3.5 shrink-0 text-danger" />
    {:else if state === 'running'}
        <CircleDashed class="h-3.5 w-3.5 shrink-0 text-warn" />
    {:else}
        <Circle class="h-3.5 w-3.5 shrink-0 text-fg-faint" />
    {/if}
    {#if label}
        <span class="text-fg-muted">{WORDS[state]}</span>
    {/if}
{/snippet}

{#if checks}
    {#if href}
        <a
            {href}
            target="_blank"
            rel="noopener noreferrer"
            {title}
            aria-label={WORDS[checks.state]}
            class="inline-flex shrink-0 items-center gap-1 text-xs hover:opacity-80"
        >
            {@render mark(checks.state)}
        </a>
    {:else}
        <span
            {title}
            aria-label={WORDS[checks.state]}
            class="inline-flex shrink-0 items-center gap-1 text-xs"
        >
            {@render mark(checks.state)}
        </span>
    {/if}
{/if}
