<script lang="ts">
    import {
        ArrowDown,
        ArrowUp,
        ChevronDown,
        ChevronRight,
        Copy,
        Trash2,
    } from '@lucide/svelte';
    import { say } from '../../../lib/forms/answers';
    import { newId } from '../../../lib/forms/ids';
    import type {
        Block,
        FileRef,
        Language,
        Rules,
    } from '../../../lib/forms/types';
    import { isQuestion } from '../../../lib/forms/types';
    import RichTextEditor from '../RichTextEditor.svelte';
    import AddBlockMenu from './AddBlockMenu.svelte';
    import BlockEditor from './BlockEditor.svelte';
    import { KINDS, newBlock } from './catalog';
    import ConditionEditor from './ConditionEditor.svelte';
    import ContentUpload from './ContentUpload.svelte';
    import OptionsEditor from './OptionsEditor.svelte';
    import TranslationInput from './TranslationInput.svelte';

    /**
     * One block in the builder: folded to its name, or open to edit its
     * words, whether it must be answered, its checks and when it shows.
     * Inside a repeating group (`inGroup`) a question has no condition of
     * its own.
     */
    let {
        block = $bindable(),
        languages,
        earlier,
        formId,
        files = $bindable(),
        open = $bindable(false),
        problems = [],
        first = false,
        last = false,
        inGroup = false,
        onmove,
        onduplicate,
        onremove,
    }: {
        block: Block;
        languages: Language[];
        earlier: Block[];
        formId: number;
        files: Record<number, FileRef>;
        open?: boolean;
        problems?: string[];
        first?: boolean;
        last?: boolean;
        inGroup?: boolean;
        onmove: (by: number) => void;
        onduplicate: () => void;
        onremove: () => void;
    } = $props();

    const uid = $props.id();
    const question = $derived(isQuestion(block.type));
    const kinds = KINDS as Record<string, { name: string; icon: typeof Copy }>;
    const kind = $derived(
        kinds[block.type] ?? { name: block.type, icon: Copy },
    );
    const rules = $derived(block.rules ?? {});
    const title = $derived(
        question
            ? say(block.label, languages[0]) || 'Untitled question'
            : block.type === 'heading'
              ? say(block.text, languages[0]) || 'Heading'
              : block.type === 'download'
                ? say(block.label, languages[0]) || 'File to download'
                : kind.name,
    );
    let openChild = $state<string | null>(null);

    function rule<K extends keyof Rules>(key: K, value: Rules[K] | undefined) {
        const next: Rules = { ...(block.rules ?? {}) };

        if (
            value === undefined ||
            value === null ||
            (typeof value === 'number' && Number.isNaN(value))
        ) {
            delete next[key];
        } else {
            next[key] = value;
        }

        block.rules = next;
    }

    const num = (v: string): number | undefined =>
        v.trim() === '' ? undefined : Number(v);

    function addChild(type: Block['type']) {
        const child = newBlock(type);
        delete child.show_if;
        block.blocks = [...(block.blocks ?? []), child];
        openChild = child.id;
    }

    function moveChild(i: number, by: number) {
        const next = [...(block.blocks ?? [])];
        const [item] = next.splice(i, 1);
        next.splice(i + by, 0, item);
        block.blocks = next;
    }

    function copyOf(source: Block): Block {
        const copy = structuredClone($state.snapshot(source)) as Block;
        copy.id = newId('q');
        copy.options = copy.options?.map((o) => ({ ...o, id: newId('o') }));

        return copy;
    }
</script>

<div
    class="rounded-md border {problems.length
        ? 'border-danger'
        : 'border-line'} bg-surface"
    id="block-{block.id}"
