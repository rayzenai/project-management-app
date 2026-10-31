<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';
    import { WEEKDAYS } from '../../../lib/attendance';
    import type { AttendanceSettings, Season } from '../../../lib/attendance';

    /**
     * Working days and hours, with seasons that change the hours between two
     * dates (winter hours) and the grace before "late".
     */
    let {
        base,
        settings,
    }: {
        /** The organization's attendance URL (the page's `base`). */
        base: string;
        settings: AttendanceSettings;
    } = $props();

    const uid = $props.id();

    // Seeded once: switching organization or the switch is a fresh visit
    // that remounts the page. Read inside a function so the one-time
    // capture is deliberate.
    function seedHours() {
        return {
            workdays: [...settings.workdays],
            start_time: settings.start_time,
            end_time: settings.end_time,
            seasons: settings.seasons.map((s) => ({ ...s })) as Season[],
            grace_minutes: settings.grace_minutes,
        };
    }

    const hoursForm = useForm(seedHours());
    const hoursErrors = $derived(hoursForm.errors as Record<string, string>);

    function toggleWorkday(value: number) {
        hoursForm.workdays = hoursForm.workdays.includes(value)
            ? hoursForm.workdays.filter((d) => d !== value)
            : [...hoursForm.workdays, value].sort((a, b) => a - b);
    }

    function addSeason() {
        hoursForm.seasons = [
            ...hoursForm.seasons,
            { name: '', from: '', to: '', start: '10:00', end: '16:00' },
        ];
    }

    function removeSeason(index: number) {
        hoursForm.seasons = hoursForm.seasons.filter((_, i) => i !== index);
    }

    function saveHours(e: SubmitEvent) {
        e.preventDefault();
        hoursForm.put(`${base}/settings`, { preserveScroll: true });
    }
</script>

<section class="space-y-2">
    <h2 class="section-title">Working days and hours</h2>
    <form onsubmit={saveHours} class="panel space-y-5 p-4">
        <fieldset class="space-y-1.5">
            <legend class="label">Working days</legend>
            <div class="flex flex-wrap gap-1.5">
                {#each WEEKDAYS as day (day.value)}
                    {@const on = hoursForm.workdays.includes(day.value)}
                    <button
                        type="button"
                        aria-pressed={on}
                        class={`btn w-12 justify-center ${on ? 'border-accent bg-accent-soft text-accent' : 'text-fg-muted'}`}
                        onclick={() => toggleWorkday(day.value)}
                        >{day.short}</button
                    >
                {/each}
            </div>
            {#if hoursErrors.workdays}
                <p class="text-xs text-danger">
                    {hoursErrors.workdays}
                </p>
            {/if}
        </fieldset>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="flex flex-col gap-1">
                <label class="label" for="{uid}-start">Starts</label>
                <input
                    id="{uid}-start"
                    type="time"
                    class="input"
                    bind:value={hoursForm.start_time}
                    required
                />
                {#if hoursErrors.start_time}
                    <p class="text-xs text-danger">
                        {hoursErrors.start_time}
                    </p>
                {/if}
            </div>
            <div class="flex flex-col gap-1">
                <label class="label" for="{uid}-end">Ends</label>
                <input
                    id="{uid}-end"
                    type="time"
                    class="input"
                    bind:value={hoursForm.end_time}
                    required
                />
                {#if hoursErrors.end_time}
                    <p class="text-xs text-danger">
                        {hoursErrors.end_time}
                    </p>
                {/if}
            </div>
            <div class="flex flex-col gap-1">
                <label class="label" for="{uid}-grace"
                    >Late after (minutes)</label
                >
                <input
                    id="{uid}-grace"
                    type="number"
                    min="0"
                    max="120"
                    class="input"
                    bind:value={hoursForm.grace_minutes}
                    required
                />
                {#if hoursErrors.grace_minutes}
                    <p class="text-xs text-danger">
                        {hoursErrors.grace_minutes}
                    </p>
                {/if}
            </div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <h3 class="label">Seasons</h3>
                <button
                    type="button"
                    class="btn-ghost ml-auto"
                    onclick={addSeason}
                    ><Plus class="h-3.5 w-3.5" />Add season</button
                >
            </div>
            <p class="text-xs text-fg-faint">
                Other hours between two dates each year, like winter hours from
                11-02 to 01-29. Dates are month-day.
            </p>
            {#each hoursForm.seasons as season, i (i)}
                <div
                    class="grid grid-cols-2 items-start gap-2 border-t border-line pt-2 sm:grid-cols-[1fr_5rem_5rem_6.5rem_6.5rem_auto]"
                >
                    <input
                        aria-label="Season name"
                        class="input col-span-2 sm:col-span-1"
                        placeholder="Winter"
                        bind:value={season.name}
                        required
                    />
                    <input
                        aria-label="From (MM-DD)"
                        class="input font-mono"
                        placeholder="11-02"
                        bind:value={season.from}
                        required
                    />
                    <input
                        aria-label="To (MM-DD)"
                        class="input font-mono"
                        placeholder="01-29"
                        bind:value={season.to}
                        required
                    />
                    <input
                        aria-label="Starts"
                        type="time"
                        class="input"
                        bind:value={season.start}
                        required
                    />
                    <input
                        aria-label="Ends"
                        type="time"
                        class="input"
                        bind:value={season.end}
                        required
                    />
                    <button
                        type="button"
                        class="btn-icon col-span-2 justify-self-end sm:col-span-1"
                        aria-label="Remove season"
                        onclick={() => removeSeason(i)}
                        ><X class="h-3.5 w-3.5" /></button
                    >
                    {#each ['name', 'from', 'to', 'start', 'end'] as field (field)}
                        {#if hoursErrors[`seasons.${i}.${field}`]}
                            <p class="col-span-full text-xs text-danger">
                                {hoursErrors[`seasons.${i}.${field}`]}
                            </p>
                        {/if}
                    {/each}
                </div>
            {/each}
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="btn-primary"
                disabled={hoursForm.processing}>Save hours</button
            >
        </div>
    </form>
</section>
