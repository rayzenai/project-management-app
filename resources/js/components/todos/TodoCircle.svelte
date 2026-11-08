<script lang="ts">
    import { Check } from '@lucide/svelte';
    import { t } from '../../lib/i18n';

    /**
     * A private to-do's done circle, drawn as My work's task circle: 24px
     * on a phone, 18px from `sm`, 44px to tap; the left half filled in the
     * accent while you work on it ("doing"), green with a check when done.
     */
    let {
        title,
        done,
        doing = false,
        onclick,
    }: {
        title: string;
        done: boolean;
        doing?: boolean;
        onclick: () => void;
    } = $props();

    const started = $derived(doing && !done);
</script>

<button
    type="button"
    role="checkbox"
    aria-checked={done}
    aria-label={done
        ? t('Mark ":title" not done', { title })
        : started
          ? t('Doing ":title", mark done', { title })
          : t('Mark ":title" done', { title })}
    class={`group/check relative flex size-[24px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition before:absolute before:-inset-[10px] before:content-[''] sm:size-[18px] sm:before:-inset-[13px] ${
        done
            ? 'border-success bg-success text-white'
            : `${started ? 'border-accent' : 'border-fg-faint'} text-transparent hover:border-success hover:text-success`
    }`}
    {onclick}
>
    {#if started}
        <!-- Doing: the left half filled; the check takes over on hover. -->
        <span
            class="pointer-events-none absolute inset-y-0 left-0 w-1/2 rounded-l-full bg-accent transition group-hover/check:opacity-0"
            aria-hidden="true"
        ></span>
    {/if}
    <Check class="relative size-[14px] sm:size-[11px]" strokeWidth={3} />
</button>
