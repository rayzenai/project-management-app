<script lang="ts">
    import type { Id, ReportOptions } from '../../lib/types';
    import OrganizationSelect from './OrganizationSelect.svelte';

    /**
     * A list report's scope: the organization (a super-admin's choice) and
     * everyone in view, or one person's team.
     */
    let {
        options,
        organization,
        under,
        label,
        onchange,
    }: {
        options: ReportOptions;
        organization: Id;
        under: { id: Id; name: string } | null;
        /** What the list is of, for screen readers: "Whose work". */
        label: string;
        onchange: (change: { organization?: Id; under: Id | null }) => void;
    } = $props();

    // Someone narrowed to who heads no listed team (themselves, say) still
    // shows as chosen.
    const teams = $derived<{ id: Id; name: string }[]>(
        under && !options.teams.some((t) => t.id === under.id)
            ? [...options.teams, under]
            : options.teams,
    );
</script>

<OrganizationSelect
    options={options.organizations}
    value={organization}
    onchange={(id) => onchange({ organization: id, under: null })}
/>
{#if teams.length > 0}
    <select
        class="input h-7 w-auto max-w-56 py-0"
        aria-label={label}
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
