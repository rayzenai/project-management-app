<script lang="ts">
    import { tokenize } from '../lib/quickAddTokens';

    let {
        value = $bindable(''),
        placeholder = '',
        disabled = false,
        size = 'md',
        oninput,
        onsubmit,
    }: {
        value: string;
        placeholder?: string;
        disabled?: boolean;
        /** `lg` matches the command palette's 48px row; `xl` is the new-task dialog. */
        size?: 'md' | 'lg' | 'xl';
        oninput?: () => void;
        onsubmit?: () => void;
    } = $props();

    let inputEl = $state<HTMLInputElement | null>(null);
    let mirrorEl = $state<HTMLDivElement | null>(null);

    const segments = $derived(tokenize(value));

    // Both layers MUST share these metrics exactly (see the note below).
    const metrics = $derived(
        size === 'xl'
            ? 'h-16 px-5 font-sans text-[17px] leading-[64px] whitespace-pre'
            : size === 'lg'
              ? 'h-12 px-4 font-sans text-[15px] leading-[48px] whitespace-pre'
              : 'h-8 px-2.5 font-sans text-[13.5px] leading-8 whitespace-pre',
    );

    export function focus(): void {
        inputEl?.focus();
    }

    function syncScroll(): void {
        if (mirrorEl && inputEl) {
            mirrorEl.scrollLeft = inputEl.scrollLeft;
        }
    }
</script>

<!-- Transparent input stacked over an aria-hidden mirror that renders the
     tokenized text. Both layers share identical typography metrics (the
     `metrics` classes): the input's text is invisible, the mirror's is
     visible, the caret stays native. -->
<div class="relative">
    <div
        bind:this={mirrorEl}
        aria-hidden="true"
        class={`qa-mirror pointer-events-none absolute inset-0 overflow-hidden text-fg ${metrics}`}
    >
        {#each segments as seg, i (i)}
            {#if seg.type === 'plain'}{seg.text}{:else}<span
                    class={`qa-tok qa-tok-${seg.type}`}>{seg.text}</span
                >{/if}
        {/each}
    </div>
    <input
        bind:this={inputEl}
        bind:value
        type="text"
        {placeholder}
        {disabled}
        class={`relative w-full bg-transparent text-transparent caret-fg outline-none placeholder:text-fg-faint ${metrics}`}
        autocomplete="off"
        spellcheck="false"
        oninput={() => {
            syncScroll();
            oninput?.();
        }}
        onscroll={syncScroll}
        onkeydown={(e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                onsubmit?.();
            }
        }}
    />
</div>
