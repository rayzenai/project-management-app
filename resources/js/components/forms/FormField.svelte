<script lang="ts">
    import { Plus, Trash2 } from '@lucide/svelte';
    import { say } from '../../lib/forms/answers';
    import type { Block, FileRef } from '../../lib/forms/types';
    import DateInput from './DateInput.svelte';
    import FileInput from './FileInput.svelte';
    import FormField from './FormField.svelte';
    import PlaceInput from './PlaceInput.svelte';
    import SearchSelect from './SearchSelect.svelte';

    /**
     * One question to answer, of any kind, with its label, help and the
     * problem with its answer. A repeating group draws its questions once
     * per entry, with Add another.
     */
    let {
        question,
        value = $bindable(),
        errors,
        path,
        language,
        files = $bindable(),
        formId,
        test = false,
        maxUploadMb = 20,
        disabled = false,
        onvalue,
    }: {
        question: Block;
        value: unknown;
        errors: Record<string, string>;
        /** Where its errors are: the id, or `group.0.child` inside a group. */
        path: string;
        language: 'en' | 'ne';
        files: Record<number, FileRef>;
        formId: number;
        test?: boolean;
        maxUploadMb?: number;
        disabled?: boolean;
        /** Inside a repeating group: where a change goes instead of `value`. */
        onvalue?: (value: unknown) => void;
    } = $props();

    function set(next: unknown) {
        if (onvalue) {
            onvalue(next);
        } else {
            value = next;
        }
    }

    const uid = $props.id();
    const id = $derived(`${uid}-${question.id}`);
    const rules = $derived(question.rules ?? {});
    const error = $derived(errors[path] ?? null);
    const label = $derived(say(question.label, language));
    const help = $derived(say(question.help, language));
    const options = $derived(question.options ?? []);

    const entries = $derived(
        (Array.isArray(value) ? value : []) as Record<string, unknown>[],
    );

    function setEntry(i: number, child: string, v: unknown) {
        const next = entries.map((e) => ({ ...e }));
        next[i] = { ...(next[i] ?? {}), [child]: v };
        set(next);
    }

    function toggle(option: string, on: boolean) {
        const chosen = Array.isArray(value) ? (value as string[]) : [];
        set(
            on
                ? [...chosen.filter((c) => c !== option), option]
                : chosen.filter((c) => c !== option),
        );
    }
</script>

