<script lang="ts">
    import type { HTMLInputAttributes } from 'svelte/elements';
    import { tokenize } from '../lib/quickAddTokens';

    let {
        value = $bindable(''),
        placeholder = '',
        size = 'md',
        oninput,
        onsubmit,
        onkeydown,
        ...rest
    }: {
        value: string;
        placeholder?: string;
        /** `lg` is the add field on My work; `xl` is the new-task dialog. */
        size?: 'md' | 'lg' | 'xl';
        oninput?: () => void;
        onsubmit?: () => void;
        /** Runs first: a handler that calls preventDefault keeps Enter from submitting. */
        onkeydown?: (e: KeyboardEvent) => void;
    } & Omit<
        HTMLInputAttributes,
        | 'value'
        | 'placeholder'
        | 'size'
        | 'oninput'
        | 'onkeydown'
        | 'class'
        | 'type'
    > = $props();

    let inputEl = $state<HTMLInputElement | null>(null);
    let mirrorEl = $state<HTMLDivElement | null>(null);

    const segments = $derived(tokenize(value));

    // Both layers MUST share these metrics exactly (see the note below).
    const metrics = $derived(
        size === 'xl'
            ? 'h-16 px-5 font-sans text-[17px] leading-[64px] whitespace-pre'
            : size === 'lg'
              ? // 16px below lg: iOS zooms into a smaller field.
                'h-[48px] px-0 font-sans text-[16px] leading-[48px] whitespace-pre lg:text-[15px]'
              : 'h-8 px-2.5 font-sans text-[13.5px] leading-8 whitespace-pre',
    );

    export function focus(): void {
        inputEl?.focus();
    }

    /** Where the caret is in the text. */
    export function caret(): number {
        return inputEl?.selectionStart ?? value.length;
    }

    /** Focus the field with the caret at `position`. */
    export function setCaret(position: number): void {
        inputEl?.focus();
        inputEl?.setSelectionRange(position, position);
        syncScroll();
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
        {...rest}
        bind:this={inputEl}
        bind:value
        type="text"
        {placeholder}
        class={`relative w-full bg-transparent text-transparent caret-fg outline-none placeholder:text-fg-faint ${metrics}`}
        autocomplete="off"
        spellcheck="false"
        oninput={() => {
            syncScroll();
            oninput?.();
        }}
        onscroll={syncScroll}
        onkeydown={(e) => {
            onkeydown?.(e);

            // Enter that confirms an input method's composition (Nepali
            // typed through an IME) is not a submit.
            if (
                !e.defaultPrevented &&
                e.key === 'Enter' &&
                !(e.isComposing || e.keyCode === 229)
            ) {
                e.preventDefault();
                onsubmit?.();
            }
        }}
    />
</div>
