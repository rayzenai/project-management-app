<script lang="ts">
    import AppShell from '../../components/AppShell.svelte';
    import FormList from '../../components/forms/FormList.svelte';
    import NewForm from '../../components/forms/NewForm.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import type { FormItem, FormTemplate } from '../../lib/forms/types';

    /**
     * The Forms page: forms waiting for you (To fill), what you sent
     * (Filled), and the forms you build or whose responses you see
     * (Managing). New form starts one in a project you work on.
     */
    let props: {
        to_fill: FormItem[];
        filled: FormItem[];
        managing: FormItem[];
        places: { slug: string; label: string }[];
        templates: FormTemplate[];
    } = $props();

    // svelte-ignore state_referenced_locally
    let tab = $state<string>(
        props.to_fill.length === 0 && props.managing.length > 0
            ? 'managing'
            : 'to_fill',
    );

    const tabs = $derived([
        {
            value: 'to_fill',
            label: `To fill${props.to_fill.length ? ` (${props.to_fill.length})` : ''}`,
        },
        { value: 'filled', label: 'Filled' },
        { value: 'managing', label: 'Managing' },
    ]);
</script>

<svelte:head><title>Forms</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Forms</span>
        </div>
        <NewForm templates={props.templates} places={props.places} />
    {/snippet}

    <div class="mx-auto w-full max-w-4xl">
        <div class="-mx-1 overflow-x-auto px-1 [scrollbar-width:none]">
            <PillGroup options={tabs} bind:value={tab} />
        </div>

        <div class="mt-4">
            {#if tab === 'to_fill'}
                {#if props.to_fill.length}
                    <FormList forms={props.to_fill} mode="fill" />
                {:else}
                    <p class="text-[13px] text-fg-muted">
                        No forms are waiting for you. When an office sends you
                        one, it shows here and on My work.
                    </p>
                {/if}
            {:else if tab === 'filled'}
                {#if props.filled.length}
                    <FormList forms={props.filled} mode="filled" />
                {:else}
                    <p class="text-[13px] text-fg-muted">Nothing sent yet.</p>
                {/if}
            {:else if props.managing.length}
                <FormList forms={props.managing} mode="manage" />
            {:else}
                <div class="text-[13px] text-fg-muted">
                    <p class="font-medium text-fg">No forms to manage yet.</p>
                    <p class="mt-1 max-w-xl">
                        Make a form in a project you work on: a data call to
                        other offices, a survey, a registration or a request. It
                        gets a link to share; signed-in officials fill it in,
                        and their answers come back here. Only whoever runs the
                        project, the form's maker and the people they share it
                        with see the responses.
                    </p>
                </div>
            {/if}
        </div>
    </div>
</AppShell>
