<script lang="ts">
    import { ArrowDown, ArrowUp, Plus, X } from '@lucide/svelte';
    import { FormsHttpError, send } from '../../../lib/forms/http';
    import type { Stage } from '../../../lib/forms/types';
    import { toast } from '../../../lib/toast.svelte';
    import Spinner from '../../Spinner.svelte';
    import { fromLocalInput, toLocalInput } from './builderTypes';
    import type { BuilderData } from './builderTypes';

    /** The form's settings beside its questions: dates, limits, who answers, editing, stages. */
    let {
        data,
        onupdate,
    }: { data: BuilderData; onupdate: (next: BuilderData) => void } = $props();

    const uid = $props.id();
    // svelte-ignore state_referenced_locally
    let title = $state(data.form.title);
    // svelte-ignore state_referenced_locally
    let opens = $state(toLocalInput(data.form.opens_at));
    // svelte-ignore state_referenced_locally
    let closes = $state(toLocalInput(data.form.closes_at));
    // svelte-ignore state_referenced_locally
    let limit = $state(
        data.form.response_limit === null
            ? ''
            : String(data.form.response_limit),
    );
    // svelte-ignore state_referenced_locally
    let answering = $state(data.form.answering);
    // svelte-ignore state_referenced_locally
    let allowEdit = $state(data.form.settings.allow_edit);
    // svelte-ignore state_referenced_locally
    let reviewPage = $state(data.form.settings.review_page);
    // svelte-ignore state_referenced_locally
    let notify = $state(data.form.settings.notify);
    // svelte-ignore state_referenced_locally
    let stages = $state<Stage[]>(
        structuredClone($state.snapshot(data.form.stages)),
    );
    let saving = $state(false);
    let errors = $state<Record<string, string>>({});

    async function save(event: SubmitEvent) {
        event.preventDefault();
        saving = true;
        errors = {};

        try {
            const { data: next, message } = await send<BuilderData>(
                'PATCH',
                `/workspace/forms/${data.form.id}`,
                {
                    title,
                    opens_at: fromLocalInput(opens),
                    closes_at: fromLocalInput(closes),
                    response_limit: limit.trim() === '' ? null : Number(limit),
                    answering,
                    allow_edit: allowEdit,
                    review_page: reviewPage,
                    notify,
                    stages,
                },
            );
            onupdate(next);
            toast.show(message ?? 'Saved.');
        } catch (e) {
            errors = e instanceof FormsHttpError ? e.errors : {};
            toast.error(e instanceof Error ? e.message : "That didn't save.");
        } finally {
            saving = false;
        }
    }

    function moveStage(i: number, by: number) {
        const next = [...stages];
        const [item] = next.splice(i, 1);
        next.splice(i + by, 0, item);
        stages = next;
    }
</script>

<form class="flex flex-col gap-4" onsubmit={save}>
    <div>
        <label for="{uid}-title" class="label mb-1 block">Title</label>
        <input
            id="{uid}-title"
            class="input"
            bind:value={title}
            maxlength="200"
        />
        {#if errors.title}<p class="mt-1 text-xs text-danger">
                {errors.title}
            </p>{/if}
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="{uid}-opens" class="label mb-1 block"
                >Opens (Nepal time; empty: when published)</label
            >
            <input
                id="{uid}-opens"
                type="datetime-local"
                class="input"
                bind:value={opens}
            />
        </div>
        <div>
            <label for="{uid}-closes" class="label mb-1 block"
                >Closes (Nepal time)</label
            >
            <input
                id="{uid}-closes"
                type="datetime-local"
                class="input"
                bind:value={closes}
            />
            {#if errors.closes_at}<p class="mt-1 text-xs text-danger">
                    {errors.closes_at}
                </p>{/if}
        </div>
    </div>
    <div>
        <label for="{uid}-limit" class="label mb-1 block"
            >Most responses (empty: no limit)</label
        >
        <input
            id="{uid}-limit"
            class="input w-40"
            inputmode="numeric"
            bind:value={limit}
            placeholder="No limit"
        />
        <p class="mt-1 text-xs text-fg-muted">
            It closes itself once this many are in, such as the seats of a
            training.
        </p>
    </div>
    <fieldset>
        <legend class="label mb-1">Who answers</legend>
        <label class="flex items-start gap-2 text-[13px] text-fg">
            <input
                type="radio"
                value="person"
                bind:group={answering}
                disabled={data.form.answering_locked}
                class="mt-0.5"
            />
            <span>Each person sends their own response</span>
        </label>
        <label class="mt-1 flex items-start gap-2 text-[13px] text-fg">
            <input
                type="radio"
                value="organization"
                bind:group={answering}
                disabled={data.form.answering_locked}
                class="mt-0.5"
            />
            <span
                >One response per office (a data call): anyone there can start
                it, and the others can change it</span
            >
        </label>
        {#if data.form.answering_locked}<p class="mt-1 text-xs text-fg-muted">
                Responses are in, so this can't change now.
            </p>{/if}
        {#if errors.answering}<p class="mt-1 text-xs text-danger">
                {errors.answering}
            </p>{/if}
    </fieldset>
    <div class="flex flex-col gap-1.5">
        <label class="flex items-center gap-2 text-[13px] text-fg"
            ><input type="checkbox" bind:checked={allowEdit} />People can change
            their response until it closes</label
        >
        <label class="flex items-center gap-2 text-[13px] text-fg"
            ><input type="checkbox" bind:checked={reviewPage} />End a form of
            several steps on a page to check the answers</label
        >
        <label class="flex items-center gap-2 text-[13px] text-fg"
            ><input type="checkbox" bind:checked={notify} />Tell the respondents
            when it is first published</label
        >
    </div>
    <div>
        <span class="label mb-1 block">Stages a response moves through</span>
        <div class="flex flex-col gap-1.5">
            {#each stages as stage, i (stage.id)}
                <div class="flex items-center gap-1.5">
                    <label for="{uid}-stage-{stage.id}" class="sr-only"
                        >Stage {i + 1}</label
                    >
                    <input
                        id="{uid}-stage-{stage.id}"
                        class="input min-w-0 flex-1"
                        maxlength="40"
                        bind:value={stage.name}
                    />
                    <button
                        type="button"
                        class="btn-icon"
                        aria-label="Move up"
                        disabled={i === 0}
                        onclick={() => moveStage(i, -1)}
                        ><ArrowUp class="h-3.5 w-3.5" /></button
                    >
                    <button
                        type="button"
                        class="btn-icon"
                        aria-label="Move down"
                        disabled={i === stages.length - 1}
                        onclick={() => moveStage(i, 1)}
                        ><ArrowDown class="h-3.5 w-3.5" /></button
                    >
                    <button
                        type="button"
                        class="btn-icon"
                        aria-label="Remove {stage.name}"
                        disabled={stages.length === 1}
                        onclick={() =>
                            (stages = stages.filter((s) => s.id !== stage.id))}
                        ><X class="h-3.5 w-3.5" /></button
                    >
                </div>
            {/each}
            {#if stages.length < 12}
                <button
                    type="button"
                    class="btn-ghost w-fit text-xs"
                    onclick={() =>
                        (stages = [...stages, { id: '', name: 'New stage' }])}
                    ><Plus class="h-3.5 w-3.5" />Add a stage</button
                >
            {/if}
        </div>
        <p class="mt-1 text-xs text-fg-muted">
            New responses start in the first. Respondents never see stages.
        </p>
    </div>
    <div>
        <button class="btn btn-primary" disabled={saving}
            >{#if saving}<Spinner size={14} />{/if}Save settings</button
        >
    </div>
</form>
