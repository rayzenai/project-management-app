<script lang="ts">
    type Tone = 'neutral' | 'amber' | 'orange' | 'red';
    type Option = { value: string; label: string; tone?: Tone };

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
        red: 'bg-red-500',
        orange: 'bg-orange-500',
        amber: 'bg-amber-400',
        neutral: 'bg-neutral-400 dark:bg-neutral-500',
    };

    function classFor(opt: Option, active: boolean): string {
        const base =
            size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs';

        if (!active) {
            return `${base} rounded-full ring-1 ring-inset ring-line bg-surface text-fg-muted hover:bg-surface-alt`;
        }

        switch (opt.tone) {
            case 'red':
                return `${base} rounded-full ring-1 ring-inset ring-red-300 bg-red-100 text-red-800 dark:ring-red-500/40 dark:bg-red-500/15 dark:text-red-300`;
            case 'orange':
                return `${base} rounded-full ring-1 ring-inset ring-orange-300 bg-orange-100 text-orange-800 dark:ring-orange-500/40 dark:bg-orange-500/15 dark:text-orange-300`;
            case 'amber':
                return `${base} rounded-full ring-1 ring-inset ring-amber-300 bg-amber-100 text-amber-900 dark:ring-amber-500/40 dark:bg-amber-500/15 dark:text-amber-300`;
            default:
                return `${base} rounded-full ring-1 ring-inset ring-neutral-300 bg-neutral-200 text-neutral-900 dark:ring-neutral-600 dark:bg-neutral-700 dark:text-neutral-100`;
        }
    }
</script>

<div class="inline-flex flex-wrap gap-1">
    {#each options as opt (opt.value)}
        <button
            type="button"
            class={classFor(opt, value === opt.value)}
            onclick={() => (value = opt.value)}
        >
            {#if dot}<span
                    class={`mr-1.5 inline-block h-2 w-2 rounded-full align-middle ring-1 ring-black/10 ring-inset dark:ring-white/15 ${dotClass[opt.tone ?? 'neutral']}`}
                ></span>{/if}{opt.label}
        </button>
    {/each}
</div>
