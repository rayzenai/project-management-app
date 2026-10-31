<script lang="ts">
    import { Copy } from '@lucide/svelte';
    import { writeToClipboard } from '../lib/clipboard';
    import { toast } from '../lib/toast.svelte';

    /**
     * Commands or a file to type, as they should be typed, with a button
     * that copies them whole.
     */
    let { code }: { code: string } = $props();

    function copy() {
        writeToClipboard(code);
        toast.show('Copied', { duration: 2000 });
    }
</script>

<div class="relative mt-1.5 rounded-md border border-line bg-surface-alt">
    <pre
        class="overflow-x-auto py-2 pr-10 pl-3 font-mono text-xs leading-relaxed text-fg">{code}</pre>
    <button
        type="button"
        class="btn-icon absolute top-1 right-1 h-6 w-6"
        title="Copy"
        aria-label="Copy"
        onclick={copy}><Copy class="h-3.5 w-3.5" /></button
    >
</div>
