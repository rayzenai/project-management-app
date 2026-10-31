<script lang="ts">
    import type { Id, OrganizationOption } from '../../lib/types';

    /**
     * A super-admin's choice of organization on a page about one
     * organization; nothing for anyone else, who only sees their own.
     */
    let {
        options,
        value,
        onchange,
    }: {
        options: OrganizationOption[];
        value: Id;
        onchange: (id: Id) => void;
    } = $props();
</script>

{#if options.length > 1}
    <select
        class="input h-7 w-auto max-w-40 py-0 sm:max-w-56"
        aria-label="Organization"
        value={String(value)}
        onchange={(e) => onchange(Number(e.currentTarget.value))}
    >
        {#each options as organization (organization.id)}
            <option value={String(organization.id)}>{organization.name}</option>
        {/each}
    </select>
{/if}
