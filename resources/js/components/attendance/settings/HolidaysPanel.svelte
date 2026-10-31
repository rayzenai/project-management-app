<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import type { Holiday } from '../../../lib/attendance';
    import { fullDay } from '../../../lib/dates';

    /**
     * The days the office is closed: nobody is expected.
     */
    let {
        base,
        holidays,
    }: {
        /** The organization's attendance URL (the page's `base`). */
        base: string;
        holidays: Holiday[];
    } = $props();

    const uid = $props.id();

    let holidayId = $state<number | null>(null);
    let holidayOpen = $state(false);
    const holidayForm = useForm({ date: '', name: '' });

    function startHoliday(holiday: Holiday | null) {
        holidayId = holiday?.id ?? null;
        holidayOpen = true;
        holidayForm.clearErrors();
        holidayForm.date = holiday?.date ?? '';
        holidayForm.name = holiday?.name ?? '';
    }

    function saveHoliday(e: SubmitEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => (holidayOpen = false),
        };

        if (holidayId === null) {
            holidayForm.post(`${base}/holidays`, options);
        } else {
            holidayForm.patch(`${base}/holidays/${holidayId}`, options);
        }
    }

    function removeHoliday(holiday: Holiday) {
        if (confirm(`Remove ${holiday.name}?`)) {
            router.delete(`${base}/holidays/${holiday.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<section class="space-y-2">
    <div class="flex items-center gap-2">
        <h2 class="section-title">
            Holidays <span class="section-count">{holidays.length}</span>
        </h2>
        <button
            type="button"
            class="btn ml-auto"
            onclick={() => startHoliday(null)}
            ><Plus class="h-3.5 w-3.5" />Add holiday</button
        >
    </div>
    <p class="text-xs text-fg-muted">
        Nobody is expected on a holiday. From the start of this year.
    </p>
    {#if holidayOpen}
        <form
            onsubmit={saveHoliday}
            class="panel grid gap-3 p-4 sm:grid-cols-[10rem_1fr_auto] sm:items-start"
        >
            <div class="flex flex-col gap-1">
                <label class="label" for="{uid}-hol-date">Date</label>
                <input
                    id="{uid}-hol-date"
                    type="date"
                    class="input"
                    bind:value={holidayForm.date}
                    required
                />
                {#if holidayForm.errors.date}
                    <p class="text-xs text-danger">
                        {holidayForm.errors.date}
                    </p>
                {/if}
            </div>
            <div class="flex flex-col gap-1">
                <label class="label" for="{uid}-hol-name">Name</label>
                <input
                    id="{uid}-hol-name"
                    class="input"
                    placeholder="Tihar"
                    bind:value={holidayForm.name}
                    required
                />
                {#if holidayForm.errors.name}
                    <p class="text-xs text-danger">
                        {holidayForm.errors.name}
                    </p>
                {/if}
            </div>
            <div class="flex justify-end gap-1.5 sm:pt-5">
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (holidayOpen = false)}>Cancel</button
                >
                <button
                    type="submit"
                    class="btn-primary"
                    disabled={holidayForm.processing}
                    >{holidayId === null ? 'Add' : 'Save'}</button
                >
            </div>
        </form>
    {/if}
    <div class="border-t border-line">
        {#each holidays as holiday (holiday.id)}
            <div class="row min-h-11 px-2 lg:px-2">
                <span
                    class="w-32 shrink-0 font-mono text-xs text-fg-muted tabular-nums"
                    >{fullDay(holiday.date)}</span
                >
                <span class="min-w-0 flex-1 truncate">{holiday.name}</span>
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => startHoliday(holiday)}>Edit</button
                >
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => removeHoliday(holiday)}>Remove</button
                >
            </div>
        {:else}
            <p class="py-3 text-xs text-fg-muted">No holidays yet.</p>
        {/each}
    </div>
</section>
