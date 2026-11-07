<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import {
        ArrowLeft,
        ArrowRight,
        CheckCircle2,
        Lock,
        Send,
    } from '@lucide/svelte';
    import { onDestroy } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AutosaveStatus from '../../components/AutosaveStatus.svelte';
    import ContentBlock from '../../components/forms/ContentBlock.svelte';
    import FormField from '../../components/forms/FormField.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import {
        filled,
        say,
        stepProblems,
        visible,
    } from '../../lib/forms/answers';
    import { answerText } from '../../lib/forms/answerText';
    import { when } from '../../lib/forms/format';
    import { FormsHttpError, send } from '../../lib/forms/http';
    import { loadPlaces } from '../../lib/forms/places';
    import type { Places } from '../../lib/forms/places';
    import type {
        Answers,
        Block,
        FileRef,
        Language,
        Schema,
    } from '../../lib/forms/types';
    import { isQuestion } from '../../lib/forms/types';

    /**
     * Filling a form in (its link, /f/{code}): who runs it, who you are
     * answering as, its steps one at a time, a draft that saves itself as
     * you type, a review page, Submit and a receipt. Someone it isn't for
     * sees only that. The form's side trying it (`test`) uses the draft and
     * nothing is kept.
     */
    type State =
        'open' | 'not_open_yet' | 'closed' | 'full' | 'sent' | 'not_for_you';

    let props: {
        state: State;
        form: {
            id: number;
            code: string;
            title: string;
            organization: string | null;
            project: string | null;
            maker: string | null;
            closes_at: string | null;
            opens_at: string | null;
            answering: 'person' | 'organization';
            allow_edit: boolean;
            review_page: boolean;
            can_manage: boolean;
        } | null;
        test?: boolean;
        schema?: Schema;
        respondent?: {
            name: string;
            designation: string | null;
            organization: string | null;
        } | null;
        answers?: Answers;
        draft?: { saved_at: string | null; saved_by: string | null } | null;
        response?: {
            reference: string;
            submitted_at: string;
            updated_at: string | null;
            updated_by: string | null;
            answers: Answers;
        } | null;
        files?: Record<number, FileRef>;
        max_upload_mb?: number;
    } = $props();

    // svelte-ignore state_referenced_locally
    const schema: Schema = props.schema ?? { languages: ['en'], steps: [] };
    // svelte-ignore state_referenced_locally
    let answers = $state<Answers>(asAnswers(props.answers));
    // svelte-ignore state_referenced_locally
    let files = $state<Record<number, FileRef>>({ ...(props.files ?? {}) });
    let language = $state<Language>(schema.languages[0] ?? 'en');
    let stepIndex = $state(0);
    let reviewing = $state(false);
    let errors = $state<Record<string, string>>({});
    let submitting = $state(false);
    let submitError = $state<string | null>(null);
    let receipt = $state<{
        reference: string;
        message: string;
        test: boolean;
    } | null>(null);
    let saving = $state(false);
    // svelte-ignore state_referenced_locally
    let savedAt = $state<Date | null>(
        props.draft?.saved_at ? new Date(props.draft.saved_at) : null,
    );
    let saveError = $state<string | null>(null);
    let dirty = $state(false);
    let places = $state<Places | null>(null);
    /** Trying it out: the page at a phone's width. */
    let phone = $state(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    const form = $derived(props.form);
    const test = $derived(props.test ?? false);
    const editable = $derived(props.state === 'open' && receipt === null);
    const shown = $derived(visible(schema, answers));
    const steps = $derived(schema.steps.filter((s) => shown.steps.has(s.id)));
    const step = $derived(
        steps[Math.min(stepIndex, Math.max(steps.length - 1, 0))],
    );
    const last = $derived(stepIndex >= steps.length - 1);
    const languages = $derived(
        schema.languages.map((l) => ({
            value: l,
            label: l === 'ne' ? 'नेपाली' : 'English',
        })),
    );
    const hasAnswers = $derived(filled(answers));

    if (schema.steps.some((s) => s.blocks.some((b) => b.type === 'place'))) {
        void loadPlaces().then((p) => (places = p));
    }

    /** A copy of answers as a plain object (never an array, whatever came in). */
    function asAnswers(value: unknown): Answers {
        return value && typeof value === 'object' && !Array.isArray(value)
            ? (structuredClone($state.snapshot(value)) as Answers)
            : {};
    }

    /** Empty entries of a repeating group are left out, as the server does. */
    function cleaned(): Answers {
        const out: Answers = {};

        for (const [key, value] of Object.entries(
            $state.snapshot(answers) as Answers,
        )) {
            out[key] =
                Array.isArray(value) &&
                value.length &&
                typeof value[0] === 'object' &&
                !Array.isArray(value[0])
                    ? value.filter((entry) => filled(entry))
                    : value;
        }

        return out;
    }

    // What the server has: the answers the page opened with, then each save.
    let lastSaved = JSON.stringify(cleaned());

    $effect(() => {
        // Any change to an answer: save the draft a moment later.
        const now = JSON.stringify(cleaned());

        if (!editable || !form || now === lastSaved) {
            return;
        }

        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(saveDraft, 1500);
    });

    async function saveDraft() {
        if (!form || !editable) {
            return;
        }

        const body = cleaned();
        saving = true;
        saveError = null;

        try {
            const { data } = await send<{ saved_at: string | null }>(
                'PUT',
                `/workspace/forms/${form.id}/fill/draft`,
                { answers: body, test },
            );
            savedAt = data.saved_at ? new Date(data.saved_at) : new Date();
            lastSaved = JSON.stringify(body);
            dirty = JSON.stringify(cleaned()) !== lastSaved;
        } catch (e) {
            saveError = e instanceof Error ? e.message : 'not saved';
        } finally {
            saving = false;
        }
    }

    onDestroy(() => clearTimeout(timer));

    function check(blocks: Block[]): boolean {
        errors = stepProblems(blocks, shown.blocks, answers);

        if (Object.keys(errors).length > 0) {
            queueMicrotask(() =>
                document
                    .querySelector('[aria-invalid="true"]')
                    ?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
            );

            return false;
        }

        return true;
    }

    function next() {
        if (!step || !check(step.blocks)) {
            return;
        }

        if (last) {
            reviewing = true;
        } else {
            stepIndex++;
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function back() {
        if (reviewing) {
            reviewing = false;
        } else {
            stepIndex = Math.max(0, stepIndex - 1);
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function submit() {
        if (!form) {
            return;
        }

        if (!reviewing && step && !check(step.blocks)) {
            return;
        }

        submitting = true;
        submitError = null;
        clearTimeout(timer);

        try {
            const result = await send<{ reference: string; test: boolean }>(
                'POST',
                `/workspace/forms/${form.id}/fill`,
                { answers: cleaned(), test },
            );
            receipt = {
                reference: result.data.reference,
                message: result.message ?? 'Sent.',
                test: result.data.test,
            };
            // The server took the draft into the response; nothing to save.
            lastSaved = JSON.stringify(cleaned());
            dirty = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (e) {
            if (e instanceof FormsHttpError && e.status === 422) {
                const found: Record<string, string> = {};

                for (const [key, message] of Object.entries(e.errors)) {
                    found[key.replace(/^answers\./, '')] = message;
                }

                errors = found;
                submitError = e.message;
                const firstWrong = Object.keys(found)[0]?.split('.')[0];
                const at = steps.findIndex((s) =>
                    s.blocks.some((b) => b.id === firstWrong),
                );

                if (at >= 0) {
                    reviewing = false;
                    stepIndex = at;
                }
            } else {
                submitError =
                    e instanceof Error
                        ? e.message
                        : "Your response didn't go through. Try again.";
            }
        } finally {
            submitting = false;
        }
    }

    async function clearDraft() {
        if (
            !form ||
            !window.confirm(
                "Clear every answer you have typed? This can't be undone.",
            )
        ) {
            return;
        }

        await send('DELETE', `/workspace/forms/${form.id}/fill/draft`, {
            test,
        });
        answers = asAnswers(props.response?.answers);
        lastSaved = JSON.stringify(cleaned());
        errors = {};
        stepIndex = 0;
        reviewing = false;
    }

    const stateText: Record<string, string> = {
        not_open_yet: "This form isn't open yet.",
        closed: 'This form is closed.',
        full: 'This form has all the responses it takes.',
        sent: "You have sent your response. This form doesn't take changes.",
    };
</script>

<svelte:head><title>{form?.title ?? 'Form'}</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/forms"
                use:inertia
                class="hidden shrink-0 text-fg-muted hover:text-fg sm:inline"
                >Forms</a
            >
            {#if form}
                <span class="hidden text-fg-faint sm:inline">/</span>
                <span class="truncate font-medium">{form.title}</span>
            {/if}
        </div>
        {#if form && editable && !receipt}
            <AutosaveStatus {saving} {savedAt} {dirty} error={saveError} />
        {/if}
    {/snippet}

    <div class="mx-auto w-full pb-16 {phone ? 'max-w-[390px]' : 'max-w-2xl'}">
        {#if props.state === 'not_for_you' || !form}
            <div class="panel mt-6 p-6 text-center">
                <Lock class="mx-auto h-6 w-6 text-fg-faint" />
                <h1 class="mt-3 text-[15px] font-semibold text-fg">
                    This form isn't open to you
                </h1>
                <p class="mt-1 text-[13px] text-fg-muted">
                    The link may be for another office, or it may be wrong. Your
                    forms are on the Forms page.
                </p>
                <a href="/workspace/forms" use:inertia class="btn mt-4"
                    >Go to Forms</a
                >
            </div>
        {:else}
            {#if test}
                <div
                    class="mb-3 rounded-md border border-warn bg-warn-soft px-3 py-2 text-[13px] text-fg"
                >
                    Trying it out: this uses the draft, and nothing you send is
                    kept.
                    <a
                        href="/workspace/forms/{form.id}/edit"
                        use:inertia
                        class="underline">Back to the builder</a
                    >
                    <span class="ml-2 inline-flex gap-1">
                        <button
                            type="button"
                            class="btn-ghost text-xs"
                            aria-pressed={phone}
                            onclick={() => (phone = true)}>As on a phone</button
                        >
                        <button
                            type="button"
                            class="btn-ghost text-xs"
                            aria-pressed={!phone}
                            onclick={() => (phone = false)}
                            >As on a computer</button
                        >
                    </span>
                </div>
            {/if}

            <header class="mb-4">
                <h1 class="text-xl font-semibold text-fg">{form.title}</h1>
                <p class="mt-1 text-[13px] text-fg-muted">
                    Run by {form.organization}{form.project
                        ? `, ${form.project}`
                        : ''}{form.maker ? `, made by ${form.maker}` : ''}.
                    {#if form.closes_at}Open until {when(form.closes_at)}.{/if}
                </p>
                {#if props.respondent}
                    <p class="mt-2 text-[13px] text-fg">
                        {#if form.answering === 'organization'}
                            You are answering for <strong
                                >{props.respondent.organization}</strong
                            >. Anyone there can see and change this response
                            until it closes.
                        {:else}
                            Answering as <strong>{props.respondent.name}</strong
                            >{props.respondent.designation
                                ? `, ${props.respondent.designation}`
                                : ''}{props.respondent.organization
                                ? `, ${props.respondent.organization}`
                                : ''}.
                        {/if}
                    </p>
                {/if}
                {#if languages.length > 1}
                    <div class="mt-3">
                        <PillGroup options={languages} bind:value={language} />
                    </div>
                {/if}
            </header>

            {#if receipt}
                <div class="panel p-6">
                    <CheckCircle2 class="h-6 w-6 text-success" />
                    <h2 class="mt-2 text-[15px] font-semibold text-fg">
                        {receipt.test ? 'Test passed' : 'Sent'}
                    </h2>
                    <p class="mt-1 text-[14px] text-fg">{receipt.message}</p>
                    {#if !receipt.test}
                        <p class="mt-1 text-[13px] text-fg-muted">
                            Keep the reference <strong class="text-fg"
                                >{receipt.reference}</strong
                            >. You'll find your response under Filled on the
                            Forms page.
                        </p>
                    {/if}
                    <div class="mt-4 flex flex-wrap gap-2">
                        {#if receipt.test}
                            <a
                                href="/workspace/forms/{form.id}/edit"
                                use:inertia
                                class="btn">Back to the builder</a
                            >
                        {:else}
                            {#if form.allow_edit}
                                <button
                                    type="button"
                                    class="btn"
                                    onclick={() => {
                                        receipt = null;
                                        stepIndex = 0;
                                        reviewing = false;
                                    }}>Change your answers</button
                                >
                            {/if}
                            <a href="/workspace/forms" use:inertia class="btn"
                                >Go to Forms</a
                            >
                        {/if}
                    </div>
                </div>
            {:else if props.state !== 'open'}
                <div class="panel p-5">
                    <p class="text-[14px] text-fg">
                        {stateText[props.state] ?? ''}
                    </p>
                    {#if props.state === 'not_open_yet' && form.opens_at}<p
                            class="mt-1 text-[13px] text-fg-muted"
                        >
                            It opens {when(form.opens_at)}.
                        </p>{/if}
                    {#if props.response}
                        <p class="mt-1 text-[13px] text-fg-muted">
                            You sent your response {when(
                                props.response.submitted_at,
                            )}, reference {props.response.reference}.
                        </p>
                    {/if}
                </div>
                {#if props.response}
                    <div class="panel mt-4 p-5">
                        <h2 class="section-title mb-3">Your answers</h2>
                        {@render review(props.response.answers)}
                    </div>
                {/if}
            {:else}
                {#if props.response}
                    <div
                        class="mb-3 rounded-md border border-line bg-surface-alt px-3 py-2 text-[13px] text-fg"
                    >
                        You sent this {when(props.response.submitted_at)} (reference
                        {props.response.reference}){props.response.updated_by &&
                        form.answering === 'organization'
                            ? `, last changed by ${props.response.updated_by}`
                            : ''}. Sending again replaces it.
                    </div>
                {:else if props.draft?.saved_by && form.answering === 'organization'}
                    <div
                        class="mb-3 rounded-md border border-line bg-surface-alt px-3 py-2 text-[13px] text-fg"
                    >
                        {props.draft.saved_by} last saved this draft {when(
                            props.draft.saved_at,
                        )}.
                    </div>
                {/if}

                {#if steps.length > 1 && !reviewing}
                    <div class="mb-3 flex items-center gap-3">
                        <div
                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-alt"
                            aria-hidden="true"
                        >
                            <div
                                class="h-full rounded-full bg-accent transition-all"
                                style:width="{((stepIndex + 1) / steps.length) *
                                    100}%"
                            ></div>
                        </div>
                        <span class="shrink-0 text-xs text-fg-muted"
                            >Step {stepIndex + 1} of {steps.length}</span
                        >
                    </div>
                {/if}

                <div class="panel p-5">
                    {#if reviewing}
                        <h2 class="section-title mb-1">Check your answers</h2>
                        <p class="mb-4 text-[13px] text-fg-muted">
                            Nothing is sent until you choose Submit.
                        </p>
                        {@render review(answers)}
                    {:else if step}
                        {#if say(step.title, language) && steps.length > 1}
                            <h2 class="mb-4 text-[15px] font-semibold text-fg">
                                {say(step.title, language)}
                            </h2>
                        {/if}
                        <div class="flex flex-col gap-5">
                            {#each step.blocks as block (block.id)}
                                {#if shown.blocks.has(block.id)}
                                    {#if isQuestion(block.type)}
                                        <FormField
                                            question={block}
                                            bind:value={answers[block.id]}
                                            {errors}
                                            path={block.id}
                                            {language}
                                            bind:files
                                            formId={form.id}
                                            {test}
                                            maxUploadMb={props.max_upload_mb ??
                                                20}
                                        />
                                    {:else}
                                        <ContentBlock
                                            {block}
                                            {language}
                                            {files}
                                        />
                                    {/if}
                                {/if}
                            {/each}
                        </div>
                    {:else}
                        <p class="text-[13px] text-fg-muted">
                            This form has no questions.
                        </p>
                    {/if}
                </div>

                {#if submitError}<p
                        class="mt-3 text-[13px] text-danger"
                        role="alert"
                    >
                        {submitError}
                    </p>{/if}

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    {#if stepIndex > 0 || reviewing}
                        <button type="button" class="btn" onclick={back}
                            ><ArrowLeft class="h-3.5 w-3.5" />Back</button
                        >
                    {/if}
                    <div class="flex-1"></div>
                    {#if hasAnswers}
                        <button
                            type="button"
                            class="btn-ghost text-xs"
                            onclick={clearDraft}>Clear my draft</button
                        >
                    {/if}
                    {#if reviewing || (last && !(form.review_page && steps.length > 1))}
                        <button
                            type="button"
                            class="btn btn-primary"
                            disabled={submitting}
                            onclick={submit}
                        >
                            {#if submitting}<Spinner
                                    size={14}
                                />Sending{:else}<Send
                                    class="h-3.5 w-3.5"
                                />{props.response
                                    ? 'Send changes'
                                    : 'Submit'}{/if}
                        </button>
                    {:else}
                        <button
                            type="button"
                            class="btn btn-primary"
                            onclick={next}
                        >
                            {last ? 'Review' : 'Next'}<ArrowRight
                                class="h-3.5 w-3.5"
                            />
                        </button>
                    {/if}
                </div>
            {/if}
        {/if}
    </div>
</AppShell>

{#snippet review(values: Answers)}
    <dl class="flex flex-col gap-3">
        {#each steps as s, si (s.id)}
            {#each s.blocks.filter((b) => isQuestion(b.type) && shown.blocks.has(b.id)) as q (q.id)}
                <div>
                    <dt class="text-xs text-fg-muted">
                        {say(q.label, language) || say(q.text, language)}
                    </dt>
                    <dd class="text-[14px] text-fg">
                        {#if q.type === 'group'}
                            {#each Array.isArray(values[q.id]) ? (values[q.id] as Answers[]) : [] as entry, i (i)}
                                <div
                                    class="mt-1 rounded border border-line-soft px-2 py-1"
                                >
                                    {#each q.blocks ?? [] as c (c.id)}
                                        <div class="text-[13px]">
                                            <span class="text-fg-muted"
                                                >{say(c.label, language)}:</span
                                            >
                                            {answerText(
                                                c,
                                                entry[c.id],
                                                language,
                                                files,
                                                places,
                                            ) || 'No answer'}
                                        </div>
                                    {/each}
                                </div>
                            {:else}No answer{/each}
                        {:else}
                            {answerText(
                                q,
                                values[q.id],
                                language,
                                files,
                                places,
                            ) || 'No answer'}
                        {/if}
                        {#if reviewing && editable}
                            <button
                                type="button"
                                class="btn-ghost ml-1 text-xs"
                                onclick={() => {
                                    reviewing = false;
                                    stepIndex = si;
                                }}>Change</button
                            >
                        {/if}
                    </dd>
                </div>
            {/each}
        {/each}
    </dl>
{/snippet}
