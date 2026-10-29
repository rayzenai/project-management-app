<script lang="ts">
    /**
     * Says where a task's edits stand. Fields save on their own when you
     * leave them, so this line takes the place of a Save button.
     */
    let {
        saving,
        savedAt,
        dirty = false,
        error = null,
    }: {
        saving: boolean;
        savedAt: Date | null;
        dirty?: boolean;
        error?: string | null;
    } = $props();

    const time = $derived(
        savedAt?.toLocaleTimeString(undefined, {
            hour: 'numeric',
            minute: '2-digit',
        }) ?? '',
    );
</script>

<span class="truncate text-xs" aria-live="polite">
    {#if error}
        <span class="text-danger">Not saved: {error}</span>
    {:else if saving}
        <span class="text-fg-faint">Saving</span>
    {:else if dirty}
        <span class="text-fg-faint">Saves when you leave the field</span>
    {:else if savedAt}
        <span class="text-fg-faint"
            >Auto saved at <span class="font-mono tabular-nums">{time}</span
            ></span
        >
    {:else}
        <span class="text-fg-faint">Changes save automatically</span>
    {/if}
</span>
