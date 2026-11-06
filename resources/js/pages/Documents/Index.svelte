<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import type { DocumentLibrary } from '../../lib/types';

    /**
     * The Documents page: every document the viewer may open, most recently
     * edited first. (Filters, New, Upload, Share and the trash arrive with
     * the shared document parts.)
     */
    let props: DocumentLibrary = $props();
</script>

<svelte:head><title>Documents</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <span class="text-[13px] font-medium">Documents</span>
    {/snippet}

    <ul class="border-t border-line">
        {#each props.documents as document (document.id)}
            <li class="border-b border-line py-2.5">
                <a
                    href="/workspace/documents/{document.id}"
                    use:inertia
                    class="font-medium text-fg hover:text-accent"
                    >{document.title}</a
                >
            </li>
        {/each}
    </ul>
</AppShell>
