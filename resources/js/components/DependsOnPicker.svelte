<script lang="ts">
    import { X } from '@lucide/svelte';
    import type { TaskOption } from '../lib/types';
    import StatusGlyph from './StatusGlyph.svelte';

    /**
     * Pick the tasks a new task will wait for, by searching the chosen
     * project's tasks. Only that project's tasks are offered; the server
     * ignores any others too.
     */
    let {
        projectSlug,
        selected = $bindable([]),
    }: {
        projectSlug: string | null;
        selected: TaskOption[];
    } = $props();

    const uid = $props.id();
    let query = $state('');
    let results = $state<TaskOption[]>([]);
    let searched = $state(false);
    let seq = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function search(value: string) {
        clearTimeout(timer);
        const q = value.trim();

        if (q.length < 1 || !projectSlug) {
            results = [];
            searched = false;

            return;
        }

        timer = setTimeout(async () => {
            const mine = ++seq;
            const response = await fetch(
                `/workspace/search?q=${encodeURIComponent(q)}`,
                { headers: { Accept: 'application/json' } },
            );

            if (mine !== seq || !response.ok) {
                return;
            }

            const tasks = ((await response.json()).data?.tasks ??
                []) as (TaskOption & { project?: { slug: string } | null })[];

            results = tasks
                .filter((t) => t.project?.slug === projectSlug)
                .filter((t) => !selected.some((s) => s.id === t.id))
                .slice(0, 6);
            searched = true;
        }, 180);
    }

    function pick(option: TaskOption) {
        selected = [...selected, option];
        query = '';
        results = [];
        searched = false;
    }

    function drop(option: TaskOption) {
        selected = selected.filter((s) => s.id !== option.id);
    }

    function label(option: TaskOption): string {
        return option.item_number !== null
            ? `#${option.item_number} ${option.title}`
            : option.title;
    }
</script>

<div class="flex flex-col gap-1">
    <label for="{uid}-q" class="label">Depends on</label>
    {#if selected.length > 0}
        <div class="flex flex-wrap gap-1">
            {#each selected as option (option.id)}
                <span class="chip max-w-full">
                    <span class="truncate">{label(option)}</span>
                    <button
                        type="button"
                        class="text-fg-faint hover:text-fg"
                        aria-label={`Stop waiting for ${option.title}`}
                        onclick={() => drop(option)}
                        ><X class="h-3 w-3" /></button
                    >
                </span>
            {/each}
        </div>
    {/if}
    <input
        id="{uid}-q"
        type="search"
        autocomplete="off"
        bind:value={query}
        oninput={(e) => search(e.currentTarget.value)}
        onkeydown={(e) => {
            // Enter picks the first match instead of submitting the form.
            if (e.key === 'Enter' && query.trim() !== '') {
                e.preventDefault();

                if (results[0]) {
                    pick(results[0]);
                }
            }
        }}
        placeholder={projectSlug
            ? 'Find a task in this project it waits for'
            : 'Pick a project first'}
        disabled={!projectSlug}
        class="input"
    />
    {#if results.length > 0}
        <ul class="rounded-md border border-line">
            {#each results as option (option.id)}
                <li>
                    <button
                        type="button"
                        class="flex w-full min-w-0 items-center gap-2 px-2 py-1.5 text-left text-[13px] hover:bg-hover"
                        onclick={() => pick(option)}
                    >
                        <StatusGlyph status={option.status} />
                        <span class="min-w-0 flex-1 truncate text-fg"
                            >{label(option)}</span
                        >
                    </button>
                </li>
            {/each}
        </ul>
    {:else if searched}
        <p class="text-xs text-fg-muted">No task in this project matches.</p>
    {/if}
    <p class="text-xs text-fg-faint">
        It can start once these are done. On the board you can also drop a card
        onto another.
    </p>
</div>
