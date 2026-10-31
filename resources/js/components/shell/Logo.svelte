<script lang="ts">
    /** The Workspace mark: a blue square with a dial. */
    let { class: className = 'h-5 w-5' }: { class?: string } = $props();
</script>

<svg
    class={`shrink-0 ${className}`}
    viewBox="0 0 32 32"
    role="img"
    aria-label="Workspace"
>
    <rect width="32" height="32" rx="8" class="fill-accent" />
    <circle
        cx="16"
        cy="16"
        r="9"
        fill="none"
        stroke="#fff"
        stroke-width="2.4"
    />
    <path d="M16 16V7a9 9 0 1 1-7.28 14.29z" fill="#fff" />
</svg>
