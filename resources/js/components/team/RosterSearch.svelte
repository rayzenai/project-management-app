<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Search } from '@lucide/svelte';
    import type { Team } from '../../lib/types';
    import Avatar from '../Avatar.svelte';

    let { team }: { team: Team } = $props();

    interface Candidate {
        id: number;
        name: string;
        email: string | null;
        title: string | null;
        organization: string | null;
    }

    const uid = $props.id();
    let query = $state('');
    let results = $state<Candidate[]>([]);
    let searched = $state(false);
    let adding = $state<number | null>(null);
    let seq = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    /**
     * Ask the server who can join: the team's organization, or anyone for a
     * cross-organization team. Debounced; a stale reply never overwrites a
     * newer one.
     */
    function search(value: string) {
        clearTimeout(timer);
        const q = value.trim();

        if (q.length < 2) {
            results = [];
            searched = false;

            return;
        }

        timer = setTimeout(async () => {
            const mySeq = ++seq;
            const res = await fetch(
                `/workspace/teams/${team.id}/candidates?q=${encodeURIComponent(q)}`,
                { headers: { Accept: 'application/json' } },
            );

            if (mySeq !== seq || !res.ok) {
                return;
            }

            results = ((await res.json()).data ?? []) as Candidate[];
            searched = true;
        }, 200);
    }

    function add(candidate: Candidate) {
        adding = candidate.id;
        router.post(
            `/workspace/teams/${team.id}/members`,
            { member_id: candidate.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    query = '';
                    results = [];
                    searched = false;
                },
                onFinish: () => (adding = null),
            },
        );
    }
</script>

<div class="mt-2 max-w-xl">
    <label for="{uid}-q" class="sr-only">Add someone to {team.name}</label>
    <div class="relative">
        <Search
            class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
        />
        <input
            id="{uid}-q"
            type="search"
            autocomplete="off"
            bind:value={query}
            oninput={(e) => search(e.currentTarget.value)}
            placeholder={team.is_cross_organization
                ? 'Add someone from any organization: name or email'
                : 'Add someone: name or email'}
            class="input pl-8"
        />
    </div>

    {#if results.length > 0}
        <div class="mt-1 rounded-md border border-line">
            {#each results as candidate (candidate.id)}
                <div class="row min-h-10 px-2 py-1">
                    <Avatar name={candidate.name} size="md" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-fg">
                            {candidate.name}
                        </div>
                        <div class="truncate text-xs text-fg-muted">
                            {[
                                candidate.title,
                                candidate.email,
                                team.is_cross_organization
                                    ? candidate.organization
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn-ghost"
                        disabled={adding !== null}
                        onclick={() => add(candidate)}
                        >{adding === candidate.id ? 'Adding' : 'Add'}</button
                    >
                </div>
            {/each}
        </div>
    {:else if searched}
        <p class="mt-1 text-xs text-fg-muted">
            Nobody matches who isn't already on the team.
        </p>
    {/if}
</div>
