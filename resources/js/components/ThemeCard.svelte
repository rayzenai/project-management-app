<script lang="ts">
    import type { ConfigTheme } from '../lib/appearance';

    let {
        theme,
        selected = false,
        onselect,
    }: {
        theme: ConfigTheme;
        selected?: boolean;
        onselect?: () => void;
    } = $props();

    /**
     * System has no tokens of its own; preview it with a neutral dark palette so
     * the card still reads as a real swatch (the live OS-driven preview happens
     * once it is selected). Concrete themes preview their OWN tokens inline so
     * each card shows its palette + fonts regardless of the active theme.
     */
    const SYSTEM_PREVIEW = {
        color: {
            bg: '#0e0f12',
            surface: '#16181d',
            surfaceAlt: '#1c1f25',
            line: '#2a2e36',
            text: '#e7e9ee',
            textMuted: '#98a0ac',
            accent: '#6f8bff',
        },
        font: { display: 'Inter Tight, sans-serif', body: 'Inter, sans-serif' },
    };

    const c = $derived(
        theme.key === 'system' ? SYSTEM_PREVIEW.color : theme.tokens!.color,
    );
    const f = $derived(
        theme.key === 'system' ? SYSTEM_PREVIEW.font : theme.tokens!.font,
    );
</script>

<button
    type="button"
    onclick={onselect}
    aria-pressed={selected}
    class="group relative block w-full overflow-hidden rounded-xl border text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
    class:border-accent={selected}
    class:border-line={!selected}
    style={`background:${c.surface};border-color:${selected ? c.accent : c.line}`}
>
    <!-- Mini mock rendered in the theme's OWN palette + fonts -->
    <div class="p-3" style={`background:${c.bg}`}>
        <div
            class="rounded-lg p-2.5"
            style={`background:${c.surface};border:1px solid ${c.line}`}
        >
            <div class="mb-2 flex items-center gap-2">
                <span
                    class="h-2.5 w-2.5 rounded-full"
                    style={`background:${c.accent}`}
                ></span>
                <span
                    class="text-[0.7rem] font-bold tracking-tight"
                    style={`color:${c.text};font-family:${f.display}`}
                    >{theme.label}</span
                >
            </div>
            <div class="space-y-1.5">
                <div
                    class="h-1.5 w-full rounded-full"
                    style={`background:${c.surfaceAlt}`}
                ></div>
                <div
                    class="h-1.5 w-3/4 rounded-full"
                    style={`background:${c.line}`}
                ></div>
                <div class="flex items-center gap-1.5 pt-0.5">
                    <span
                        class="h-1.5 w-1.5 rounded-full"
                        style={`background:${c.accent}`}
                    ></span>
                    <span
                        class="text-[0.55rem]"
                        style={`color:${c.textMuted};font-family:${f.body}`}
                        >Aa preview</span
                    >
                </div>
            </div>
        </div>
    </div>

    <div
        class="flex items-center justify-between px-3 py-2"
        style={`background:${c.surface};border-top:1px solid ${c.line}`}
    >
        <span
            class="text-xs font-medium"
            style={`color:${c.text};font-family:${f.body}`}>{theme.label}</span
        >
        {#if selected}
            <span
                class="flex h-4 w-4 items-center justify-center rounded-full text-[0.6rem] font-bold"
                style={`background:${c.accent};color:${c.bg}`}>✓</span
            >
        {/if}
    </div>
</button>
