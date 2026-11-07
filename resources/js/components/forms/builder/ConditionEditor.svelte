<script lang="ts">
    import { Plus, X } from '@lucide/svelte';
    import { say } from '../../../lib/forms/answers';
    import type {
        Block,
        Condition,
        ConditionOp,
        ConditionTest,
    } from '../../../lib/forms/types';

    /**
     * "Show only when": tests on earlier questions, joined by all of or any
     * of. A question with nothing to test against (a file, a place) offers
     * answered and not answered only.
     */
    let {
        condition = $bindable(),
        earlier,
    }: {
        condition: Condition | null | undefined;
        /** Questions before this block (or step), in order. */
        earlier: Block[];
    } = $props();

    const uid = $props.id();

    function opsFor(
        q: Block | undefined,
    ): { value: ConditionOp; label: string }[] {
        const base: { value: ConditionOp; label: string }[] = [
            { value: 'answered', label: 'is answered' },
            { value: 'not_answered', label: 'is not answered' },
        ];

        switch (q?.type) {
            case 'choice':
            case 'yes_no':
            case 'number':
            case 'scale':
            case 'short_text':
            case 'email':
            case 'phone':
            case 'url':
                return [
                    { value: 'equals', label: 'is' },
                    { value: 'not_equals', label: 'is not' },
                    ...base,
                ];
            case 'choices':
                return [{ value: 'includes', label: 'includes' }, ...base];
            default:
                return base;
        }
    }

    function question(id: string): Block | undefined {
        return earlier.find((q) => q.id === id);
    }

    function defaults(q: Block | undefined): ConditionTest {
        const op = opsFor(q)[0].value;
        const value =
            q?.type === 'yes_no'
                ? true
                : (q?.options?.[0]?.id ??
                  (q?.type === 'number' || q?.type === 'scale' ? 1 : ''));

        return {
            question: q?.id ?? '',
            op,
            ...(op === 'answered' ? {} : { value }),
        };
    }

    function add() {
        const first = earlier[earlier.length - 1];
        condition = {
            match: condition?.match ?? 'all',
            conditions: [...(condition?.conditions ?? []), defaults(first)],
        };
    }

    function update(i: number, test: ConditionTest) {
        const conditions = [...(condition?.conditions ?? [])];
        conditions[i] = test;
        condition = { match: condition?.match ?? 'all', conditions };
    }

    function remove(i: number) {
        const conditions = (condition?.conditions ?? []).filter(
            (_, j) => j !== i,
        );
        condition = conditions.length
            ? { match: condition?.match ?? 'all', conditions }
            : null;
    }
</script>

{#if earlier.length === 0}
    <p class="text-xs text-fg-faint">
        Conditions look at questions before this one; there are none yet.
    </p>
{:else if !condition || condition.conditions.length === 0}
    <button type="button" class="btn-ghost w-fit text-xs" onclick={add}
        ><Plus class="h-3.5 w-3.5" />Show only when…</button
    >
{:else}
    <div
        class="flex flex-col gap-1.5 rounded-md border border-line-soft bg-surface-alt p-2"
    >
        <div class="flex items-center gap-1.5 text-xs text-fg-muted">
            Show only when
            {#if condition.conditions.length > 1}
                <label for="{uid}-match" class="sr-only">All or any</label>
                <select
                    id="{uid}-match"
                    class="input h-7 w-auto text-xs"
                    value={condition.match}
                    onchange={(e) =>
                        (condition = {
                            ...condition!,
                            match: e.currentTarget.value as 'all' | 'any',
                        })}
                >
                    <option value="all">all of these hold</option>
                    <option value="any">any of these holds</option>
                </select>
            {/if}
        </div>
        {#each condition.conditions as test, i (i)}
            {@const q = question(test.question)}
            <div class="flex flex-wrap items-center gap-1.5">
                <label for="{uid}-q{i}" class="sr-only">Question</label>
                <select
                    id="{uid}-q{i}"
                    class="input h-8 min-w-0 flex-1 basis-40 text-xs"
                    value={test.question}
                    onchange={(e) =>
                        update(i, defaults(question(e.currentTarget.value)))}
                >
                    {#each earlier as e (e.id)}<option value={e.id}
                            >{say(e.label, 'en') || 'Untitled question'}</option
                        >{/each}
                </select>
                <label for="{uid}-op{i}" class="sr-only">Test</label>
                <select
                    id="{uid}-op{i}"
                    class="input h-8 w-auto text-xs"
                    value={test.op}
                    onchange={(e) => {
                        const op = e.currentTarget.value as ConditionOp;
                        update(
                            i,
                            op === 'answered' || op === 'not_answered'
                                ? { question: test.question, op }
                                : { ...defaults(q), op },
                        );
                    }}
                >
                    {#each opsFor(q) as op (op.value)}<option value={op.value}
                            >{op.label}</option
                        >{/each}
                </select>
                {#if test.op !== 'answered' && test.op !== 'not_answered'}
                    <label for="{uid}-v{i}" class="sr-only">Value</label>
                    {#if q?.type === 'choice' || q?.type === 'choices'}
                        <select
                            id="{uid}-v{i}"
                            class="input h-8 w-auto max-w-48 text-xs"
                            value={test.value}
                            onchange={(e) =>
                                update(i, {
                                    ...test,
                                    value: e.currentTarget.value,
                                })}
                        >
                            {#each q.options ?? [] as o (o.id)}<option
                                    value={o.id}
                                    >{say(o.label, 'en') ||
                                        'Untitled answer'}</option
                                >{/each}
                        </select>
                    {:else if q?.type === 'yes_no'}
                        <select
                            id="{uid}-v{i}"
                            class="input h-8 w-auto text-xs"
                            value={String(test.value)}
                            onchange={(e) =>
                                update(i, {
                                    ...test,
                                    value: e.currentTarget.value === 'true',
                                })}
                        >
                            <option value="true">Yes</option>
                            <option value="false">No</option>
                        </select>
                    {:else if q?.type === 'number' || q?.type === 'scale'}
                        <input
                            id="{uid}-v{i}"
                            class="input h-8 w-24 text-xs"
                            inputmode="decimal"
                            value={test.value ?? ''}
                            oninput={(e) =>
                                update(i, {
                                    ...test,
                                    value: Number(e.currentTarget.value),
                                })}
                        />
                    {:else}
                        <input
                            id="{uid}-v{i}"
                            class="input h-8 w-40 text-xs"
                            value={test.value ?? ''}
                            oninput={(e) =>
                                update(i, {
                                    ...test,
                                    value: e.currentTarget.value,
                                })}
                        />
                    {/if}
                {/if}
                <button
                    type="button"
                    class="btn-icon h-7 w-7"
                    aria-label="Remove this test"
                    onclick={() => remove(i)}><X class="h-3 w-3" /></button
                >
            </div>
        {/each}
        <button type="button" class="btn-ghost w-fit text-xs" onclick={add}
            ><Plus class="h-3.5 w-3.5" />Add a test</button
        >
    </div>
{/if}
