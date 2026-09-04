<script lang="ts">
    import StatusGlyph from './StatusGlyph.svelte';

    let {
        status,
        label,
    }: { status: string | null | undefined; label?: string | null } = $props();
</script>

<span
    class="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap text-fg-muted"
>
    <StatusGlyph {status} size={12} />
    {label || status || 'Unknown'}
</span>
