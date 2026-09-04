<script lang="ts">
    import { Check, X } from '@lucide/svelte';
    import { fly } from 'svelte/transition';
    import { toast } from '../lib/toast.svelte';
</script>

<div
    class="pointer-events-none fixed inset-x-0 bottom-5 z-[70] flex flex-col items-center gap-2 px-4"
>
    {#each toast.items as item (item.id)}
        <div
            role="status"
            transition:fly={{ y: 10, duration: 150 }}
            class="popover pointer-events-auto flex max-w-md min-w-64 items-center gap-3 px-3.5 py-2.5 text-[13px]"
            onmouseenter={() => toast.pause(item.id)}
            onmouseleave={() => toast.resume(item.id)}
        >
            {#if item.variant === 'error'}
                <X class="h-3.5 w-3.5 shrink-0 text-danger" />
            {:else if item.variant === 'success'}
                <Check class="h-3.5 w-3.5 shrink-0 text-success" />
            {:else}
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-fg-faint"
                ></span>
            {/if}
            <span class="min-w-0 flex-1 truncate text-fg">{item.message}</span>
            {#if item.undo}
                <button
                    type="button"
                    class="text-xs font-medium text-accent hover:underline"
                    onclick={() => toast.undo(item.id)}
                >
                    {item.undo.label}
                </button>
            {/if}
            <button
                type="button"
                aria-label="Dismiss"
                class="btn-icon h-6 w-6"
                onclick={() => toast.dismiss(item.id)}
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>
    {/each}
</div>
