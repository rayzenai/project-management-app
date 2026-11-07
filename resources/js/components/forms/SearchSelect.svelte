<script lang="ts">
    import { ChevronDown, X } from '@lucide/svelte';

    /**
     * A dropdown you can type into, for long lists (a ministry's 100
     * offices, 753 local levels): it filters as you type, in English or
     * Nepali, and arrow keys and Enter choose.
     */
    let {
        id,
        options,
        value = $bindable(),
        placeholder = 'Choose',
        disabled = false,
        invalid = false,
        onchange,
    }: {
        id: string;
        options: { value: string; label: string; search?: string }[];
        value: string | null | undefined;
        placeholder?: string;
        disabled?: boolean;
        invalid?: boolean;
        onchange?: (value: string | null) => void;
    } = $props();

    let open = $state(false);
    let query = $state('');
    let active = $state(0);
    let input = $state<HTMLInputElement | null>(null);

    const chosen = $derived(options.find((o) => o.value === value) ?? null);
    const shown = $derived.by(() => {
        const q = query.trim().toLowerCase();
        const list =
            q === ''
                ? options
                : options.filter((o) =>
                      `${o.label} ${o.search ?? ''}`.toLowerCase().includes(q),
                  );

        return list.slice(0, 200);
    });

    function pick(choice: string | null) {
        value = choice;
        onchange?.(choice);
        open = false;
        query = '';
    }

    function onKeydown(e: KeyboardEvent) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            open = true;
            active = Math.min(active + 1, shown.length - 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            active = Math.max(active - 1, 0);
        } else if (e.key === 'Enter' && open && shown[active]) {
            e.preventDefault();
            pick(shown[active].value);
        } else if (e.key === 'Escape' && open) {
            e.preventDefault();
            e.stopPropagation();
            open = false;
        }
    }
</script>

<div class="relative">
    <div class="relative">
        <input
            {id}
            bind:this={input}
            class="input pr-14"
            role="combobox"
            aria-expanded={open}
            aria-controls="{id}-list"
            aria-autocomplete="list"
            aria-invalid={invalid}
            autocomplete="off"
            {disabled}
            placeholder={chosen?.label ?? placeholder}
            value={open ? query : (chosen?.label ?? '')}
            onfocus={() => {
                open = true;
                active = 0;
            }}
            oninput={(e) => {
                query = e.currentTarget.value;
                open = true;
                active = 0;
            }}
            onblur={() => setTimeout(() => (open = false), 150)}
            onkeydown={onKeydown}
        />
        <div class="absolute inset-y-0 right-1.5 flex items-center gap-0.5">
            {#if chosen && !disabled}
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    aria-label="Clear"
                    onclick={() => pick(null)}><X class="h-3 w-3" /></button
                >
            {/if}
            <ChevronDown
                class="pointer-events-none h-3.5 w-3.5 text-fg-faint"
            />
        </div>
    </div>
    {#if open && !disabled}
        <ul
            id="{id}-list"
            role="listbox"
            class="popover absolute z-30 mt-1 max-h-64 w-full overflow-y-auto p-1"
        >
            {#each shown as option, i (option.value)}
                <li
                    role="option"
                    aria-selected={option.value === value}
                    class="menu-item cursor-pointer"
                    class:bg-hover={i === active}
                    onmousedown={(e) => {
                        e.preventDefault();
                        pick(option.value);
                    }}
                    onmouseenter={() => (active = i)}
                >
                    {option.label}
                </li>
            {:else}
                <li class="px-2 py-1.5 text-xs text-fg-muted">
                    Nothing matches.
                </li>
            {/each}
        </ul>
    {/if}
</div>
