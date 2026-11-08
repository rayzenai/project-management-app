<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Building2, Check, ChevronsUpDown } from '@lucide/svelte';
    import { t } from '../../lib/i18n';
    import type { Id, OrganizationSwitch } from '../../lib/types';
    import Popover from '../Popover.svelte';

    /**
     * A super-admin's organization switch: which organization's projects
     * the sidebar lists and which one the office pages open on, or all of
     * them. At the top of the sidebar, and of the Me sheet on a phone
     * (`sheet`: a full-width row you can tap).
     */
    let {
        organizationSwitch,
        sheet = false,
        onswitch,
    }: {
        organizationSwitch: OrganizationSwitch;
        sheet?: boolean;
        /** After a pick, e.g. to put the sheet away. */
        onswitch?: () => void;
    } = $props();

    let open = $state(false);

    const choices = $derived([
        { id: null as Id | null, name: t('All organizations') },
        ...organizationSwitch.organizations,
    ]);
    const name = $derived(
        organizationSwitch.organizations.find(
            (organization) => organization.id === organizationSwitch.current,
        )?.name ?? t('All organizations'),
    );

    function switchTo(id: Id | null) {
        open = false;
        onswitch?.();

        if (id === (organizationSwitch.current ?? null)) {
            return;
        }

        router.put(
            '/workspace/organization',
            { organization: id ?? 'all' },
            { preserveScroll: true },
        );
    }
</script>

<Popover
    bind:open
    role="listbox"
    triggerLabel={t('Organization: :name', { name })}
    wrapperClass="min-w-0 flex-1"
    triggerClass={sheet
        ? 'flex min-h-[44px] w-full items-center gap-3 rounded-lg border border-line bg-surface px-3 text-left text-[15px] font-medium text-fg transition hover:bg-hover'
        : 'flex h-[36px] w-full items-center gap-2 rounded-lg px-2 text-left text-[14.5px] font-semibold text-fg transition hover:bg-hover'}
    panelClass={sheet ? 'w-[calc(100vw-24px)]' : 'w-[280px]'}
>
    {#snippet trigger()}
        {#if sheet}
            <Building2 class="h-[18px] w-[18px] shrink-0 text-fg-muted" />
        {/if}
        <span class="min-w-0 flex-1 truncate">{name}</span>
        <ChevronsUpDown class="h-[16px] w-[16px] shrink-0 text-fg-faint" />
    {/snippet}
    <div class="max-h-[min(420px,70vh)] overflow-y-auto py-1">
        {#each choices as choice (choice.id ?? 'all')}
            {@const selected =
                choice.id === (organizationSwitch.current ?? null)}
            <button
                type="button"
                role="option"
                aria-selected={selected}
                data-popover-item
                class={`menu-item ${sheet ? 'min-h-[44px] text-[15px]' : ''} ${selected ? 'text-fg' : ''}`}
                onclick={() => switchTo(choice.id)}
            >
                <span class="min-w-0 flex-1 truncate">{choice.name}</span>
                {#if selected}
                    <Check class="h-3.5 w-3.5 shrink-0 text-accent" />
                {/if}
            </button>
        {/each}
    </div>
</Popover>
