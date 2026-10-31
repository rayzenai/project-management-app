<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { Plus, TriangleAlert } from '@lucide/svelte';
    import type { OfficeNetwork } from '../../../lib/attendance';

    /**
     * The office network ranges a punch is judged "office network" by, with
     * the address this page was opened from to help add the office's range
     * (never the VPN's).
     */
    let {
        base,
        networks,
        yourAddress,
    }: {
        /** The organization's attendance URL (the page's `base`). */
        base: string;
        networks: OfficeNetwork[];
        yourAddress: string | null;
    } = $props();

    const uid = $props.id();

    let networkId = $state<number | null>(null);
    let networkOpen = $state(false);
    const networkForm = useForm({ label: '', cidr: '' });

    function startNetwork(network: OfficeNetwork | null) {
        networkId = network?.id ?? null;
        networkOpen = true;
        networkForm.clearErrors();
        networkForm.label = network?.label ?? '';
        networkForm.cidr = network?.cidr ?? '';
    }

    function saveNetwork(e: SubmitEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => (networkOpen = false),
        };

        if (networkId === null) {
            networkForm.post(`${base}/networks`, options);
        } else {
            networkForm.patch(`${base}/networks/${networkId}`, options);
        }
    }

    function removeNetwork(network: OfficeNetwork) {
        if (confirm(`Remove ${network.label} (${network.cidr})?`)) {
            router.delete(`${base}/networks/${network.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<section class="space-y-2">
    <div class="flex items-center gap-2">
        <h2 class="section-title">
            Office networks <span class="section-count">{networks.length}</span>
        </h2>
        <button
            type="button"
            class="btn ml-auto"
            onclick={() => startNetwork(null)}
            ><Plus class="h-3.5 w-3.5" />Add network</button
        >
    </div>
    <p class="text-xs text-fg-muted">
        A press from an address in these ranges counts as the office network.
        Your address now:
        <span class="font-mono text-fg">{yourAddress ?? 'unknown'}</span>.
    </p>
    <p class="flex items-start gap-1.5 text-xs text-fg-muted">
        <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0 text-warn" />
        <span
            >Add only the office's own internet. Never add the VPN's range:
            anyone on the VPN, anywhere, would count as in the office.</span
        >
    </p>
    {#if networkOpen}
        <form onsubmit={saveNetwork} class="panel space-y-3 p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <label class="label" for="{uid}-net-label">Label</label>
                    <input
                        id="{uid}-net-label"
                        class="input"
                        placeholder="Office Wi-Fi"
                        bind:value={networkForm.label}
                        required
                    />
                    {#if networkForm.errors.label}
                        <p class="text-xs text-danger">
                            {networkForm.errors.label}
                        </p>
                    {/if}
                </div>
                <div class="flex flex-col gap-1">
                    <label class="label" for="{uid}-net-cidr"
                        >Address or range</label
                    >
                    <input
                        id="{uid}-net-cidr"
                        class="input font-mono"
                        placeholder="203.0.113.0/24"
                        bind:value={networkForm.cidr}
                        required
                    />
                    {#if networkForm.errors.cidr}
                        <p class="text-xs text-danger">
                            {networkForm.errors.cidr}
                        </p>
                    {/if}
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-1.5">
                {#if yourAddress}
                    <button
                        type="button"
                        class="btn-ghost mr-auto"
                        onclick={() => (networkForm.cidr = yourAddress ?? '')}
                        >Use my address</button
                    >
                {/if}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (networkOpen = false)}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn-primary"
                    disabled={networkForm.processing}
                    >{networkId === null ? 'Add network' : 'Save'}</button
                >
            </div>
        </form>
    {/if}
    <div class="border-t border-line">
        {#each networks as network (network.id)}
            <div class="row min-h-11 px-2 lg:px-2">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">
                        {network.label}
                    </div>
                    <div class="font-mono text-xs text-fg-muted">
                        {network.cidr}
                    </div>
                </div>
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => startNetwork(network)}>Edit</button
                >
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => removeNetwork(network)}>Remove</button
                >
            </div>
        {:else}
            <p class="py-3 text-xs text-fg-muted">
                No networks yet. Presses are judged by location only.
            </p>
        {/each}
    </div>
</section>
