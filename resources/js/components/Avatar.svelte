<script lang="ts">
    import { initials } from '../lib/format';

    /** Initials in a neutral squircle. 20 / 24 / 28px. */
    let {
        name,
        size = 'sm',
        title,
        class: className = '',
    }: {
        name: string | null | undefined;
        size?: 'sm' | 'md' | 'lg';
        title?: string;
        class?: string;
    } = $props();

    const dim = $derived(
        size === 'lg'
            ? 'h-7 w-7 rounded-[7px] text-[11px]'
            : size === 'md'
              ? 'h-6 w-6 rounded-md text-[10px]'
              : 'h-5 w-5 rounded-[5px] text-[9.5px]',
    );
</script>

<span
    class={`inline-grid shrink-0 place-items-center border border-line bg-surface-alt font-semibold text-fg select-none ${dim} ${className}`}
    title={title ?? name ?? undefined}
>
    {initials(name)}
</span>
