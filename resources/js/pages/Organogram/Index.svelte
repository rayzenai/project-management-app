<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ListTree, Network, Printer } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import OrganizationSelect from '../../components/filters/OrganizationSelect.svelte';
    import DesignationsPanel from '../../components/organogram/DesignationsPanel.svelte';
    import OrganogramOutline from '../../components/organogram/OrganogramOutline.svelte';
    import OrgChart from '../../components/organogram/OrgChart.svelte';
    import { buildOrgTree } from '../../lib/structure';
    import type { Organogram } from '../../lib/structure';
    import type { Id, OrganizationOption } from '../../lib/types';

    /**
     * The organogram page: the outline to rearrange (OrganogramOutline),
     * the top-down chart (OrgChart) and its printout, and, for managers
     * (whoever administers the organization), its designations.
     */
    let {
        organogram,
        organizations = [],
    }: {
        organogram: Organogram | null;
        /** A super-admin's choice of organization; empty for anyone else. */
        organizations?: OrganizationOption[];
    } = $props();

    const tree = $derived(organogram ? buildOrgTree(organogram.people) : []);
    const people = $derived(organogram?.people ?? []);
    const canManage = $derived(organogram?.can_manage ?? false);
    const designations = $derived(organogram?.designations ?? []);

    let view = $state<'outline' | 'chart'>('outline');

    function pickOrganization(id: Id) {
        router.get('/workspace/organogram', { organization: id });
    }

    function printChart() {
        window.print();
    }

    /** Moves the print-only chart to <body>, so printing can hide the rest. */
    function portal(node: HTMLElement) {
        document.body.appendChild(node);

        return {
            destroy() {
                node.remove();
            },
        };
    }
</script>

<svelte:head>
    <title
        >Organogram{organogram ? ` · ${organogram.organization.name}` : ''} · Workspace</title
    >
</svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            {#if organogram}
                <span class="hidden truncate text-fg-muted sm:inline"
                    >{organogram.organization.name}</span
                >
                <span class="hidden text-fg-faint sm:inline">/</span>
            {/if}
            <span class="truncate font-medium">Organogram</span>
        </div>
        <div class="flex items-center gap-1.5">
            {#if organogram}
                <OrganizationSelect
                    options={organizations}
                    value={organogram.organization.id}
                    onchange={pickOrganization}
                />
            {/if}
            {#if organogram}
                <div class="flex items-center" role="group" aria-label="View">
                    <button
                        type="button"
                        class={view === 'outline' ? 'btn' : 'btn-ghost'}
                        aria-pressed={view === 'outline'}
                        aria-label="Outline"
                        onclick={() => (view = 'outline')}
                        ><ListTree class="h-3.5 w-3.5" /><span
                            class="hidden sm:inline">Outline</span
                        ></button
                    >
                    <button
                        type="button"
                        class={view === 'chart' ? 'btn' : 'btn-ghost'}
                        aria-pressed={view === 'chart'}
                        aria-label="Chart"
                        onclick={() => (view = 'chart')}
                        ><Network class="h-3.5 w-3.5" /><span
                            class="hidden sm:inline">Chart</span
                        ></button
                    >
                </div>
                <button
                    type="button"
                    class="btn-ghost"
                    aria-label="Print the chart"
                    onclick={printChart}
                    ><Printer class="h-3.5 w-3.5" /><span
                        class="hidden sm:inline">Print</span
                    ></button
                >
            {/if}
        </div>
    {/snippet}

    {#if !organogram}
        <p class="px-5 py-6 text-xs text-fg-muted lg:px-8 lg:py-8">
            There are no organizations yet.
        </p>
    {:else if view === 'chart'}
        <div class="px-5 py-6 lg:px-8 lg:py-8">
            {#if tree.length === 0}
                <p class="text-xs text-fg-muted">Nobody here yet.</p>
            {:else}
                <OrgChart {tree} />
            {/if}
        </div>
    {:else}
        <div
            class={`grid min-w-0 ${canManage ? 'lg:grid-cols-[minmax(0,1fr)_300px]' : ''}`}
        >
            <OrganogramOutline {tree} {people} {designations} {canManage} />

            {#if canManage}
                <DesignationsPanel
                    organizationId={organogram.organization.id}
                    {designations}
                />
            {/if}
        </div>
    {/if}
</AppShell>

{#if organogram && tree.length > 0}
    <div class="organogram-print" use:portal aria-hidden="true">
        <h1 class="print-title">
            {organogram.organization.name}: organogram
        </h1>
        <OrgChart {tree} />
    </div>
{/if}

<style>
    .organogram-print {
        display: none;
    }

    @media print {
        @page {
            size: landscape;
            margin: 12mm;
        }

        :global(body > *:not(.organogram-print)) {
            display: none !important;
        }

        :global(body) {
            background: #fff !important;
        }

        .organogram-print {
            display: block;
            color: #111;
            print-color-adjust: exact;
        }

        .print-title {
            margin: 0 0 16px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }
    }
</style>
