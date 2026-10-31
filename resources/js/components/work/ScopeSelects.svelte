<script lang="ts">
    import type { Id } from '../../lib/types';
    import type { WorkOptions } from '../../lib/work';

    /**
     * Whose work a list shows: the organization (a super-admin's choice)
     * and everyone in view, or one person's team.
     */
    let {
        options,
        organization,
        under,
        onchange,
    }: {
        options: WorkOptions;
        organization: Id;
        under: { id: Id; name: string } | null;
        onchange: (change: { organization?: Id; under: Id | null }) => void;
    } = $props();

    // Someone narrowed to who heads no listed team (themselves, say) still
    // shows as chosen.
    const teams = $derived(
        under && !options.teams.some((t) => t.id === under.id)
            ? [...options.teams, under]
            : options.teams,
    );
</script>

{#if options.organizations.length > 0}
    <select
        class="input h-7 w-auto max-w-56 py-0"
        aria-label="Organization"
        value={String(organization)}
        onchange={(e) =>
            onchange({
                organization: Number(e.currentTarget.value),
                under: null,
            })}
    >
        {#each options.organizations as org (org.id)}
            <option value={String(org.id)}>{org.name}</option>
        {/each}
    </select>
{/if}
{#if teams.length > 0}
    <select
        class="input h-7 w-auto max-w-56 py-0"
        aria-label="Whose work"
        value={under ? String(under.id) : ''}
        onchange={(e) =>
            onchange({
                under:
                    e.currentTarget.value === ''
                        ? null
                        : Number(e.currentTarget.value),
            })}
    >
        <option value="">Everyone</option>
        {#each teams as team (team.id)}
            <option value={String(team.id)}>{team.name}'s team</option>
        {/each}
    </select>
{/if}
