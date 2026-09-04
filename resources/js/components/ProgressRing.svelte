<script lang="ts">
    /** A small completion ring: track in the line colour, value in the accent. */
    let {
        percent,
        size = 16,
        class: className = '',
    }: { percent: number; size?: number; class?: string } = $props();

    const r = 6;
    const c = 2 * Math.PI * r;
    const clamped = $derived(Math.max(0, Math.min(100, percent)));
</script>

<svg
    viewBox="0 0 16 16"
    width={size}
    height={size}
    class={`shrink-0 ${className}`}
    role="img"
    aria-label={`${Math.round(clamped)}% complete`}
>
    <circle
        cx="8"
        cy="8"
        {r}
        fill="none"
        stroke="var(--ws-line)"
        stroke-width="2.5"
    />
    <circle
        cx="8"
        cy="8"
        {r}
        fill="none"
        stroke="var(--ws-accent)"
        stroke-width="2.5"
        stroke-dasharray={c}
        stroke-dashoffset={c * (1 - clamped / 100)}
        transform="rotate(-90 8 8)"
    />
</svg>