<div class="flex flex-col gap-1.5" data-question={question.id}>
    {#if question.type === 'declaration'}
        <label class="flex items-start gap-2.5 text-[14px] text-fg">
            <input
                type="checkbox"
                class="mt-0.5 h-4 w-4"
                checked={value === true}
                {disabled}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                onchange={(e) => set(e.currentTarget.checked ? true : null)}
            />
            <span>{say(question.text, language)}</span>
        </label>
    {:else}
        <label
            for={question.type === 'choice' && rules.display !== 'dropdown'
                ? undefined
                : id}
            class="text-[14px] font-medium text-fg"
        >
            {label}{#if question.required}<span
                    class="text-danger"
                    aria-hidden="true"
                >
                    *</span
                ><span class="sr-only"> (required)</span>{/if}
        </label>
        {#if help}<p id="{id}-help" class="-mt-1 text-xs text-fg-muted">
                {help}
            </p>{/if}

        {#if question.type === 'short_text' || question.type === 'email' || question.type === 'url' || question.type === 'phone'}
            <input
                {id}
                class="input"
                type={question.type === 'email'
                    ? 'email'
                    : question.type === 'url'
                      ? 'url'
                      : question.type === 'phone'
                        ? 'tel'
                        : 'text'}
                inputmode={question.type === 'phone' ? 'tel' : undefined}
                lang={rules.format === 'devanagari' ? 'ne' : undefined}
                autocomplete="off"
                {disabled}
                aria-invalid={!!error}
                aria-describedby={error
                    ? `${id}-error`
                    : help
                      ? `${id}-help`
                      : undefined}
                maxlength={rules.max_length ?? 500}
                value={typeof value === 'string' ? value : ''}
                oninput={(e) => set(e.currentTarget.value)}
            />
        {:else if question.type === 'paragraph'}
            <textarea
                {id}
                class="input min-h-24 py-2"
                rows="4"
                {disabled}
                aria-invalid={!!error}
                aria-describedby={error
                    ? `${id}-error`
                    : help
                      ? `${id}-help`
                      : undefined}
                maxlength={rules.max_length ?? 5000}
                value={typeof value === 'string' ? value : ''}
                oninput={(e) => set(e.currentTarget.value)}
            ></textarea>
        {:else if question.type === 'number'}
            <input
                {id}
                class="input w-48"
                inputmode="decimal"
                {disabled}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                value={value === null || value === undefined
                    ? ''
                    : String(value)}
                oninput={(e) => set(e.currentTarget.value)}
            />
        {:else if question.type === 'date'}
            <DateInput
                {id}
                calendar={rules.calendar ?? 'bs'}
                bind:value={
                    () => value as { bs?: string; ad?: string } | null,
                    (v) => set(v)
                }
                {language}
                {disabled}
                invalid={!!error}
            />
        {:else if question.type === 'choice' && rules.display === 'dropdown'}
            <SearchSelect
                {id}
                options={options.map((o) => ({
                    value: o.id,
                    label: say(o.label, language),
                    search: `${o.label.en} ${o.label.ne}`,
                }))}
                value={typeof value === 'string' ? value : null}
                {disabled}
                invalid={!!error}
                onchange={(v) => set(v)}
            />
        {:else if question.type === 'choice'}
            <div
                class="flex flex-col gap-1.5"
                role="radiogroup"
                aria-label={label}
                aria-invalid={!!error}
            >
                {#each options as option (option.id)}
                    <label
                        class="flex items-center gap-2.5 text-[14px] text-fg"
                    >
                        <input
                            type="radio"
                            name={id}
                            class="h-4 w-4"
                            checked={value === option.id}
                            {disabled}
                            onchange={() => set(option.id)}
                        />
                        {say(option.label, language)}
                    </label>
                {/each}
                {#if typeof value === 'string' && !question.required && !disabled}
                    <button
                        type="button"
                        class="btn-ghost w-fit text-xs"
                        onclick={() => set(null)}>Clear the choice</button
                    >
                {/if}
            </div>
        {:else if question.type === 'choices'}
            <div class="flex flex-col gap-1.5" role="group" aria-label={label}>
                {#each options as option (option.id)}
                    <label
                        class="flex items-center gap-2.5 text-[14px] text-fg"
                    >
                        <input
                            type="checkbox"
                            class="h-4 w-4"
                            checked={Array.isArray(value) &&
                                (value as string[]).includes(option.id)}
                            {disabled}
                            onchange={(e) =>
                                toggle(option.id, e.currentTarget.checked)}
                        />
                        {say(option.label, language)}
                    </label>
                {/each}
            </div>
        {:else if question.type === 'yes_no'}
            <div class="flex gap-2" role="radiogroup" aria-label={label}>
                {#each [{ v: true, l: language === 'ne' ? 'हो' : 'Yes' }, { v: false, l: language === 'ne' ? 'होइन' : 'No' }] as choice (choice.l)}
                    <label
                        class="btn cursor-pointer"
                        class:btn-primary={value === choice.v}
                    >
                        <input
                            type="radio"
                            name={id}
                            class="sr-only"
                            checked={value === choice.v}
                            {disabled}
                            onchange={() => set(choice.v)}
                        />
                        {choice.l}
                    </label>
                {/each}
            </div>
        {:else if question.type === 'scale'}
            <div class="flex flex-col gap-1">
                <div
                    class="flex flex-wrap gap-1.5"
                    role="radiogroup"
                    aria-label={label}
                >
                    {#each Array.from({ length: rules.max ?? 5 }, (_, i) => i + 1) as n (n)}
                        <label
                            class="btn w-10 cursor-pointer justify-center"
                            class:btn-primary={value === n}
                        >
                            <input
                                type="radio"
                                name={id}
                                class="sr-only"
                                checked={value === n}
                                {disabled}
                                onchange={() => set(n)}
                            />
                            {n}
                        </label>
                    {/each}
                </div>
                {#if say(rules.min_label, language) || say(rules.max_label, language)}
                    <div
                        class="flex max-w-[calc(var(--spacing)*11*10)] justify-between text-xs text-fg-muted"
                    >
                        <span>1: {say(rules.min_label, language)}</span>
                        <span
                            >{rules.max ?? 5}: {say(
                                rules.max_label,
                                language,
                            )}</span
                        >
                    </div>
                {/if}
            </div>
        {:else if question.type === 'file'}
            <FileInput
                {id}
                {formId}
                block={question.id}
                {test}
                bind:value={() => value as number[] | null, (v) => set(v)}
                bind:files
                kinds={rules.kinds ?? ['pdf', 'image']}
                maxFiles={rules.max_files ?? 1}
                maxMb={Math.min(rules.max_mb ?? 10, maxUploadMb)}
                {disabled}
                invalid={!!error}
            />
        {:else if question.type === 'place'}
            <PlaceInput
                {id}
                depth={rules.depth ?? 'local'}
                bind:value={
                    () => value as Record<string, never> | null, (v) => set(v)
                }
                {language}
                {disabled}
                invalid={!!error}
            />
        {:else if question.type === 'group'}
            <div class="flex flex-col gap-3">
                {#each entries as entry, i (i)}
                    <fieldset class="rounded-md border border-line p-3">
                        <legend class="px-1 text-xs font-medium text-fg-muted"
                            >{label} {i + 1}</legend
                        >
                        <div class="flex flex-col gap-4">
                            {#each question.blocks ?? [] as child (child.id)}
                                <FormField
                                    question={child}
                                    value={entry[child.id]}
                                    {errors}
                                    path="{path}.{i}.{child.id}"
                                    {language}
                                    bind:files
                                    {formId}
                                    {test}
                                    {maxUploadMb}
                                    {disabled}
                                    onvalue={(v: unknown) =>
                                        setEntry(i, child.id, v)}
                                />
                            {/each}
                        </div>
                        {#if !disabled}
                            <button
                                type="button"
                                class="btn-ghost mt-2 text-xs"
                                onclick={() =>
                                    set(entries.filter((_, j) => j !== i))}
                            >
                                <Trash2 class="h-3.5 w-3.5" />Remove {label}
                                {i + 1}
                            </button>
                        {/if}
                    </fieldset>
                {/each}
                {#if !disabled && entries.length < (rules.max ?? 50)}
                    <button
                        type="button"
                        class="btn w-fit"
                        onclick={() => set([...entries, {}])}
                    >
                        <Plus class="h-3.5 w-3.5" />{say(
                            rules.add_label,
                            language,
                        ) || (entries.length ? 'Add another' : 'Add one')}
                    </button>
                {/if}
            </div>
        {/if}
    {/if}
    {#if error}
        <p id="{id}-error" class="text-xs text-danger" role="alert">{error}</p>
    {/if}
</div>
