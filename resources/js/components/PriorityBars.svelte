<script lang="ts">
    import type { Priority } from '../lib/types';

    /** Three signal bars: low lights one, medium two, high all three, urgent all three in red. */
    let {
        priority,
        class: className = '',
    }: { priority: Priority | null | undefined; class?: string } = $props();

    const level = $derived.by(() => {
        switch (priority) {
            case 'urgent':
                return 3;
            case 'high':
                return 3;
            case 'medium':
                return 2;
            case 'low':
                return 1;
            default:
                return 0;
        }
    });

    const lit = $derived(
        priority === 'urgent'
            ? 'bg-danger'
            : priority === 'high'
              ? 'bg-fg-muted'
              : 'bg-fg-faint',
    );
</script>

<span
    class={`inline-flex h-[11px] shrink-0 items-end gap-[1.5px] ${className}`}
    aria-hidden="true"
>
    {#each [4, 7, 10] as h, i (h)}
        <span
            class={`w-[3px] rounded-[1px] ${i < level ? lit : 'bg-fg-faint opacity-35'}`}
            style={`height:${h}px`}
        ></span>
    {/each}
</span>
