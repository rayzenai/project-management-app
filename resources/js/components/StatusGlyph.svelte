<script lang="ts">
    /**
     * The status icon. State carries the only colour in the UI: an empty ring
     * (not started), a dashed ring (unclear), an amber half-disc (in progress),
     * a green check (done) and a red cross (failed / blocked).
     */
    let {
        status,
        size = 14,
        class: className = '',
    }: {
        status: string | null | undefined;
        size?: number;
        class?: string;
    } = $props();

    const bucket = $derived.by(() => {
        switch (status) {
            case 'done':
                return 'done';
            case 'in_progress':
            case 'started':
                return 'progress';
            case 'failed':
            case 'blocked':
                return 'failed';
            case 'unclear':
                return 'unclear';
            default:
                return 'todo';
        }
    });

    const ring = $derived.by(() => {
        switch (bucket) {
            case 'done':
                return 'border-success bg-success';
            case 'progress':
                return 'border-warn';
            case 'failed':
                return 'border-danger bg-danger';
            case 'unclear':
                return 'border-dashed border-fg-faint';
            default:
                return 'border-fg-faint';
        }
    });
</script>

<span
    class={`relative inline-block shrink-0 rounded-full border-[1.5px] ${ring} ${className}`}
    style={`width:${size}px;height:${size}px`}
    aria-hidden="true"
>
    {#if bucket === 'progress'}
        <span
            class="absolute inset-[2px] rounded-full"
            style="background: conic-gradient(var(--ws-warn) 0 50%, transparent 50%)"
        ></span>
    {:else if bucket === 'done'}
        <svg
            viewBox="0 0 14 14"
            class="absolute inset-0 h-full w-full"
            fill="none"
            stroke="var(--ws-surface)"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M3.5 7.2l2.2 2.2 4.8-4.8" />
        </svg>
    {:else if bucket === 'failed'}
        <svg
            viewBox="0 0 14 14"
            class="absolute inset-0 h-full w-full"
            fill="none"
            stroke="var(--ws-surface)"
            stroke-width="1.8"
            stroke-linecap="round"
        >
            <path d="M4.5 4.5l5 5M9.5 4.5l-5 5" />
        </svg>
    {/if}
</span>
