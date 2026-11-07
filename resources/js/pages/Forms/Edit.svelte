<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        ArrowDown,
        ArrowUp,
        Copy,
        Eye,
        Inbox,
        Lock,
        Plus,
        Rocket,
        Trash2,
        Unlock,
    } from '@lucide/svelte';
    import { onDestroy } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AutosaveStatus from '../../components/AutosaveStatus.svelte';
    import AddBlockMenu from '../../components/forms/builder/AddBlockMenu.svelte';
    import BlockEditor from '../../components/forms/builder/BlockEditor.svelte';
    import type { BuilderData } from '../../components/forms/builder/builderTypes';
    import { newBlock } from '../../components/forms/builder/catalog';
    import ConditionEditor from '../../components/forms/builder/ConditionEditor.svelte';
    import RespondentsPanel from '../../components/forms/builder/RespondentsPanel.svelte';
    import SettingsPanel from '../../components/forms/builder/SettingsPanel.svelte';
    import SharingPanel from '../../components/forms/builder/SharingPanel.svelte';
    import TasksPanel from '../../components/forms/builder/TasksPanel.svelte';
    import TranslationInput from '../../components/forms/builder/TranslationInput.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import { PHASES, when } from '../../lib/forms/format';
    import { FormsHttpError, send } from '../../lib/forms/http';
    import { newId } from '../../lib/forms/ids';
    import type {
        Block,
        Language,
        Problem,
        Schema,
        Step,
    } from '../../lib/forms/types';
    import { isQuestion } from '../../lib/forms/types';
    import { toast } from '../../lib/toast.svelte';

    /**
     * The builder: steps and their blocks, saved as you work (only the
     * draft changes; Publish puts it live), what stops it from being
     * published, and the form's settings, respondents, sharing and tasks.
     */
    let props: BuilderData = $props();

    // svelte-ignore state_referenced_locally
    let data = $state<BuilderData>(props);
    // svelte-ignore state_referenced_locally
    let draft = $state<Schema>(structuredClone($state.snapshot(props.draft)));
    // svelte-ignore state_referenced_locally
    let files = $state({ ...props.files });
    let tab = $state('questions');
    let openBlock = $state<string | null>(null);
    let saving = $state(false);
    let savedAt = $state<Date | null>(null);
    let saveError = $state<string | null>(null);
    let dirty = $state(false);
    let publishing = $state(false);
    let copyOpen = $state(false);
    // svelte-ignore state_referenced_locally
    let copyTo = $state(props.places[0]?.slug ?? '');
    let timer: ReturnType<typeof setTimeout> | undefined;
    let loaded = false;

    const form = $derived(data.form);
    const tabs = [
        { value: 'questions', label: 'Questions' },
        { value: 'settings', label: 'Settings' },
        { value: 'respondents', label: 'Who fills it in' },
        { value: 'sharing', label: 'Sharing' },
        { value: 'tasks', label: 'Tasks' },
    ];
    const errors = $derived(data.problems.filter((p) => p.level === 'error'));
    const warnings = $derived(
        data.problems.filter((p) => p.level === 'warning'),
    );
    const byBlock = $derived.by(() => {
        const map: Record<string, string[]> = {};

        for (const p of errors) {
            if (p.block) {
                (map[p.block] ??= []).push(p.message);
            }
        }

        return map;
    });

    $effect(() => {
        void JSON.stringify(draft);

        if (!loaded) {
            loaded = true;

            return;
        }

        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(save, 800);
    });

    onDestroy(() => clearTimeout(timer));

    async function save() {
        saving = true;
        saveError = null;

        try {
            const { data: saved } = await send<{
                problems: Problem[];
                changed: boolean;
                saved_at: string | null;
            }>('PUT', `/workspace/forms/${form.id}/draft`, {
                draft: $state.snapshot(draft),
            });
            data.problems = saved.problems;
            data.changed = saved.changed;
            savedAt = saved.saved_at ? new Date(saved.saved_at) : new Date();
            dirty = false;
        } catch (e) {
            saveError = e instanceof Error ? e.message : 'not saved';
        } finally {
            saving = false;
        }
    }

    function update(next: BuilderData) {
        data = { ...next, draft: data.draft };
    }

    async function publish() {
        clearTimeout(timer);

        if (dirty) {
            await save();
        }

        publishing = true;

        try {
            const { data: next, message } = await send<BuilderData>(
                'POST',
                `/workspace/forms/${form.id}/publish`,
                { notify: form.settings.notify },
            );
            update(next);
            toast.show(message ?? 'Published.');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : "It didn't publish.");
            tab = 'questions';
        } finally {
            publishing = false;
        }
    }

    async function setOpen(open: boolean) {
        try {
            const { data: next, message } = await send<BuilderData>(
                'POST',
                `/workspace/forms/${form.id}/${open ? 'reopen' : 'close'}`,
            );
            update(next);
            toast.show(message ?? 'Saved.');
        } catch (e) {
            toast.error(
                e instanceof FormsHttpError ? e.message : "That didn't save.",
            );
        }
    }

    async function copyLink() {
        try {
            await navigator.clipboard.writeText(form.link);
            toast.show('Copied the link.');
        } catch {
            toast.error(`Couldn't copy. The link is ${form.link}`);
        }
    }

    /** Questions before a step (or before a block in it): what a condition may look at. */
    function earlierThan(stepIndex: number, blockIndex: number): Block[] {
        const before: Block[] = [];

        draft.steps.forEach((step, s) => {
            step.blocks.forEach((block, b) => {
                if (
                    isQuestion(block.type) &&
                    (s < stepIndex || (s === stepIndex && b < blockIndex))
                ) {
                    before.push(block);
                }
            });
        });

        return before;
    }

    function addBlock(step: Step, type: Block['type']) {
        const block = newBlock(type);
        step.blocks = [...step.blocks, block];
        openBlock = block.id;
    }

    function moveBlock(step: Step, i: number, by: number) {
        const next = [...step.blocks];
        const [item] = next.splice(i, 1);
        next.splice(i + by, 0, item);
        step.blocks = next;
    }

    function duplicateBlock(step: Step, i: number) {
        const copy = structuredClone($state.snapshot(step.blocks[i])) as Block;
        copy.id = newId('q');
        copy.options = copy.options?.map((o) => ({ ...o, id: newId('o') }));
        copy.blocks = copy.blocks?.map((c) => ({
            ...c,
            id: newId('q'),
            options: c.options?.map((o) => ({ ...o, id: newId('o') })),
        }));
        step.blocks = [
            ...step.blocks.slice(0, i + 1),
            copy,
            ...step.blocks.slice(i + 1),
        ];
        openBlock = copy.id;
    }

    function removeBlock(step: Step, i: number) {
        if (
            isQuestion(step.blocks[i].type) &&
            form.published &&
            !window.confirm(
                'Delete this question? Answers already sent keep it, under "Earlier questions".',
            )
        ) {
            return;
        }

        step.blocks = step.blocks.filter((_, j) => j !== i);
    }

    function addStep() {
        draft.steps = [
            ...draft.steps,
            {
                id: newId('s'),
                title: { en: '', ne: '' },
                show_if: null,
                blocks: [],
            },
        ];
    }

    function moveStep(i: number, by: number) {
        const next = [...draft.steps];
        const [item] = next.splice(i, 1);
        next.splice(i + by, 0, item);
        draft.steps = next;
    }

    function removeStep(i: number) {
        if (
            draft.steps[i].blocks.length &&
            !window.confirm('Delete this step and everything in it?')
        ) {
            return;
        }

        draft.steps = draft.steps.filter((_, j) => j !== i);
    }

    function setLanguage(language: Language, on: boolean) {
        const next = on
            ? [...new Set([...draft.languages, language])]
            : draft.languages.filter((l) => l !== language);
        draft.languages = (['en', 'ne'] as Language[]).filter((l) =>
            next.includes(l),
        );

        if (draft.languages.length === 0) {
            draft.languages = [language === 'en' ? 'ne' : 'en'];
        }
    }

    function goTo(block: string | null) {
        if (!block) {
            return;
        }

        tab = 'questions';
        openBlock = block;
        queueMicrotask(() =>
            document
                .getElementById(`block-${block}`)
                ?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
        );
    }

    function remove() {
        if (window.confirm(`Delete ${form.title}? It has no responses.`)) {
            router.delete(`/workspace/forms/${form.id}`);
        }
    }
