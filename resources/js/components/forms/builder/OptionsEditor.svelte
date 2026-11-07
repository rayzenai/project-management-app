<script lang="ts">
    import { ArrowDown, ArrowUp, Plus, X } from '@lucide/svelte';
    import { newId } from '../../../lib/forms/ids';
    import type { Language, Option } from '../../../lib/forms/types';

    /**
     * A choice question's answers. Each keeps its id however its words
     * change, so answers already in stay right. Paste several lines into the
     * last box to add them all.
     */
    let {
        options = $bindable(),
        languages,
    }: {
        options: Option[];
        languages: Language[];
    } = $props();

    const uid = $props.id();

    function add(text = '') {
        options = [...options, { id: newId('o'), label: { en: text, ne: '' } }];
    }

    function move(i: number, by: number) {
        const next = [...options];
        const [item] = next.splice(i, 1);
        next.splice(i + by, 0, item);
        options = next;
    }

    function paste(e: ClipboardEvent, i: number, language: Language) {
        const text = e.clipboardData?.getData('text') ?? '';
        const lines = text
            .split(/\r?\n/)
            .map((l) => l.trim())
            .filter(Boolean);

        if (lines.length < 2) {
            return;
        }

        e.preventDefault();
        const next = [...options];
        next[i] = {
            ...next[i],
            label: { ...next[i].label, [language]: lines[0] },
        };
        options = [
            ...next,
            ...lines.slice(1).map((line) => ({
                id: newId('o'),
                label: {
                    en: language === 'en' ? line : '',
                    ne: language === 'ne' ? line : '',
                },
            })),
        ];
    }
</script>

<div class="flex flex-col gap-1.5">
    <span class="label">Answers to choose from</span>
    {#each options as option, i (option.id)}
        <div class="flex items-center gap-1.5">
            <span class="w-5 shrink-0 text-right text-xs text-fg-faint"
                >{i + 1}</span
            >
            {#each languages as language (language)}
                <label for="{uid}-{option.id}-{language}" class="sr-only"
                    >Answer {i + 1} ({language === 'ne'
                        ? 'Nepali'
                        : 'English'})</label
                >
                <input
                    id="{uid}-{option.id}-{language}"
                    class="input min-w-0 flex-1"
                    lang={language}
                    maxlength="300"
                    placeholder={language === 'ne'
                        ? 'नेपालीमा'
                        : `Answer ${i + 1}`}
                    value={option.label[language]}
                    oninput={(e) =>
                        (option.label = {
                            ...option.label,
                            [language]: e.currentTarget.value,
                        })}
                    onpaste={(e) => paste(e, i, language)}
                />
            {/each}
            <button
                type="button"
                class="btn-icon shrink-0"
                aria-label="Move up"
                disabled={i === 0}
                onclick={() => move(i, -1)}
                ><ArrowUp class="h-3.5 w-3.5" /></button
            >
            <button
                type="button"
                class="btn-icon shrink-0"
                aria-label="Move down"
                disabled={i === options.length - 1}
                onclick={() => move(i, 1)}
                ><ArrowDown class="h-3.5 w-3.5" /></button
            >
            <button
                type="button"
                class="btn-icon shrink-0"
                aria-label="Remove answer {i + 1}"
                onclick={() =>
                    (options = options.filter((o) => o.id !== option.id))}
                ><X class="h-3.5 w-3.5" /></button
            >
        </div>
    {/each}
    <button type="button" class="btn-ghost w-fit text-xs" onclick={() => add()}
        ><Plus class="h-3.5 w-3.5" />Add an answer</button
    >
    <p class="text-xs text-fg-faint">
        Tip: paste a list, one per line, to add them all.
    </p>
</div>
