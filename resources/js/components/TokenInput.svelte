<script lang="ts">
    import { tokenize } from '../lib/quickAddTokens';

    let {
        value = $bindable(''),
        placeholder = '',
        disabled = false,
        oninput,
        onsubmit,
    }: {
        value: string;
        placeholder?: string;
        disabled?: boolean;
        oninput?: () => void;
        onsubmit?: () => void;
    } = $props();

    let inputEl = $state<HTMLInputElement | null>(null);
    let mirrorEl = $state<HTMLDivElement | null>(null);

    const segments = $derived(tokenize(value));

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
     tokenized text. Both layers share identical typography metrics
     (px-3 py-2 font-sans text-base whitespace-pre) — that is the entire trick:
     the input's text is invisible, the mirror's is visible, the caret stays native. -->
<div class="relative">
    <div
        bind:this={mirrorEl}
        aria-hidden="true"
        class="qa-mirror pointer-events-none absolute inset-0 overflow-hidden px-3 py-2 font-sans text-base whitespace-pre"
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
        class="relative w-full bg-transparent px-3 py-2 font-sans text-base text-transparent caret-fg outline-none placeholder:text-fg-faint"
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
