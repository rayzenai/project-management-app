<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { FolderOpen, Plus, Users, X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import { formatTimeAgo } from '../../lib/format';
    import type { MyWorkJoined } from '../../lib/types';

    /**
     * The projects you were put on lately (MyWorkQuery's `joined`), so
     * someone added to a project with no tasks yet is told on the page they
     * land on, and can open it or add its first task from here. Each one can
     * be put away on this device.
     */
    let {
        joined,
        onAdd,
    }: {
        joined: MyWorkJoined[];
        /** Focus the add field with this project chosen. */
        onAdd: (projectId: number) => void;
    } = $props();

    /** Kept in a constant: a bare ' · ' at the start of a block loses its spaces. */
    const DOT = ' · ';
    const STORE = 'workspace.mywork.joined.hidden.';

    function remembered(ids: number[]): number[] {
        if (typeof window === 'undefined') {
            return [];
        }

        try {
            return ids.filter(
                (id) => window.localStorage.getItem(STORE + id) === '1',
            );
        } catch {
            return []; // private mode / blocked storage
        }
    }

    const hidden = new SvelteSet<number>(
        remembered(untrack(() => joined.map((j) => j.project.id))),
    );
    const shown = $derived(joined.filter((j) => !hidden.has(j.project.id)));

    function dismiss(projectId: number): void {
        hidden.add(projectId);

        try {
            window.localStorage.setItem(STORE + projectId, '1');
        } catch {
            /* nothing to remember it in; it stays hidden for this visit */
        }
    }
</script>

{#if shown.length > 0}
    <ul
        class="mt-4 max-h-[420px] space-y-2 overflow-y-auto px-5 sm:px-0"
        aria-label="Projects you were added to"
    >
        {#each shown as item (item.project.id)}
            {@const ago = formatTimeAgo(item.joined_at)}
            <li class="panel py-1 pr-1 pl-4 sm:pl-3">
                <div class="flex items-start gap-3">
                    <Users
                        class="mt-[15px] size-4 shrink-0 text-fg-muted sm:mt-[11px]"
                    />
                    <p
                        class="min-w-0 flex-1 py-[11px] text-[15px] leading-[22px] text-fg sm:py-[7px] sm:text-[14px]"
                    >
                        {#if item.role === 'leader'}
                            You manage <strong class="font-semibold"
                                >{item.project.label}</strong
                            >
                        {:else}
                            You were added to <strong class="font-semibold"
                                >{item.project.label}</strong
                            >{#if item.added_by}{` by ${item.added_by}`}{/if}
                        {/if}{#if ago}<span class="text-fg-muted"
                                >{DOT}{ago}</span
                            >{/if}
                    </p>
                    <button
                        type="button"
                        class="btn-icon size-[44px] shrink-0 sm:mt-[2px] sm:size-[32px]"
                        aria-label={`Hide ${item.project.label} here`}
                        title="Hide on this device"
                        onclick={() => dismiss(item.project.id)}
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <!-- Under the words from sm; the whole width on a phone, so both fit on one line. -->
                <div class="flex flex-wrap gap-2 pr-3 pb-3 sm:pb-2 sm:pl-7">
                    <a
                        href={`/workspace/projects/${item.project.slug}`}
                        use:inertia
                        class="btn h-[44px] sm:h-[32px]"
                    >
                        <FolderOpen class="size-4 text-fg-muted" />
                        Open project
                    </a>
                    <button
                        type="button"
                        class="btn h-[44px] sm:h-[32px]"
                        onclick={() => onAdd(item.project.id)}
                    >
                        <Plus class="size-4 text-fg-muted" />
                        Add a task here
                    </button>
                </div>
            </li>
        {/each}
    </ul>
{/if}
