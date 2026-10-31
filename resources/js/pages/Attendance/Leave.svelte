<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { Briefcase, CalendarDays } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import {
        LEAVE_KIND_LABEL,
        LEAVE_STATUS_LABEL,
    } from '../../lib/attendanceRegister';
    import type {
        LeaveItem,
        LeaveKind,
        LeaveStatus,
        RecordablePerson,
    } from '../../lib/attendanceRegister';
    import { dayRange, isoDay } from '../../lib/dates';

    /**
     * Leave and kaaj: ask for it, see and cancel one's own requests, decide
     * those of the people below, and record it for them directly.
     */
    let {
        can_ask,
        mine,
        to_decide,
        can_record_for,
    }: {
        can_ask: boolean;
        mine: LeaveItem[];
        to_decide: LeaveItem[];
        can_record_for: RecordablePerson[];
    } = $props();

    const uid = $props.id();
    const today = isoDay(new Date());

    const KINDS = [
        { value: 'leave', label: 'Leave' },
        { value: 'kaaj', label: 'Kaaj' },
    ];

    const STATUS_TONE: Record<LeaveStatus, string> = {
        pending: 'text-fg-muted',
        approved: 'text-success',
        rejected: 'text-danger',
        cancelled: 'text-fg-faint',
    };

    // ---- Asking ----
    const askForm = useForm({
        kind: 'leave' as LeaveKind,
        starts_on: today,
        ends_on: today,
        place: '',
        reason: '',
    });

    function ask(e: SubmitEvent) {
        e.preventDefault();
        askForm.post('/workspace/leave', {
            preserveScroll: true,
            onSuccess: () => askForm.reset(),
        });
    }

    // ---- Recording ----
    const recordForm = useForm({
        member_id: '',
        kind: 'leave' as LeaveKind,
        starts_on: today,
        ends_on: today,
        place: '',
        reason: '',
    });

    const organizations = $derived([
        ...new Set(can_record_for.map((p) => p.organization)),
    ]);

    function record(e: SubmitEvent) {
        e.preventDefault();
        recordForm.post(`/workspace/members/${recordForm.member_id}/leave`, {
            preserveScroll: true,
            onSuccess: () => recordForm.reset(),
        });
    }

    // ---- Deciding ----
    let rejecting = $state<number | null>(null);
    let note = $state('');

    function approve(leave: LeaveItem) {
        router.post(
            `/workspace/leave/${leave.id}/approve`,
            {},
            { preserveScroll: true },
        );
    }

    function startReject(leave: LeaveItem) {
        rejecting = leave.id;
        note = '';
    }

    function reject(e: SubmitEvent, leave: LeaveItem) {
        e.preventDefault();
        router.post(
            `/workspace/leave/${leave.id}/reject`,
            { note },
            {
                preserveScroll: true,
                onSuccess: () => (rejecting = null),
            },
        );
    }

    function cancel(leave: LeaveItem) {
        if (!confirm(`Cancel this ${leave.kind}?`)) {
            return;
        }

        router.post(
            `/workspace/leave/${leave.id}/cancel`,
            {},
            { preserveScroll: true },
        );
    }

    function what(leave: LeaveItem): string {
        const days = `${leave.days} ${leave.days === 1 ? 'day' : 'days'}`;
        const where =
            leave.kind === 'kaaj' && leave.place ? ` in ${leave.place}` : '';

        return `${LEAVE_KIND_LABEL[leave.kind]}${where}, ${dayRange(leave.starts_on, leave.ends_on)} (${days})`;
    }
</script>

<svelte:head><title>Leave and kaaj · Workspace</title></svelte:head>