</script>

<svelte:head><title>{form.title} · Builder</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/forms"
                use:inertia
                class="hidden shrink-0 text-fg-muted hover:text-fg sm:inline"
                >Forms</a
            >
            <span class="hidden text-fg-faint sm:inline">/</span>
            <span class="truncate font-medium">{form.title}</span>
            <span
                class="chip shrink-0 {form.phase === 'open'
                    ? 'chip-success'
                    : form.phase === 'scheduled'
                      ? 'chip-warn'
                      : ''}">{PHASES[form.phase]}</span
            >
        </div>
        <AutosaveStatus {saving} {savedAt} {dirty} error={saveError} />
        <a
            href="/workspace/forms/{form.id}/responses"
            use:inertia
            class="btn hidden sm:inline-flex"
            ><Inbox class="h-3.5 w-3.5" />Responses ({form.responses_count ??
                0})</a
        >
        <a
            href="/workspace/forms/{form.id}/try"
            class="btn"
            title="Fill in the draft yourself; nothing is kept"
            ><Eye class="h-3.5 w-3.5" />Try it</a
        >
        <button
            type="button"
            class="btn btn-primary"
            disabled={publishing || (!data.changed && form.published)}
            onclick={publish}
        >
            {#if publishing}<Spinner size={14} />{:else}<Rocket
                    class="h-3.5 w-3.5"
                />{/if}{form.published ? 'Publish changes' : 'Publish'}
        </button>
    {/snippet}

    <div class="mx-auto w-full max-w-3xl pb-16">
        <div
            class="mb-4 flex flex-wrap items-center gap-2 text-[13px] text-fg-muted"
        >
            <span
                >{form.organization} · {form.project?.label ?? ''}{form.maker
                    ? ` · made by ${form.maker}`
                    : ''}</span
            >
            <div class="flex-1"></div>
            {#if form.published}
                <span class="hidden truncate font-mono text-xs sm:inline"
                    >{form.link}</span
                >
                <button type="button" class="btn" onclick={copyLink}
                    ><Copy class="h-3.5 w-3.5" />Copy link</button
                >
                {#if form.status === 'closed'}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => setOpen(true)}
                        ><Unlock class="h-3.5 w-3.5" />Reopen</button
                    >
                {:else}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => setOpen(false)}
                        ><Lock class="h-3.5 w-3.5" />Close</button
                    >
                {/if}
            {/if}
            <button
                type="button"
                class="btn"
                onclick={() => (copyOpen = !copyOpen)}>Make a copy</button
            >
            {#if form.can_delete}<button
                    type="button"
                    class="btn-icon"
                    title="Delete"
                    aria-label="Delete the form"
                    onclick={remove}><Trash2 class="h-3.5 w-3.5" /></button
                >{/if}
        </div>

        {#if copyOpen}
            <div class="panel mb-4 flex flex-wrap items-end gap-2 p-3">
                <div class="min-w-0 flex-1">
                    <label for="copy-to" class="label mb-1 block"
                        >Copy it as a new draft into</label
                    >
                    <select id="copy-to" class="input" bind:value={copyTo}
                        >{#each data.places as p (p.slug)}<option value={p.slug}
                                >{p.label}</option
                            >{/each}</select
                    >
                </div>
                <button
                    type="button"
                    class="btn btn-primary"
                    onclick={() =>
                        router.post(`/workspace/forms/${form.id}/duplicate`, {
                            project: copyTo,
                        })}>Make the copy</button
                >
            </div>
        {/if}

        {#if data.changed && form.published}
            <p
                class="mb-3 rounded-md border border-line bg-surface-alt px-3 py-2 text-[13px] text-fg"
            >
                You have changes that aren't live yet. People see the published
                version until you choose Publish changes.
            </p>
        {/if}

        <div class="-mx-1 mb-4 overflow-x-auto px-1 [scrollbar-width:none]">
            <PillGroup options={tabs} bind:value={tab} />
        </div>

        {#if tab === 'questions'}
            {#if errors.length || warnings.length}
                <div class="panel mb-4 p-3">
                    {#if errors.length}
                        <p class="mb-1 text-[13px] font-medium text-fg">
                            Before it can be published
                        </p>
                        <ul class="mb-2 flex flex-col gap-0.5">
                            {#each errors as problem, i (i)}
                                <li class="text-[13px] text-danger">
                                    {#if problem.block}<button
                                            type="button"
                                            class="text-left underline-offset-2 hover:underline"
                                            onclick={() => goTo(problem.block)}
                                            >{problem.message}</button
                                        >{:else}{problem.message}{/if}
                                </li>
                            {/each}
                        </ul>
                    {/if}
                    {#each warnings as problem, i (i)}<p
                            class="text-xs text-fg-muted"
                        >
                            {problem.message}
                        </p>{/each}
                </div>
            {/if}

            <fieldset
                class="mb-4 flex flex-wrap items-center gap-3 text-[13px] text-fg"
            >
                <legend class="label mb-1">Languages</legend>
                <label class="flex items-center gap-1.5"
                    ><input
                        type="checkbox"
                        checked={draft.languages.includes('en')}
                        onchange={(e) =>
                            setLanguage('en', e.currentTarget.checked)}
                    />English</label
                >
                <label class="flex items-center gap-1.5"
                    ><input
                        type="checkbox"
                        checked={draft.languages.includes('ne')}
                        onchange={(e) =>
                            setLanguage('ne', e.currentTarget.checked)}
                    />नेपाली</label
                >
                <span class="text-xs text-fg-muted"
                    >With both, every question needs both, and people switch
                    between them.</span
                >
            </fieldset>

            <div class="flex flex-col gap-4">
                {#each draft.steps as step, si (step.id)}
                    <section class="panel p-4" id="block-{step.id}">
                        <div class="mb-3 flex items-center gap-1.5">
                            <h2 class="section-title flex-1">Step {si + 1}</h2>
                            {#if draft.steps.length > 1}
                                <button
                                    type="button"
                                    class="btn-icon"
                                    aria-label="Move step up"
                                    disabled={si === 0}
                                    onclick={() => moveStep(si, -1)}
                                    ><ArrowUp class="h-3.5 w-3.5" /></button
                                >
                                <button
                                    type="button"
                                    class="btn-icon"
                                    aria-label="Move step down"
                                    disabled={si === draft.steps.length - 1}
                                    onclick={() => moveStep(si, 1)}
                                    ><ArrowDown class="h-3.5 w-3.5" /></button
                                >
                                <button
                                    type="button"
                                    class="btn-icon"
                                    aria-label="Delete step {si + 1}"
                                    onclick={() => removeStep(si)}
                                    ><Trash2 class="h-3.5 w-3.5" /></button
                                >
                            {/if}
                        </div>
                        {#each byBlock[step.id] ?? [] as message (message)}<p
                                class="mb-2 text-xs text-danger"
                            >
                                {message}
                            </p>{/each}
                        {#if draft.steps.length > 1}
                            <div class="mb-3 flex flex-col gap-2">
                                <TranslationInput
                                    bind:value={step.title}
                                    languages={draft.languages}
                                    label="Title"
                                    max={200}
                                />
                                <ConditionEditor
                                    bind:condition={step.show_if}
                                    earlier={earlierThan(si, 0)}
                                />
                            </div>
                        {/if}
                        <div class="flex flex-col gap-2">
                            {#each step.blocks as block, bi (block.id)}
                                <BlockEditor
                                    bind:block={step.blocks[bi]}
                                    languages={draft.languages}
                                    earlier={earlierThan(si, bi)}
                                    formId={form.id}
                                    bind:files
                                    bind:open={
                                        () => openBlock === block.id,
                                        (v) =>
                                            (openBlock = v
                                                ? block.id
                                                : openBlock === block.id
                                                  ? null
                                                  : openBlock)
                                    }
                                    problems={byBlock[block.id] ?? []}
                                    first={bi === 0}
                                    last={bi === step.blocks.length - 1}
                                    onmove={(by) => moveBlock(step, bi, by)}
                                    onduplicate={() => duplicateBlock(step, bi)}
                                    onremove={() => removeBlock(step, bi)}
                                />
                            {:else}
                                <p class="text-[13px] text-fg-muted">
                                    Nothing in this step yet.
                                </p>
                            {/each}
                        </div>
                        <div class="mt-3">
                            <AddBlockMenu
                                label="Add to step {si + 1}"
                                onadd={(type) => addBlock(step, type)}
                            />
                        </div>
                    </section>
                {/each}
                {#if draft.steps.length < data.limits.max_steps}
                    <button type="button" class="btn w-fit" onclick={addStep}
                        ><Plus class="h-3.5 w-3.5" />Add a step</button
                    >
                {/if}
            </div>

            {#if data.versions.length}
                <p class="mt-6 text-xs text-fg-muted">
                    Published {data.versions.length}
                    {data.versions.length === 1 ? 'time' : 'times'}, last {when(
                        data.versions[data.versions.length - 1].published_at,
                    )}. Each response keeps the version it was filled in on.
                </p>
            {/if}
        {:else if tab === 'settings'}
            <SettingsPanel {data} onupdate={update} />
        {:else if tab === 'respondents'}
            <RespondentsPanel {data} onupdate={update} />
        {:else if tab === 'sharing'}
            <SharingPanel
                formId={form.id}
                organization={form.organization}
                project={form.project?.label ?? ''}
            />
        {:else}
            <TasksPanel {data} onupdate={update} />
        {/if}
    </div>
</AppShell>
