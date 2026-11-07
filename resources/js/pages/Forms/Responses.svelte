<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        Bell,
        Download,
        FileArchive,
        FileSpreadsheet,
        Pencil,
        Search,
        X,
    } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import { say } from '../../lib/forms/answers';
    import { answerText } from '../../lib/forms/answerText';
    import { when } from '../../lib/forms/format';
    import { FormsHttpError, send } from '../../lib/forms/http';
    import { loadPlaces } from '../../lib/forms/places';
    import type { Places } from '../../lib/forms/places';
    import type {
        Answers,
        Block,
        FileRef,
        FormItem,
        Language,
        Schema,
        Stage,
    } from '../../lib/forms/types';
    import { toast } from '../../lib/toast.svelte';

    /**
     * A form's responses, for whoever may see them: the table with its
     * filters (in the URL), moving responses between stages, one response
     * with its history and notes, the summary, who hasn't answered (with
     * Remind) and the exports. Respondents never see stages or notes.
     */
    type Row = {
        id: number;
        reference: string;
        sender: {
            name: string;
            designation: string | null;
            organization: string | null;
        };
        organization_id: number | null;
        stage: string | null;
        answers: Answers;
        submitted_at: string;
        updated_at: string | null;
        task: {
            title: string;
            status: string;
            url: string;
            trashed: boolean;
        } | null;
    };
    type Detail = Row & {
        schema: Schema;
        version_number: number;
        files: Record<number, FileRef>;
        history: {
            kind: string;
            by: string | null;
            at: string;
            text: string;
            note: string | null;
        }[];
    };
    type Question = Block & { earlier: boolean; step: number };

    let props: {
        form: FormItem & { stages: Stage[]; languages: Language[] };
        questions: Question[];
        organizations: { id: number; name: string }[];
        page: { rows: Row[]; next_cursor: string | null; total: number };
        filters: Record<string, string | number>;
        open_response: number | null;
        can_edit: boolean;
        can_open_as_spreadsheet: boolean;
    } = $props();

    const uid = $props.id();
    const form = $derived(props.form);
    const language = $derived<Language>(props.form.languages[0] ?? 'en');
    const stageName = $derived(
        Object.fromEntries(props.form.stages.map((s) => [s.id, s.name])),
    );
    const filterable = $derived(
        props.questions.filter((q) =>
            ['choice', 'choices', 'yes_no'].includes(q.type),
        ),
    );

    let tab = $state('responses');
    // svelte-ignore state_referenced_locally
    let q = $state(String(props.filters.q ?? ''));
    // svelte-ignore state_referenced_locally
    let stage = $state(String(props.filters.stage ?? ''));
    // svelte-ignore state_referenced_locally
    let organization = $state(String(props.filters.organization ?? ''));
    // svelte-ignore state_referenced_locally
    let question = $state(String(props.filters.question ?? ''));
    // svelte-ignore state_referenced_locally
    let answer = $state(String(props.filters.answer ?? ''));
    // svelte-ignore state_referenced_locally
    let from = $state(String(props.filters.from ?? ''));
    // svelte-ignore state_referenced_locally
    let to = $state(String(props.filters.to ?? ''));
    // svelte-ignore state_referenced_locally
    let columns = $state<string[]>(
        props.questions
            .filter((x) => !x.earlier && x.type !== 'group')
            .slice(0, 3)
            .map((x) => x.id),
    );
    let more = $state<Row[]>([]);
    // svelte-ignore state_referenced_locally
    let cursor = $state<string | null>(props.page.next_cursor);
    let loadingMore = $state(false);
    let selected = $state<number[]>([]);
    let moveTo = $state('');
    let moveNote = $state('');
    let detail = $state<Detail | null>(null);
    let detailLoading = $state(false);
    let note = $state('');
    let places = $state<Places | null>(null);
    let summary = $state<{
        total: number;
        per_day: { day: string; count: number }[];
        questions: {
            id: string;
            label: { en: string; ne: string };
            answered: number;
            counts: {
                id: string;
                label: { en: string; ne: string };
                count: number;
            }[];
        }[];
    } | null>(null);
    let pending = $state<{
        mode: string;
        people: { id: number; name: string; designation: string | null }[];
        organizations: {
            id: number;
            name: string;
            answered: number;
            total: number;
        }[];
        answered: number;
        total: number;
    } | null>(null);
    let reminding = $state(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    $effect(() => {
        void props.page;
        more = [];
        cursor = props.page.next_cursor;
        selected = [];
    });

    const rows = $derived([...props.page.rows, ...more]);
    const query = $derived(
        new URLSearchParams(
            Object.entries({
                q: q.trim(),
                stage,
                organization,
                question,
                answer: question ? answer : '',
                from,
                to,
            }).filter(([, v]) => v !== ''),
        ).toString(),
    );
    const shownQuery = $derived(
        new URLSearchParams(
            Object.entries(props.filters).map(([k, v]) => [k, String(v)]),
        ).toString(),
    );

    onMount(() => {
        if (props.questions.some((x) => x.type === 'place')) {
            void loadPlaces().then((p) => (places = p));
        }

        if (props.open_response) {
            void open(props.open_response);
        }
    });

    function apply() {
        router.get(
            `/workspace/forms/${form.id}/responses`,
            Object.fromEntries(new URLSearchParams(query)),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['page', 'filters'],
            },
        );
    }

    function soon() {
        clearTimeout(timer);
        timer = setTimeout(apply, 300);
    }

    async function showMore() {
        if (!cursor) {
            return;
        }

        loadingMore = true;

        try {
            const { data } = await send<{
                rows: Row[];
                next_cursor: string | null;
            }>(
                'GET',
                `/workspace/forms/${form.id}/responses/list?${shownQuery}${shownQuery ? '&' : ''}cursor=${encodeURIComponent(cursor)}`,
            );
            more = [...more, ...data.rows];
            cursor = data.next_cursor;
        } catch {
            toast.error("The next ones didn't load. Try again.");
        } finally {
            loadingMore = false;
        }
    }

    async function open(id: number) {
        detailLoading = true;
        note = '';

        try {
            const { data } = await send<Detail>(
                'GET',
                `/workspace/forms/${form.id}/responses/${id}`,
            );
            detail = data;
        } catch {
            toast.error("That response didn't load.");
        } finally {
            detailLoading = false;
        }
    }

    async function move(ids: number[], to: string, withNote: string | null) {
        try {
            const { message } = await send(
                'PATCH',
                `/workspace/forms/${form.id}/responses/stage`,
                { ids, stage: to, note: withNote },
            );
            toast.show(message ?? 'Moved.');
            selected = [];
            moveNote = '';
            router.reload({ only: ['page'] });

            if (detail && ids.includes(detail.id)) {
                await open(detail.id);
            }
        } catch (e) {
            toast.error(
                e instanceof FormsHttpError ? e.message : "That didn't save.",
            );
        }
    }

    async function addNote() {
        if (!detail || note.trim() === '') {
            return;
        }

        try {
            await send(
                'POST',
                `/workspace/forms/${form.id}/responses/${detail.id}/notes`,
                { text: note },
            );
            note = '';
            await open(detail.id);
        } catch (e) {
            toast.error(
                e instanceof FormsHttpError ? e.message : "That didn't save.",
            );
        }
    }

    $effect(() => {
        if (tab === 'summary' && summary === null) {
            void send<typeof summary>(
                'GET',
                `/workspace/forms/${form.id}/summary`,
            ).then(({ data }) => (summary = data));
        }

        if (tab === 'pending' && pending === null) {
            void send<typeof pending>(
                'GET',
                `/workspace/forms/${form.id}/pending`,
            ).then(({ data }) => (pending = data));
        }
    });

    async function remind() {
        reminding = true;

        try {
            const { message } = await send(
                'POST',
                `/workspace/forms/${form.id}/remind`,
            );
            toast.show(message ?? 'Reminded.');
        } catch (e) {
            toast.error(
                e instanceof FormsHttpError
                    ? e.message
                    : "The reminders didn't go.",
            );
        } finally {
            reminding = false;
        }
    }

    function find(schema: Schema, id: string): Block | undefined {
        return schema.steps.flatMap((s) => s.blocks).find((b) => b.id === id);
    }

    const tabs = [
        { value: 'responses', label: 'Responses' },
        { value: 'summary', label: 'Summary' },
        { value: 'pending', label: "Who hasn't answered" },
    ];
