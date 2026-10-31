<script lang="ts">
    import { weekRanges } from '../../lib/work';
    import type { WorkRangeFilters } from '../../lib/work';
    import PillGroup from '../PillGroup.svelte';

    /** This week, last week, or any range of days. */
    let {
        range,
        onchange,
    }: {
        range: WorkRangeFilters;
        onchange: (range: WorkRangeFilters) => void;
    } = $props();

    const PRESETS = [
        { value: 'this_week', label: 'This week' },
        { value: 'last_week', label: 'Last week' },
    ];

    const weeks = weekRanges();

    const preset = $derived(
        PRESETS.find(
            (p) =>
                weeks[p.value as keyof typeof weeks].from === range.from &&
                weeks[p.value as keyof typeof weeks].to === range.to,
        )?.value ?? '',
    );

    function applyPreset(value: string) {
        onchange(weeks[value as keyof typeof weeks]);
    }

    function setDate(field: 'from' | 'to', value: string) {
        if (value !== '' && value !== range[field]) {
            onchange({ ...range, [field]: value });
        }
    }
</script>

<PillGroup options={PRESETS} bind:value={() => preset, applyPreset} />
<div class="flex items-center gap-1.5">
    <input
        type="date"
        class="input h-7 w-auto py-0"
        aria-label="From"
        value={range.from}
        max={range.to}
        onchange={(e) => setDate('from', e.currentTarget.value)}
    />
    <span class="text-xs text-fg-faint">to</span>
    <input
        type="date"
        class="input h-7 w-auto py-0"
        aria-label="To"
        value={range.to}
        min={range.from}
        onchange={(e) => setDate('to', e.currentTarget.value)}
    />
</div>
