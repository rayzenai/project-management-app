<script lang="ts">
    /**
     * The language switch's mark: क and A on a diagonal, the two scripts the
     * app reads in (Lucide's "languages" icon shows a Chinese character).
     * Decorative; the switch says the language in words. It takes the icon
     * classes of its neighbours (`h-4 w-4` and a text colour).
     */
    let { class: className = 'h-4 w-4' }: { class?: string } = $props();
</script>

<svg
    viewBox="0 0 16 16"
    class={`shrink-0 ${className}`}
    aria-hidden="true"
    fill="currentColor"
>
    <text
        x="-0.4"
        y="9.6"
        font-family="Mukta, 'Noto Sans Devanagari', sans-serif"
        font-size="12.5"
        font-weight="600">क</text
    >
    <text
        x="8.6"
        y="15.6"
        font-family="Geist, ui-sans-serif, system-ui, sans-serif"
        font-size="9"
        font-weight="600">A</text
    >
</svg>
