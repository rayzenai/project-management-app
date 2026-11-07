<script lang="ts">
    import { onMount } from 'svelte';
    import { loadPlaces, under } from '../../lib/forms/places';
    import type { Places } from '../../lib/forms/places';
    import Spinner from '../Spinner.svelte';
    import SearchSelect from './SearchSelect.svelte';

    /**
     * A place in Nepal, down to the question's depth: province, then
     * district, then local level, then ward, each list narrowed by the one
     * before. Changing a level clears those below it.
     */
    type Place = {
        province?: string;
        district?: string;
        local?: string;
        ward?: number;
    };

    let {
        id,
        depth = 'local',
        value = $bindable(),
        language = 'en',
        disabled = false,
        invalid = false,
    }: {
        id: string;
        depth?: 'province' | 'district' | 'local' | 'ward';
        value: Place | null | undefined;
        language?: 'en' | 'ne';
        disabled?: boolean;
        invalid?: boolean;
    } = $props();

    let places = $state<Places | null>(null);
    const levels = ['province', 'district', 'local', 'ward'];
    const reach = $derived(levels.indexOf(depth));

    onMount(async () => {
        places = await loadPlaces();
    });

    const label = (p: { en: string; ne: string }) =>
        language === 'ne' ? `${p.ne} (${p.en})` : `${p.en} (${p.ne})`;
    const asOptions = (list: { id: string; en: string; ne: string }[]) =>
        list.map((p) => ({
            value: p.id,
            label: label(p),
            search: `${p.en} ${p.ne}`,
        }));

    const local = $derived(
        places?.locals.find((l) => l.id === value?.local) ?? null,
    );

    function set(level: keyof Place, choice: string | number | null) {
        const next: Place = { ...(value ?? {}) };
        const at = levels.indexOf(level);

        for (const below of levels.slice(at)) {
            delete next[below as keyof Place];
        }

        if (choice !== null && choice !== '') {
            (next as Record<string, unknown>)[level] = choice;
        }

        value = Object.keys(next).length ? next : null;
    }
</script>

{#if places === null}
    <p class="flex items-center gap-2 text-xs text-fg-muted">
        <Spinner size={12} />Loading places
    </p>
{:else}
    <div class="grid gap-2 sm:grid-cols-2">
        <div>
            <label for="{id}-province" class="label mb-1 block">Province</label>
            <SearchSelect
                id="{id}-province"
                options={asOptions(places.provinces)}
                value={value?.province}
                {disabled}
                {invalid}
                onchange={(v) => set('province', v)}
            />
        </div>
        {#if reach >= 1}
            <div>
                <label for="{id}-district" class="label mb-1 block"
                    >District</label
                >
                <SearchSelect
                    id="{id}-district"
                    options={value?.province
                        ? asOptions(under(places.districts, value.province))
                        : []}
                    value={value?.district}
                    placeholder={value?.province
                        ? 'Choose'
                        : 'Choose the province first'}
                    disabled={disabled || !value?.province}
                    {invalid}
                    onchange={(v) => set('district', v)}
                />
            </div>
        {/if}
        {#if reach >= 2}
            <div>
                <label for="{id}-local" class="label mb-1 block"
                    >Local level</label
                >
                <SearchSelect
                    id="{id}-local"
                    options={value?.district
                        ? asOptions(under(places.locals, value.district))
                        : []}
                    value={value?.local}
                    placeholder={value?.district
                        ? 'Choose'
                        : 'Choose the district first'}
                    disabled={disabled || !value?.district}
                    {invalid}
                    onchange={(v) => set('local', v)}
                />
            </div>
        {/if}
        {#if reach >= 3}
            <div>
                <label for="{id}-ward" class="label mb-1 block">Ward</label>
                <select
                    id="{id}-ward"
                    class="input"
                    disabled={disabled || !local}
                    aria-invalid={invalid}
                    value={value?.ward ?? ''}
                    onchange={(e) =>
                        set(
                            'ward',
                            e.currentTarget.value === ''
                                ? null
                                : Number(e.currentTarget.value),
                        )}
                >
                    <option value=""
                        >{local
                            ? 'Choose'
                            : 'Choose the local level first'}</option
                    >
                    {#each Array.from({ length: local?.wards ?? 0 }, (_, i) => i + 1) as ward (ward)}
                        <option value={ward}>Ward {ward}</option>
                    {/each}
                </select>
            </div>
        {/if}
    </div>
{/if}