>
    <div class="flex items-center gap-1.5 px-2 py-1.5">
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-2 text-left"
            aria-expanded={open}
            onclick={() => (open = !open)}
        >
            {#if open}<ChevronDown
                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                />{:else}<ChevronRight
                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                />{/if}
            <kind.icon class="h-4 w-4 shrink-0 text-fg-muted" />
            <span class="min-w-0 flex-1 truncate text-[13px] text-fg"
                >{title}</span
            >
            {#if question && block.required}<span class="chip shrink-0"
                    >Required</span
                >{/if}
            {#if block.show_if}<span class="chip chip-accent shrink-0"
                    >Shown when…</span
                >{/if}
            <span class="hidden shrink-0 text-xs text-fg-faint sm:inline"
                >{kind.name}</span
            >
        </button>
        <button
            type="button"
            class="btn-icon shrink-0"
            aria-label="Move up"
            disabled={first}
            onclick={() => onmove(-1)}><ArrowUp class="h-3.5 w-3.5" /></button
        >
        <button
            type="button"
            class="btn-icon shrink-0"
            aria-label="Move down"
            disabled={last}
            onclick={() => onmove(1)}><ArrowDown class="h-3.5 w-3.5" /></button
        >
        <button
            type="button"
            class="btn-icon shrink-0"
            aria-label="Duplicate"
            title="Duplicate"
            onclick={onduplicate}><Copy class="h-3.5 w-3.5" /></button
        >
        <button
            type="button"
            class="btn-icon shrink-0"
            aria-label="Delete"
            title="Delete"
            onclick={onremove}><Trash2 class="h-3.5 w-3.5" /></button
        >
    </div>

    {#if problems.length && !open}
        <p class="px-3 pb-2 text-xs text-danger">{problems[0]}</p>
    {/if}

    {#if open}
        <div class="flex flex-col gap-3 border-t border-line-soft p-3">
            {#each problems as problem (problem)}<p class="text-xs text-danger">
                    {problem}
                </p>{/each}

            {#if question}
                {#if block.type === 'declaration'}
                    <TranslationInput
                        bind:value={block.text}
                        {languages}
                        label="What they confirm"
                        multiline
                        max={1000}
                    />
                    <TranslationInput
                        bind:value={block.label}
                        {languages}
                        label="Short name (for the responses table)"
                    />
                {:else}
                    <TranslationInput
                        bind:value={block.label}
                        {languages}
                        label="Question"
                    />
                    <TranslationInput
                        bind:value={block.help}
                        {languages}
                        label="Help (optional)"
                        multiline
                        max={1000}
                    />
                    <label class="flex items-center gap-2 text-[13px] text-fg">
                        <input
                            type="checkbox"
                            checked={block.required}
                            onchange={(e) =>
                                (block.required = e.currentTarget.checked)}
                        />
                        Must be answered
                    </label>
                {/if}

                {#if block.type === 'short_text'}
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="sm:col-span-3">
                            <label for="{uid}-format" class="label mb-1 block"
                                >Check the answer</label
                            >
                            <select
                                id="{uid}-format"
                                class="input"
                                value={rules.format ?? 'any'}
                                onchange={(e) =>
                                    rule(
                                        'format',
                                        e.currentTarget
                                            .value as Rules['format'],
                                    )}
                            >
                                <option value="any">Any text</option>
                                <option value="devanagari"
                                    >Nepali (Unicode) only, not Preeti</option
                                >
                                <option value="latin"
                                    >English letters only</option
                                >
                                <option value="digits">Digits only</option>
                                <option value="pattern"
                                    >A pattern of my own (advanced)</option
                                >
                            </select>
                        </div>
                        {#if rules.format === 'pattern'}
                            <div class="sm:col-span-3">
                                <label
                                    for="{uid}-pattern"
                                    class="label mb-1 block"
                                    >Pattern (a regular expression the whole
                                    answer must match)</label
                                >
                                <input
                                    id="{uid}-pattern"
                                    class="input font-mono"
                                    value={rules.pattern ?? ''}
                                    oninput={(e) =>
                                        rule('pattern', e.currentTarget.value)}
                                    placeholder="[A-Z]{'{2}'}-[0-9]{'{4}'}"
                                />
                            </div>
                        {/if}
                        <div>
                            <label for="{uid}-minl" class="label mb-1 block"
                                >Fewest characters</label
                            >
                            <input
                                id="{uid}-minl"
                                class="input"
                                inputmode="numeric"
                                value={rules.min_length ?? ''}
                                oninput={(e) =>
                                    rule(
                                        'min_length',
                                        num(e.currentTarget.value),
                                    )}
                            />
                        </div>
                        <div>
                            <label for="{uid}-maxl" class="label mb-1 block"
                                >Most characters</label
                            >
                            <input
                                id="{uid}-maxl"
                                class="input"
                                inputmode="numeric"
                                placeholder="500"
                                value={rules.max_length ?? ''}
                                oninput={(e) =>
                                    rule(
                                        'max_length',
                                        num(e.currentTarget.value),
                                    )}
                            />
                        </div>
                    </div>
                {:else if block.type === 'paragraph'}
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div>
                            <label for="{uid}-minl" class="label mb-1 block"
                                >Fewest characters</label
                            >
                            <input
                                id="{uid}-minl"
                                class="input"
                                inputmode="numeric"
                                value={rules.min_length ?? ''}
                                oninput={(e) =>
                                    rule(
                                        'min_length',
                                        num(e.currentTarget.value),
                                    )}
                            />
                        </div>
                        <div>
                            <label for="{uid}-maxl" class="label mb-1 block"
                                >Most characters</label
                            >
                            <input
                                id="{uid}-maxl"
                                class="input"
                                inputmode="numeric"
                                placeholder="5000"
                                value={rules.max_length ?? ''}
                                oninput={(e) =>
                                    rule(
                                        'max_length',
                                        num(e.currentTarget.value),
                                    )}
                            />
                        </div>
                    </div>
                {:else if block.type === 'number'}
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div>
                            <label for="{uid}-min" class="label mb-1 block"
                                >Lowest</label
                            >
                            <input
                                id="{uid}-min"
                                class="input"
                                inputmode="decimal"
                                value={rules.min ?? ''}
                                oninput={(e) =>
                                    rule('min', num(e.currentTarget.value))}
                            />
                        </div>
                        <div>
                            <label for="{uid}-max" class="label mb-1 block"
                                >Highest</label
                            >
                            <input
                                id="{uid}-max"
                                class="input"
                                inputmode="decimal"
                                value={rules.max ?? ''}
                                oninput={(e) =>
                                    rule('max', num(e.currentTarget.value))}
                            />
                        </div>
                        <label
                            class="flex items-end gap-2 pb-2 text-[13px] text-fg"
                        >
                            <input
                                type="checkbox"
                                checked={rules.integer ?? false}
                                onchange={(e) =>
                                    rule(
                                        'integer',
                                        e.currentTarget.checked || undefined,
                                    )}
                            />
                            Whole numbers only
                        </label>
                    </div>
                {:else if block.type === 'date'}
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div>
                            <label for="{uid}-cal" class="label mb-1 block"
                                >Calendar</label
                            >
                            <select
                                id="{uid}-cal"
                                class="input"
                                value={rules.calendar ?? 'bs'}
                                onchange={(e) =>
                                    rule(
                                        'calendar',
                                        e.currentTarget.value as 'bs' | 'ad',
                                    )}
                            >
                                <option value="bs">BS (Bikram Sambat)</option>
                                <option value="ad">AD</option>
                            </select>
                        </div>
                        <div>
                            <label for="{uid}-early" class="label mb-1 block"
                                >Earliest (AD)</label
                            >
                            <input
                                id="{uid}-early"
                                type="date"
                                class="input"
                                value={rules.earliest ?? ''}
                                onchange={(e) =>
                                    rule(
                                        'earliest',
                                        e.currentTarget.value || undefined,
                                    )}
                            />
                        </div>
                        <div>
                            <label for="{uid}-late" class="label mb-1 block"
                                >Latest (AD)</label
                            >
                            <input
                                id="{uid}-late"
                                type="date"
                                class="input"
                                value={rules.latest ?? ''}
                                onchange={(e) =>
                                    rule(
                                        'latest',
                                        e.currentTarget.value || undefined,
                                    )}
                            />
                        </div>
                        <div>
                            <label for="{uid}-amin" class="label mb-1 block"
                                >Youngest age</label
                            >
                            <input
                                id="{uid}-amin"
                                class="input"
                                inputmode="numeric"
                                value={rules.age_min ?? ''}
                                oninput={(e) =>
                                    rule('age_min', num(e.currentTarget.value))}
                            />
                        </div>
                        <div>
                            <label for="{uid}-amax" class="label mb-1 block"
                                >Oldest age</label
                            >
                            <input
                                id="{uid}-amax"
                                class="input"
                                inputmode="numeric"
                                value={rules.age_max ?? ''}
                                oninput={(e) =>
                                    rule('age_max', num(e.currentTarget.value))}
                            />
                        </div>
                        <div>
                            <label for="{uid}-aon" class="label mb-1 block"
                                >Age on (AD; empty: the day they answer)</label
                            >
                            <input
                                id="{uid}-aon"
                                type="date"
                                class="input"
                                value={rules.age_on ?? ''}
                                onchange={(e) =>
                                    rule(
                                        'age_on',
                                        e.currentTarget.value || undefined,
                                    )}
                            />
                        </div>
                    </div>
                {:else if block.type === 'choice' || block.type === 'choices'}
                    <OptionsEditor
                        bind:options={block.options as never}
                        {languages}
                    />
                    {#if block.type === 'choice'}
                        <div>
                            <label for="{uid}-display" class="label mb-1 block"
                                >Show as</label
                            >
                            <select
                                id="{uid}-display"
                                class="input w-auto"
                                value={rules.display ?? 'radio'}
                                onchange={(e) =>
                                    rule(
                                        'display',
                                        e.currentTarget.value as
                                            'radio' | 'dropdown',
                                    )}
                            >
                                <option value="radio">Buttons, all shown</option
                                >
                                <option value="dropdown"
                                    >A list they can search (for long lists)</option
                                >
                            </select>
                        </div>
                    {:else}
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div>
                                <label for="{uid}-cmin" class="label mb-1 block"
                                    >Choose at least</label
                                >
                                <input
                                    id="{uid}-cmin"
                                    class="input"
                                    inputmode="numeric"
                                    value={rules.min ?? ''}
                                    oninput={(e) =>
                                        rule('min', num(e.currentTarget.value))}
                                />
                            </div>
                            <div>
                                <label for="{uid}-cmax" class="label mb-1 block"
                                    >Choose at most</label
                                >
                                <input
                                    id="{uid}-cmax"
                                    class="input"
                                    inputmode="numeric"
                                    value={rules.max ?? ''}
                                    oninput={(e) =>
                                        rule('max', num(e.currentTarget.value))}
                                />
                            </div>
                        </div>
                    {/if}
                {:else if block.type === 'scale'}
                    <div>
                        <label for="{uid}-smax" class="label mb-1 block"
                            >From 1 to</label
                        >
                        <select
                            id="{uid}-smax"
                            class="input w-auto"
                            value={String(rules.max ?? 5)}
                            onchange={(e) =>
                                rule('max', Number(e.currentTarget.value))}
                        >
                            <option value="5">5</option>
                            <option value="10">10</option>
                        </select>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <TranslationInput
                            bind:value={
                                () => rules.min_label,
                                (v) => rule('min_label', v)
                            }
                            {languages}
                            label="What 1 means"
                            max={60}
                        />
                        <TranslationInput
                            bind:value={
                                () => rules.max_label,
                                (v) => rule('max_label', v)
                            }
                            {languages}
                            label="What the top means"
                            max={60}
                        />
                    </div>
                {:else if block.type === 'file'}
                    <fieldset>
                        <legend class="label mb-1">Kinds of file</legend>
                        <div class="flex flex-wrap gap-3">
                            {#each [['pdf', 'PDF'], ['image', 'Pictures'], ['word', 'Word'], ['excel', 'Excel']] as [value, name] (value)}
                                <label
                                    class="flex items-center gap-1.5 text-[13px] text-fg"
                                >
                                    <input
                                        type="checkbox"
                                        checked={(
                                            rules.kinds ?? ['pdf', 'image']
                                        ).includes(value as never)}
                                        onchange={(e) => {
                                            const kindsNow = new Set(
                                                rules.kinds ?? ['pdf', 'image'],
                                            );

                                            if (e.currentTarget.checked) {
                                                kindsNow.add(value as never);
                                            } else {
                                                kindsNow.delete(value as never);
                                            }

                                            rule('kinds', [
                                                ...kindsNow,
                                            ] as Rules['kinds']);
                                        }}
                                    />
                                    {name}
                                </label>
                            {/each}
                        </div>
                    </fieldset>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div>
                            <label for="{uid}-mb" class="label mb-1 block"
                                >Largest file (MB)</label
                            >
                            <input
                                id="{uid}-mb"
                                class="input"
                                inputmode="numeric"
                                value={rules.max_mb ?? 10}
                                oninput={(e) =>
                                    rule('max_mb', num(e.currentTarget.value))}
                            />
                        </div>
                        <div>
                            <label for="{uid}-files" class="label mb-1 block"
                                >How many files</label
                            >
                            <input
                                id="{uid}-files"
                                class="input"
                                inputmode="numeric"
                                value={rules.max_files ?? 1}
                                oninput={(e) =>
                                    rule(
                                        'max_files',
                                        num(e.currentTarget.value),
                                    )}
                            />
                        </div>
                    </div>
                {:else if block.type === 'place'}
                    <div>
                        <label for="{uid}-depth" class="label mb-1 block"
                            >Down to</label
                        >
                        <select
                            id="{uid}-depth"
                            class="input w-auto"
                            value={rules.depth ?? 'local'}
                            onchange={(e) =>
                                rule(
                                    'depth',
                                    e.currentTarget.value as Rules['depth'],
                                )}
                        >
                            <option value="province">Province</option>
                            <option value="district">District</option>
                            <option value="local">Local level (palika)</option>
                            <option value="ward">Ward</option>
                        </select>
                    </div>
                {:else if block.type === 'group'}
                    <div class="flex flex-col gap-2">
                        <span class="label">Questions in each entry</span>
                        {#each block.blocks ?? [] as child, i (child.id)}
                            <BlockEditor
                                bind:block={(block.blocks as Block[])[i]}
                                {languages}
                                earlier={[]}
                                {formId}
                                bind:files
                                open={openChild === child.id}
                                first={i === 0}
                                last={i === (block.blocks?.length ?? 0) - 1}
                                inGroup
                                onmove={(by) => moveChild(i, by)}
                                onduplicate={() =>
                                    (block.blocks = [
                                        ...(block.blocks ?? []).slice(0, i + 1),
                                        copyOf(child),
                                        ...(block.blocks ?? []).slice(i + 1),
                                    ])}
                                onremove={() =>
                                    (block.blocks = (block.blocks ?? []).filter(
                                        (c) => c.id !== child.id,
                                    ))}
                            />
                        {/each}
                        <AddBlockMenu
                            inGroup
                            label="Add a question to the group"
                            onadd={addChild}
                        />
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div>
                                <label for="{uid}-gmin" class="label mb-1 block"
                                    >At least</label
                                >
                                <input
                                    id="{uid}-gmin"
                                    class="input"
                                    inputmode="numeric"
                                    value={rules.min ?? ''}
                                    oninput={(e) =>
                                        rule('min', num(e.currentTarget.value))}
                                />
                            </div>
                            <div>
                                <label for="{uid}-gmax" class="label mb-1 block"
                                    >At most</label
                                >
                                <input
                                    id="{uid}-gmax"
                                    class="input"
                                    inputmode="numeric"
                                    value={rules.max ?? ''}
                                    oninput={(e) =>
                                        rule('max', num(e.currentTarget.value))}
                                />
                            </div>
                        </div>
                        <TranslationInput
                            bind:value={
                                () => rules.add_label,
                                (v) => rule('add_label', v)
                            }
                            {languages}
                            label="The Add button says (optional)"
                            max={60}
                            placeholder="Add another"
                        />
                    </div>
                {/if}
            {:else if block.type === 'heading'}
                <TranslationInput
                    bind:value={block.text}
                    {languages}
                    label="Heading"
                    max={200}
                />
            {:else if block.type === 'text'}
                {#each languages as language (language)}
                    <div>
                        <span class="label mb-1 block"
                            >Text{languages.length > 1
                                ? language === 'ne'
                                    ? ' (Nepali)'
                                    : ' (English)'
                                : ''}</span
                        >
                        <RichTextEditor
                            label="Text"
                            value={block.doc?.[language] ?? null}
                            onchange={(doc) =>
                                (block.doc = {
                                    en: block.doc?.en ?? null,
                                    ne: block.doc?.ne ?? null,
                                    [language]: doc,
                                })}
                        />
                    </div>
                {/each}
            {:else if block.type === 'image'}
                <ContentUpload
                    {formId}
                    block={block.id}
                    kind="image"
                    bind:file={block.file}
                    bind:files
                />
                <TranslationInput
                    bind:value={block.alt}
                    {languages}
                    label="What the picture shows (for people who can't see it)"
                    max={200}
                />
            {:else if block.type === 'video'}
                <div>
                    <label for="{uid}-url" class="label mb-1 block"
                        >YouTube or Vimeo link</label
                    >
                    <input
                        id="{uid}-url"
                        class="input"
                        placeholder="https://www.youtube.com/watch?v=..."
                        value={block.url ?? ''}
                        oninput={(e) => (block.url = e.currentTarget.value)}
                    />
                </div>
            {:else if block.type === 'download'}
                <ContentUpload
                    {formId}
                    block={block.id}
                    kind="download"
                    bind:file={block.file}
                    bind:files
                />
                <TranslationInput
                    bind:value={block.label}
                    {languages}
                    label="Button text"
                    max={200}
                    placeholder="Download the circular"
                />
            {:else if block.type === 'divider'}
                <p class="text-xs text-fg-muted">
                    A line between parts of the step.
                </p>
            {/if}

            {#if !inGroup}
                <div>
                    <span class="label mb-1 block">When it shows</span>
                    <ConditionEditor bind:condition={block.show_if} {earlier} />
                </div>
            {/if}
        </div>
    {/if}
</div>
