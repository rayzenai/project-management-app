<script lang="ts">
    import type { Language, Translations } from '../../../lib/forms/types';

    /**
     * Words in each of the form's languages: one box per language, marked
     * EN and ने when the form has both.
     */
    let {
        value = $bindable(),
        languages,
        label,
        multiline = false,
        placeholder = '',
        max = 500,
    }: {
        value: Translations | undefined;
        languages: Language[];
        label: string;
        multiline?: boolean;
        placeholder?: string;
        max?: number;
    } = $props();

    const uid = $props.id();

    function set(language: Language, text: string) {
        value = { en: value?.en ?? '', ne: value?.ne ?? '', [language]: text };
    }
</script>

<div class="flex flex-col gap-1">
    <span class="label">{label}</span>
    {#each languages as language (language)}
        <div class="flex items-start gap-1.5">
            {#if languages.length > 1}
                <span
                    class="mt-2 w-6 shrink-0 text-center text-[11px] font-medium text-fg-faint"
                    aria-hidden="true">{language === 'ne' ? 'ने' : 'EN'}</span
                >
            {/if}
            <label for="{uid}-{language}" class="sr-only"
                >{label} ({language === 'ne' ? 'Nepali' : 'English'})</label
            >
            {#if multiline}
                <textarea
                    id="{uid}-{language}"
                    class="input min-h-16 py-1.5"
                    rows="2"
                    maxlength={max}
                    lang={language}
                    {placeholder}
                    value={value?.[language] ?? ''}
                    oninput={(e) => set(language, e.currentTarget.value)}
                ></textarea>
            {:else}
                <input
                    id="{uid}-{language}"
                    class="input"
                    maxlength={max}
                    lang={language}
                    {placeholder}
                    value={value?.[language] ?? ''}
                    oninput={(e) => set(language, e.currentTarget.value)}
                />
            {/if}
        </div>
    {/each}
</div>
