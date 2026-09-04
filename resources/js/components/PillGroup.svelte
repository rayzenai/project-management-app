<script lang="ts">
    type Tone = 'neutral' | 'amber' | 'orange' | 'red';
    type Option = { value: string; label: string; tone?: Tone };

    /** A segmented single-select. The active segment is accent-tinted; `dot` adds a tone marker. */
    let {
        options,
        value = $bindable(''),
        size = 'md',
        dot = false,
    }: {
        options: Option[];
        value: string;
        size?: 'sm' | 'md';
        dot?: boolean;
    } = $props();

    const dotClass: Record<Tone, string> = {
        red: 'bg-danger',
        orange: 'bg-warn',
        amber: 'bg-warn',
        neutral: 'bg-fg-faint',
    };
</script>

<div
    class="inline-flex overflow-hidden rounded-md border border-line bg-surface"
    role="radiogroup"
>
    {#each options as opt, i (opt.value)}
        {@const active = value === opt.value}
        <button
            type="button"
            role="radio"
            aria-checked={active}
            class={`inline-flex items-center gap-1.5 font-medium whitespace-nowrap transition ${
                size === 'sm' ? 'h-6 px-2 text-xs' : 'h-7 px-2.5 text-[13px]'
            } ${i > 0 ? 'border-l border-line' : ''} ${
                active
                    ? 'bg-accent-soft text-accent'
                    : 'text-fg-muted hover:bg-hover hover:text-fg'
            }`}
            onclick={() => (value = opt.value)}
        >
            {#if dot}<span
                    class={`inline-block h-1.5 w-1.5 rounded-full ${dotClass[opt.tone ?? 'neutral']}`}
                ></span>{/if}{opt.label}
        </button>
    {/each}
</div>
