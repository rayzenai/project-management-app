<script lang="ts">
    import { ChevronLeft, ChevronRight } from '@lucide/svelte';
    import { isoDay, shiftDay } from '../../lib/dates';

    /**
     * A report's day: the day before, a date picker, the day after, and
     * Today while another day is shown. Only a real, different day is
     * passed on.
     */
    let {
        date,
        onchange,
    }: {
        date: string;
        onchange: (date: string) => void;
    } = $props();

    const today = isoDay(new Date());

    function pick(value: string) {
        if (value !== '' && value !== date) {
            onchange(value);
        }
    }
</script>

<div class="flex items-center gap-1">
    <button
        type="button"
        class="btn-icon"
        aria-label="The day before"
        onclick={() => pick(shiftDay(date, -1))}
    >
        <ChevronLeft class="h-4 w-4" />
    </button>
    <input
        type="date"
        class="input h-7 w-auto py-0"
        aria-label="Day"
        value={date}
        onchange={(e) => pick(e.currentTarget.value)}
    />
    <button
        type="button"
        class="btn-icon"
        aria-label="The day after"
        onclick={() => pick(shiftDay(date, 1))}
    >
        <ChevronRight class="h-4 w-4" />
    </button>
    {#if date !== today}
        <button type="button" class="btn-ghost" onclick={() => pick(today)}
            >Today</button
        >
    {/if}
</div>
