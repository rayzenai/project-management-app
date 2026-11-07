<script lang="ts">
    import {
        BS_FIRST_YEAR,
        BS_LAST_YEAR,
        BS_MONTHS,
        BS_MONTHS_NE,
        adToBs,
        bsMonthLength,
        bsToAd,
    } from '../../lib/forms/bsCalendar';

    /**
     * A date in the form's calendar: BS as year, month and day lists (the
     * AD date shown beside it), AD as the browser's date picker. The answer
     * is `{bs}` or `{ad}`; the server works out the other.
     */
    let {
        id,
        calendar = 'bs',
        value = $bindable(),
        language = 'en',
        disabled = false,
        invalid = false,
    }: {
        id: string;
        calendar?: 'bs' | 'ad';
        value: { bs?: string; ad?: string } | null | undefined;
        language?: 'en' | 'ne';
        disabled?: boolean;
        invalid?: boolean;
    } = $props();

    const parts = $derived.by(() => {
        const bs = value?.bs ?? (value?.ad ? adToBs(value.ad) : null) ?? '';
        const [y, m, d] = bs.split('-').map((p) => Number(p) || 0);

        return { y, m, d };
    });

    let year = $state(0);
    let month = $state(0);
    let day = $state(0);

    $effect.pre(() => {
        year = parts.y;
        month = parts.m;
        day = parts.d;
    });

    const days = $derived(year && month ? bsMonthLength(year, month) : 32);
    const years = Array.from(
        { length: BS_LAST_YEAR - BS_FIRST_YEAR + 1 },
        (_, i) => BS_LAST_YEAR - i,
    );
    const months = $derived(language === 'ne' ? BS_MONTHS_NE : BS_MONTHS);
    const ad = $derived(value?.bs ? bsToAd(value.bs) : null);

    function setBs() {
        if (!year || !month || !day) {
            value =
                year || month || day
                    ? {
                          bs: `${year || '0000'}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
                      }
                    : null;

            return;
        }

        const fit = Math.min(day, bsMonthLength(year, month) || day);
        day = fit;
        value = {
            bs: `${year}-${String(month).padStart(2, '0')}-${String(fit).padStart(2, '0')}`,
        };
    }
</script>

{#if calendar === 'ad'}
    <input
        {id}
        type="date"
        class="input w-auto"
        {disabled}
        aria-invalid={invalid}
        value={value?.ad ?? ''}
        onchange={(e) =>
            (value = e.currentTarget.value
                ? { ad: e.currentTarget.value }
                : null)}
    />
{:else}
    <div class="flex flex-wrap items-center gap-2">
        <label for="{id}-y" class="sr-only">Year (BS)</label>
        <select
            id="{id}-y"
            class="input w-auto"
            {disabled}
            aria-invalid={invalid}
            bind:value={year}
            onchange={setBs}
        >
            <option value={0}>Year</option>
            {#each years as y (y)}<option value={y}>{y}</option>{/each}
        </select>
        <label for="{id}-m" class="sr-only">Month</label>
        <select
            id="{id}-m"
            class="input w-auto"
            {disabled}
            aria-invalid={invalid}
            bind:value={month}
            onchange={setBs}
        >
            <option value={0}>Month</option>
            {#each months as name, i (i)}<option value={i + 1}>{name}</option
                >{/each}
        </select>
        <label for="{id}-d" class="sr-only">Day</label>
        <select
            id="{id}-d"
            class="input w-auto"
            {disabled}
            aria-invalid={invalid}
            bind:value={day}
            onchange={setBs}
        >
            <option value={0}>Day</option>
            {#each Array.from({ length: days }, (_, i) => i + 1) as d (d)}<option
                    value={d}>{d}</option
                >{/each}
        </select>
        <span class="text-xs text-fg-muted">BS{ad ? ` · ${ad} AD` : ''}</span>
    </div>
{/if}
