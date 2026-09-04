<script lang="ts">
    import { LoaderCircle } from '@lucide/svelte';

    let {
        size = 16,
        class: className = '',
        label = 'Loading',
    }: {
        size?: number;
        class?: string;
        label?: string;
    } = $props();
</script>

<LoaderCircle
    {size}
    class={`animate-spin text-accent ${className}`}
    aria-label={label}
    role="status"
/>