{#snippet kindIcon(kind: LeaveKind)}
    {#if kind === 'kaaj'}
        <Briefcase class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
    {:else}
        <CalendarDays class="h-3.5 w-3.5 shrink-0 text-fg-muted" />
    {/if}
{/snippet}

{#snippet fields(form: typeof askForm | typeof recordForm, prefix: string)}
    <div class="flex flex-col gap-1">
        <span class="label">Kind</span>
        <div>
            <PillGroup options={KINDS} bind:value={form.kind} />
        </div>
        {#if form.errors.kind}
            <p class="text-xs text-danger">{form.errors.kind}</p>
        {/if}
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
            <label class="label" for="{prefix}-from">First day</label>
            <input
                id="{prefix}-from"
                type="date"
                class="input"
                required
                bind:value={form.starts_on}
                onchange={() => {
                    if (form.ends_on < form.starts_on) {
                        form.ends_on = form.starts_on;
                    }
                }}
            />
            {#if form.errors.starts_on}
                <p class="text-xs text-danger">{form.errors.starts_on}</p>
            {/if}
        </div>
        <div class="flex flex-col gap-1">
            <label class="label" for="{prefix}-to">Last day</label>
            <input
                id="{prefix}-to"
                type="date"
                class="input"
                required
                min={form.starts_on}
                bind:value={form.ends_on}
            />
            {#if form.errors.ends_on}
                <p class="text-xs text-danger">{form.errors.ends_on}</p>
            {/if}
        </div>
    </div>
    {#if form.kind === 'kaaj'}
        <div class="flex flex-col gap-1">
            <label class="label" for="{prefix}-place">Where</label>
            <input
                id="{prefix}-place"
                type="text"
                class="input"
                required
                maxlength="255"
                placeholder="Pokhara"
                bind:value={form.place}
            />
            {#if form.errors.place}
                <p class="text-xs text-danger">{form.errors.place}</p>
            {/if}
        </div>
    {/if}
    <div class="flex flex-col gap-1">
        <label class="label" for="{prefix}-reason">Reason</label>
        <textarea
            id="{prefix}-reason"
            class="input min-h-16"
            required
            maxlength="500"
            bind:value={form.reason}
        ></textarea>
        {#if form.errors.reason}
            <p class="text-xs text-danger">{form.errors.reason}</p>
        {/if}
    </div>
{/snippet}

<AppShell>
    {#snippet bar()}
        <span class="truncate font-medium">Leave and kaaj</span>
    {/snippet}

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-8">
            {#if to_decide.length > 0}
                <section>
                    <h2 class="section-title mb-2">
                        Waiting for your decision
                        <span class="section-count">{to_decide.length}</span>
                    </h2>
                    <div class="border-t border-line">
                        {#each to_decide as leave (leave.id)}
                            <div
                                class="flex flex-col gap-2 border-b border-line py-3"
                            >
                                <div class="flex items-start gap-3">
                                    <Avatar
                                        name={leave.member?.name}
                                        size="md"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[13px]">
                                            <span class="font-medium"
                                                >{leave.member?.name}</span
                                            >
                                            {#if leave.member?.designation}
                                                <span class="text-fg-muted"
                                                    >· {leave.member
                                                        .designation}</span
                                                >
                                            {/if}
                                        </p>
                                        <p
                                            class="mt-0.5 flex items-center gap-1.5 text-xs text-fg-muted"
                                        >
                                            {@render kindIcon(leave.kind)}{what(
                                                leave,
                                            )}
                                        </p>
                                        <p class="mt-1 text-[13px]">
                                            {leave.reason}
                                        </p>
                                    </div>
                                    {#if rejecting !== leave.id}
                                        <div class="flex shrink-0 gap-1.5">
                                            <button
                                                type="button"
                                                class="btn"
                                                onclick={() =>
                                                    startReject(leave)}
                                                >Reject</button
                                            >
                                            <button
                                                type="button"
                                                class="btn-primary"
                                                onclick={() => approve(leave)}
                                                >Approve</button
                                            >
                                        </div>
                                    {/if}
                                </div>
                                {#if rejecting === leave.id}
                                    <form
                                        class="flex flex-wrap items-center gap-1.5 pl-9"
                                        onsubmit={(e) => reject(e, leave)}
                                    >
                                        <input
                                            type="text"
                                            class="input h-7 min-w-0 flex-1 py-0"
                                            aria-label="Note for {leave.member
                                                ?.name}"
                                            placeholder="A note, if you like"
                                            maxlength="500"
                                            bind:value={note}
                                        />
                                        <button
                                            type="button"
                                            class="btn-ghost"
                                            onclick={() => (rejecting = null)}
                                            >Back</button
                                        >
                                        <button type="submit" class="btn-danger"
                                            >Reject</button
                                        >
                                    </form>
                                {/if}
                            </div>
                        {/each}
                    </div>
                </section>
            {/if}

            <section>
                <h2 class="section-title mb-2">
                    Your requests
                    <span class="section-count">{mine.length}</span>
                </h2>
                {#if mine.length === 0}
                    <p class="border-t border-line py-3 text-xs text-fg-muted">
                        Nothing in the last 60 days or coming up.
                    </p>
                {:else}
                    <div class="border-t border-line">
                        {#each mine as leave (leave.id)}
                            <div
                                class="flex items-start gap-3 border-b border-line py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="flex items-center gap-1.5 text-[13px]"
                                    >
                                        {@render kindIcon(leave.kind)}{what(
                                            leave,
                                        )}
                                    </p>
                                    <p class="mt-0.5 text-xs text-fg-muted">
                                        {leave.reason}
                                    </p>
                                    <p class="mt-1 text-xs">
                                        <span class={STATUS_TONE[leave.status]}
                                            >{LEAVE_STATUS_LABEL[
                                                leave.status
                                            ]}</span
                                        >
                                        {#if leave.decided_by && leave.status !== 'cancelled'}
                                            <span class="text-fg-muted"
                                                >by {leave.decided_by
                                                    .name}</span
                                            >
                                        {/if}
                                        {#if leave.decision_note}
                                            <span class="text-fg-muted"
                                                >· "{leave.decision_note}"</span
                                            >
                                        {/if}
                                    </p>
                                </div>
                                {#if leave.can_cancel}
                                    <button
                                        type="button"
                                        class="btn-ghost shrink-0"
                                        onclick={() => cancel(leave)}
                                        >Cancel</button
                                    >
                                {/if}
                            </div>
                        {/each}
                    </div>
                {/if}
            </section>
        </div>

        <div class="space-y-6">
            {#if can_ask}
                <form onsubmit={ask} class="panel space-y-3 p-4">
                    <h2 class="section-title">Ask for leave or kaaj</h2>
                    {@render fields(askForm, `${uid}-ask`)}
                    <p class="text-xs text-fg-muted">
                        Kaaj is official duty away from the office: it never
                        counts as absence.
                    </p>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="btn-primary"
                            disabled={askForm.processing}>Ask</button
                        >
                    </div>
                </form>
            {:else if mine.length === 0 && to_decide.length === 0}
                <p class="text-xs text-fg-muted">
                    Attendance is off for your organization, so there is no
                    leave to ask for.
                </p>
            {/if}

            {#if can_record_for.length > 0}
                <form onsubmit={record} class="panel space-y-3 p-4">
                    <h2 class="section-title">Record leave or kaaj</h2>
                    <p class="text-xs text-fg-muted">
                        For someone below you, approved at once.
                    </p>
                    <div class="flex flex-col gap-1">
                        <label class="label" for="{uid}-who">Person</label>
                        <select
                            id="{uid}-who"
                            class="input"
                            required
                            bind:value={recordForm.member_id}
                        >
                            <option value="" disabled>Choose someone</option>
                            {#if organizations.length > 1}
                                {#each organizations as organization (organization)}
                                    <optgroup label={organization}>
                                        {#each can_record_for.filter((p) => p.organization === organization) as person (person.id)}
                                            <option value={String(person.id)}
                                                >{person.name}</option
                                            >
                                        {/each}
                                    </optgroup>
                                {/each}
                            {:else}
                                {#each can_record_for as person (person.id)}
                                    <option value={String(person.id)}
                                        >{person.name}{person.designation
                                            ? `, ${person.designation}`
                                            : ''}</option
                                    >
                                {/each}
                            {/if}
                        </select>
                    </div>
                    {@render fields(recordForm, `${uid}-record`)}
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="btn-primary"
                            disabled={recordForm.processing ||
                                recordForm.member_id === ''}>Record</button
                        >
                    </div>
                </form>
            {/if}
        </div>
    </div>
</AppShell>
