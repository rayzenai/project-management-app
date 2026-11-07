<script lang="ts">
    import { LoaderCircle } from '@lucide/svelte';
    import { msg, t } from '../lib/i18n';

    let {
        size = 16,
        class: className = '',
        label = msg('Loading'),
    }: {
        size?: number;
        class?: string;
        label?: string;
    } = $props();
</script>

<LoaderCircle
    {size}
    class={`animate-spin text-accent ${className}`}
    aria-label={t(label)}
    role="status"
/>