</script>

<svelte:head><title>{form.title} · Responses</title></svelte:head>

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
        </div>
        {#if props.can_edit}<a
                href="/workspace/forms/{form.id}/edit"
                use:inertia
                class="btn"><Pencil class="h-3.5 w-3.5" />Builder</a
            >{/if}
    {/snippet}

    <div class="mx-auto w-full max-w-6xl pb-16">
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PillGroup options={tabs} bind:value={tab} />
            <div class="flex-1"></div>
            <a
                class="btn"
                href="/workspace/forms/{form.id}/responses/export{shownQuery
                    ? `?${shownQuery}`
                    : ''}"><Download class="h-3.5 w-3.5" />Download .xlsx</a
            >
            {#if props.can_open_as_spreadsheet}
                <button
                    type="button"
                    class="btn"
                    title="Saved as one of your own documents; share it like any other"
                    onclick={() =>
                        router.post(
                            `/workspace/forms/${form.id}/responses/spreadsheet${shownQuery ? `?${shownQuery}` : ''}`,
                        )}
                    ><FileSpreadsheet class="h-3.5 w-3.5" />Open as spreadsheet</button
                >
            {/if}
            <a
                class="btn"
                href="/workspace/forms/{form.id}/responses/files{shownQuery
                    ? `?${shownQuery}`
                    : ''}"><FileArchive class="h-3.5 w-3.5" />Files (.zip)</a
            >
        </div>

        {#if tab === 'responses'}
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 basis-48">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                    />
                    <label for="{uid}-q" class="sr-only"
                        >Search names and references</label
                    >
                    <input
                        id="{uid}-q"
                        class="input pl-8"
                        placeholder="Search names and references"
                        bind:value={q}
                        oninput={soon}
                    />
                </div>
                <label for="{uid}-stage" class="sr-only">Stage</label>
                <select
                    id="{uid}-stage"
                    class="input w-auto"
                    bind:value={stage}
                    onchange={apply}
                >
                    <option value="">Every stage</option>
                    {#each form.stages as s (s.id)}<option value={s.id}
                            >{s.name}</option
                        >{/each}
                </select>
                {#if props.organizations.length > 1}
                    <label for="{uid}-org" class="sr-only">Office</label>
                    <select
                        id="{uid}-org"
                        class="input w-auto max-w-56"
                        bind:value={organization}
                        onchange={apply}
                    >
                        <option value="">Every office</option>
                        {#each props.organizations as o (o.id)}<option
                                value={o.id}>{o.name}</option
                            >{/each}
                    </select>
                {/if}
                {#if filterable.length}
                    <label for="{uid}-question" class="sr-only">Answer to</label
                    >
                    <select
                        id="{uid}-question"
                        class="input w-auto max-w-56"
                        bind:value={question}
                        onchange={() => {
                            answer = '';

                            if (!question) {
                                apply();
                            }
                        }}
                    >
                        <option value="">Any answers</option>
                        {#each filterable as f (f.id)}<option value={f.id}
                                >{say(f.label, language)}</option
                            >{/each}
                    </select>
                    {#if question}
                        {@const chosen = filterable.find(
                            (f) => f.id === question,
                        )}
                        <label for="{uid}-answer" class="sr-only">Answer</label>
                        <select
                            id="{uid}-answer"
                            class="input w-auto max-w-48"
                            bind:value={answer}
                            onchange={apply}
                        >
                            <option value="">Choose</option>
                            {#if chosen?.type === 'yes_no'}<option value="yes"
                                    >Yes</option
                                ><option value="no">No</option>
                            {:else}{#each chosen?.options ?? [] as o (o.id)}<option
                                        value={o.id}
                                        >{say(o.label, language)}</option
                                    >{/each}{/if}
                        </select>
                    {/if}
                {/if}
                <label for="{uid}-from" class="sr-only">From</label>
                <input
                    id="{uid}-from"
                    type="date"
                    class="input w-auto"
                    bind:value={from}
                    onchange={apply}
                />
                <label for="{uid}-to" class="sr-only">To</label>
                <input
                    id="{uid}-to"
                    type="date"
                    class="input w-auto"
                    bind:value={to}
                    onchange={apply}
                />
            </div>

            <details class="mb-3 text-[13px]">
                <summary class="cursor-pointer text-fg-muted">Columns</summary>
                <div class="mt-2 flex flex-wrap gap-3">
                    {#each props.questions.filter((x) => x.type !== 'group') as col (col.id)}
                        <label class="flex items-center gap-1.5 text-fg"
                            ><input
                                type="checkbox"
                                checked={columns.includes(col.id)}
                                onchange={(e) =>
                                    (columns = e.currentTarget.checked
                                        ? [...columns, col.id]
                                        : columns.filter((c) => c !== col.id))}
                            />{say(col.label, language) ||
                                'Untitled'}{col.earlier
                                ? ' (earlier)'
                                : ''}</label
                        >
                    {/each}
                </div>
            </details>

            {#if selected.length}
                <div
                    class="panel mb-3 flex flex-wrap items-center gap-2 p-2 text-[13px]"
                >
                    <span class="text-fg">{selected.length} chosen</span>
                    <label for="{uid}-move" class="sr-only">Move to</label>
                    <select
                        id="{uid}-move"
                        class="input h-8 w-auto"
                        bind:value={moveTo}
                    >
                        <option value="">Move to…</option>
                        {#each form.stages as s (s.id)}<option value={s.id}
                                >{s.name}</option
                            >{/each}
                    </select>
                    <input
                        class="input h-8 min-w-0 flex-1"
                        placeholder="A note (optional)"
                        bind:value={moveNote}
                        aria-label="A note for the move"
                    />
                    <button
                        type="button"
                        class="btn btn-primary"
                        disabled={!moveTo}
                        onclick={() => move(selected, moveTo, moveNote)}
                        >Move</button
                    >
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => (selected = [])}>Clear</button
                    >
                </div>
            {/if}

            <p class="mb-2 text-xs text-fg-muted">
                {props.page.total}
                {props.page.total === 1 ? 'response' : 'responses'}
            </p>

            {#if rows.length === 0}
                <p class="text-[13px] text-fg-muted">
                    {props.page.total === 0 &&
                    Object.keys(props.filters).length === 0
                        ? "No responses yet. Share the form's link to get some."
                        : 'Nothing matches. Try other filters.'}
                </p>
            {:else}
                <div class="overflow-x-auto rounded-md border border-line">
                    <table class="w-full text-left text-[13px]">
                        <thead class="bg-surface-alt text-xs text-fg-muted">
                            <tr>
                                <th class="w-8 px-2 py-2"
                                    ><input
                                        type="checkbox"
                                        aria-label="Choose all shown"
                                        checked={selected.length ===
                                            rows.length}
                                        onchange={(e) =>
                                            (selected = e.currentTarget.checked
                                                ? rows.map((r) => r.id)
                                                : [])}
                                    /></th
                                >
                                <th class="px-2 py-2 font-medium">Reference</th>
                                <th class="px-2 py-2 font-medium">From</th>
                                <th class="px-2 py-2 font-medium">Sent</th>
                                <th class="px-2 py-2 font-medium">Stage</th>
                                {#each columns as c (c)}{@const col =
                                        props.questions.find(
                                            (x) => x.id === c,
                                        )}<th
                                        class="max-w-48 truncate px-2 py-2 font-medium"
                                        >{say(col?.label, language)}</th
                                    >{/each}
                                <th class="px-2 py-2 font-medium">Task</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each rows as row (row.id)}
                                <tr
                                    class="cursor-pointer border-t border-line-soft hover:bg-hover"
                                    onclick={() => open(row.id)}
                                >
                                    <td
                                        class="px-2 py-2"
                                        onclick={(e) => e.stopPropagation()}
                                        ><input
                                            type="checkbox"
                                            aria-label="Choose {row.reference}"
                                            checked={selected.includes(row.id)}
                                            onchange={(e) =>
                                                (selected = e.currentTarget
                                                    .checked
                                                    ? [...selected, row.id]
                                                    : selected.filter(
                                                          (s) => s !== row.id,
                                                      ))}
                                        /></td
                                    >
                                    <td class="px-2 py-2 font-mono text-xs"
                                        >{row.reference}</td
                                    >
                                    <td class="px-2 py-2"
                                        ><div class="max-w-48 truncate text-fg">
                                            {row.sender.name}
                                        </div>
                                        <div
                                            class="max-w-48 truncate text-xs text-fg-muted"
                                        >
                                            {form.answering === 'organization'
                                                ? row.sender.organization
                                                : (row.sender.designation ??
                                                  row.sender.organization ??
                                                  '')}
                                        </div></td
                                    >
                                    <td
                                        class="px-2 py-2 whitespace-nowrap text-fg-muted"
                                        >{when(row.submitted_at)}</td
                                    >
                                    <td class="px-2 py-2"
                                        ><span class="chip"
                                            >{stageName[row.stage ?? ''] ??
                                                'None'}</span
                                        ></td
                                    >
                                    {#each columns as c (c)}{@const col =
                                            props.questions.find(
                                                (x) => x.id === c,
                                            )}<td
                                            class="max-w-56 truncate px-2 py-2 text-fg"
                                            >{col
                                                ? answerText(
                                                      col,
                                                      row.answers[c],
                                                      language,
                                                      {},
                                                      places,
                                                  )
                                                : ''}</td
                                        >{/each}
                                    <td
                                        class="px-2 py-2"
                                        onclick={(e) => e.stopPropagation()}
                                        >{#if row.task}<a
                                                href={row.task.url}
                                                use:inertia
                                                class="text-xs text-accent hover:underline"
                                                >{row.task.trashed
                                                    ? 'Deleted'
                                                    : row.task.status.replace(
                                                          '_',
                                                          ' ',
                                                      )}</a
                                            >{/if}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
                {#if cursor}
                    <button
                        type="button"
                        class="btn mt-3"
                        disabled={loadingMore}
                        onclick={showMore}
                        >{#if loadingMore}<Spinner
                                size={14}
                            />Loading{:else}Show more{/if}</button
                    >
                {/if}
            {/if}
        {:else if tab === 'summary'}
            {#if summary === null}
                <p class="flex items-center gap-2 text-[13px] text-fg-muted">
                    <Spinner size={14} />Loading
                </p>
            {:else}
                <p class="mb-4 text-[13px] text-fg">
                    {summary.total}
                    {summary.total === 1 ? 'response' : 'responses'}{summary
                        .per_day.length
                        ? `, the most on one day ${Math.max(...summary.per_day.map((d) => d.count))}`
                        : ''}.
                </p>
                <div class="grid gap-4 md:grid-cols-2">
                    {#each summary.questions as sq (sq.id)}
                        {@const top = Math.max(
                            1,
                            ...sq.counts.map((c) => c.count),
                        )}
                        <div class="panel p-4">
                            <h3 class="mb-2 text-[13px] font-medium text-fg">
                                {say(sq.label, language)}
                            </h3>
                            <ul class="flex flex-col gap-1.5">
                                {#each sq.counts as c (c.id)}
                                    <li class="text-xs">
                                        <div
                                            class="flex justify-between gap-2 text-fg"
                                        >
                                            <span class="truncate"
                                                >{say(c.label, language)}</span
                                            ><span
                                                class="shrink-0 text-fg-muted"
                                                >{c.count}{sq.answered
                                                    ? ` (${Math.round((c.count / sq.answered) * 100)}%)`
                                                    : ''}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-0.5 h-1.5 rounded-full bg-surface-alt"
                                        >
                                            <div
                                                class="h-full rounded-full bg-accent"
                                                style:width="{(c.count / top) *
                                                    100}%"
                                            ></div>
                                        </div>
                                    </li>
                                {/each}
                            </ul>
                            <p class="mt-2 text-xs text-fg-faint">
                                {sq.answered} answered
                            </p>
                        </div>
                    {:else}
                        <p class="text-[13px] text-fg-muted">
                            No choice, yes or no, or scale questions to count.
                        </p>
                    {/each}
                </div>
            {/if}
        {:else if pending === null}
            <p class="flex items-center gap-2 text-[13px] text-fg-muted">
                <Spinner size={14} />Loading
            </p>
        {:else}
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <p class="text-[13px] text-fg">
                    {pending.answered} of {pending.total}
                    {pending.mode === 'organization' ? 'offices' : 'people'} have
                    answered.
                </p>
                {#if form.phase === 'open' && pending.answered < pending.total}
                    <button
                        type="button"
                        class="btn"
                        disabled={reminding}
                        onclick={remind}
                        >{#if reminding}<Spinner size={14} />{:else}<Bell
                                class="h-3.5 w-3.5"
                            />{/if}Remind those who haven't</button
                    >
                {/if}
            </div>
            {#if pending.people.length}
                <ul
                    class="mb-4 grid gap-x-4 border-t border-line-soft sm:grid-cols-2"
                >
                    {#each pending.people as p (p.id)}<li
                            class="border-b border-line-soft py-1.5 text-[13px] text-fg"
                        >
                            {p.name}{#if p.designation}<span
                                    class="text-xs text-fg-muted"
                                    >, {p.designation}</span
                                >{/if}
                        </li>{/each}
                </ul>
            {/if}
            {#if pending.organizations.length}
                <ul class="border-t border-line-soft">
                    {#each pending.organizations as o (o.id)}<li
                            class="flex justify-between border-b border-line-soft py-1.5 text-[13px] text-fg"
                        >
                            <span>{o.name}</span><span
                                class="text-xs text-fg-muted"
                                >{pending.mode === 'organization'
                                    ? 'Not answered'
                                    : `${o.answered} of ${o.total}`}</span
                            >
                        </li>{/each}
                </ul>
                {#if pending.mode === 'person'}<p
                        class="mt-2 text-xs text-fg-muted"
                    >
                        Other offices' people are counted, not named.
                    </p>{/if}
            {/if}
        {/if}
    </div>
</AppShell>

{#if detail || detailLoading}
    <div
        class="fixed inset-0 z-40 bg-black/30"
        role="presentation"
        onclick={() => (detail = null)}
    ></div>
    <aside
        class="fixed inset-y-0 right-0 z-50 flex w-[min(560px,100vw)] flex-col overflow-y-auto border-l border-line bg-bg p-5 shadow-xl"
        aria-label="Response"
    >
        {#if detail}
            <div class="mb-3 flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[15px] font-semibold text-fg">
                        {detail.sender.name}
                    </h2>
                    <p class="text-xs text-fg-muted">
                        {[detail.sender.designation, detail.sender.organization]
                            .filter(Boolean)
                            .join(', ')}
                    </p>
                    <p class="mt-1 text-xs text-fg-muted">
                        Reference {detail.reference} · sent {when(
                            detail.submitted_at,
                        )}{detail.updated_at &&
                        detail.updated_at !== detail.submitted_at
                            ? ` · changed ${when(detail.updated_at)}`
                            : ''} · version {detail.version_number}
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-icon"
                    aria-label="Close"
                    onclick={() => (detail = null)}
                    ><X class="h-4 w-4" /></button
                >
            </div>
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <label for="{uid}-dstage" class="text-xs text-fg-muted"
                    >Stage</label
                >
                <select
                    id="{uid}-dstage"
                    class="input h-8 w-auto"
                    value={detail.stage ?? ''}
                    onchange={(e) =>
                        move([detail!.id], e.currentTarget.value, null)}
                >
                    {#each form.stages as s (s.id)}<option value={s.id}
                            >{s.name}</option
                        >{/each}
                </select>
                {#if detail.task}<a
                        href={detail.task.url}
                        use:inertia
                        class="text-xs text-accent hover:underline"
                        >Task: {detail.task.title}</a
                    >{/if}
            </div>
            <dl class="flex flex-col gap-3">
                {#each detail.schema.steps
                    .flatMap((s) => s.blocks)
                    .filter((b) => b.label !== undefined || b.type === 'declaration') as block (block.id)}
                    <div>
                        <dt class="text-xs text-fg-muted">
                            {say(block.label, language) ||
                                say(block.text, language)}
                        </dt>
                        <dd class="text-[14px] whitespace-pre-wrap text-fg">
                            {#if block.type === 'group'}
                                {#each Array.isArray(detail.answers[block.id]) ? (detail.answers[block.id] as Answers[]) : [] as entry, i (i)}
                                    <div
                                        class="mt-1 rounded border border-line-soft px-2 py-1"
                                    >
                                        {#each block.blocks ?? [] as c (c.id)}<div
                                                class="text-[13px]"
                                            >
                                                <span class="text-fg-muted"
                                                    >{say(
                                                        c.label,
                                                        language,
                                                    )}:</span
                                                >
                                                {#if c.type === 'file'}{#each (entry[c.id] as number[] | undefined) ?? [] as fid (fid)}<a
                                                            class="text-accent underline"
                                                            href={detail.files[
                                                                fid
                                                            ]?.url}
                                                            target="_blank"
                                                            rel="noopener"
                                                            >{detail.files[fid]
                                                                ?.name ??
                                                                'file'}</a
                                                        >
                                                    {/each}{:else}{answerText(
                                                        c,
                                                        entry[c.id],
                                                        language,
                                                        detail.files,
                                                        places,
                                                    )}{/if}
                                            </div>{/each}
                                    </div>
                                {:else}<span class="text-fg-faint"
                                        >No answer</span
                                    >{/each}
                            {:else if block.type === 'file'}
                                {#each (detail.answers[block.id] as number[] | undefined) ?? [] as fid (fid)}<a
                                        class="mr-2 text-accent underline"
                                        href={detail.files[fid]?.url}
                                        target="_blank"
                                        rel="noopener"
                                        >{detail.files[fid]?.name ?? 'file'}</a
                                    >{:else}<span class="text-fg-faint"
                                        >No answer</span
                                    >{/each}
                            {:else}
                                {answerText(
                                    block,
                                    detail.answers[block.id],
                                    language,
                                    detail.files,
                                    places,
                                ) || 'No answer'}
                            {/if}
                        </dd>
                    </div>
                {/each}
                {#each Object.keys(detail.answers).filter((id) => !find(detail!.schema, id)) as id (id)}
                    {@const earlier = props.questions.find((x) => x.id === id)}
                    {#if earlier}
                        <div>
                            <dt class="text-xs text-fg-muted">
                                {say(earlier.label, language)} (earlier question)
                            </dt>
                            <dd class="text-[14px] text-fg">
                                {answerText(
                                    earlier,
                                    detail.answers[id],
                                    language,
                                    detail.files,
                                    places,
                                )}
                            </dd>
                        </div>
                    {/if}
                {/each}
            </dl>

            <div class="mt-6 border-t border-line pt-4">
                <h3 class="section-title mb-2">Notes and history</h3>
                <div class="mb-3 flex gap-2">
                    <input
                        class="input min-w-0 flex-1"
                        placeholder="A note for the form's side"
                        bind:value={note}
                        aria-label="A note"
                        onkeydown={(e) => e.key === 'Enter' && addNote()}
                    />
                    <button
                        type="button"
                        class="btn"
                        disabled={note.trim() === ''}
                        onclick={addNote}>Add</button
                    >
                </div>
                <ul class="flex flex-col gap-2">
                    {#each detail.history as h, i (i)}
                        <li class="text-[13px]">
                            <span class="text-fg">{h.text}</span>{#if h.by}<span
                                    class="text-fg-muted"
                                >
                                    · {h.by}</span
                                >{/if}<span class="text-xs text-fg-faint">
                                · {when(h.at)}</span
                            >{#if h.note}<p
                                    class="mt-0.5 rounded bg-surface-alt px-2 py-1 text-fg"
                                >
                                    {h.note}
                                </p>{/if}
                        </li>
                    {/each}
                </ul>
            </div>
        {:else}
            <p class="flex items-center gap-2 text-[13px] text-fg-muted">
                <Spinner size={14} />Loading
            </p>
        {/if}
    </aside>
{/if}
