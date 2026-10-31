<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { LocateFixed, Plus, TriangleAlert, X } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import { formatDistance, WEEKDAYS } from '../../lib/attendance';
    import type {
        AttendanceSettings,
        Holiday,
        OfficeNetwork,
        OfficePlace,
        Season,
    } from '../../lib/attendance';

    /**
     * One organization's attendance: the switch (super-admins), working
     * days and hours with seasons, office places, office networks and
     * holidays. A super-admin switches organization from the top bar.
     */
    let {
        organization,
        settings,
        places,
        networks,
        holidays,
        canEnable,
        organizations,
        yourAddress,
    }: {
        organization: { id: number; name: string };
        settings: AttendanceSettings;
        places: OfficePlace[];
        networks: OfficeNetwork[];
        holidays: Holiday[];
        canEnable: boolean;
        organizations: { id: number; name: string }[];
        yourAddress: string | null;
    } = $props();

    const uid = $props.id();
    const base = $derived(
        `/workspace/organizations/${organization.id}/attendance`,
    );

    // ---- The switch ----------------------------------------------------

    function setEnabled(enabled: boolean) {
        if (
            !enabled &&
            !confirm(
                `Turn off attendance for ${organization.name}? Nobody can clock in until it is on again. Punches are kept.`,
            )
        ) {
            return;
        }

        router.put(base, { enabled }, { preserveScroll: true });
    }

    function switchOrganization(e: Event) {
        router.get(
            '/workspace/attendance/settings',
            { organization: (e.currentTarget as HTMLSelectElement).value },
            { preserveScroll: false },
        );
    }

    // ---- Hours ---------------------------------------------------------

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

    // ---- Office places -------------------------------------------------

    let placeId = $state<number | null>(null);
    let placeOpen = $state(false);
    let locating = $state(false);
    let locateError = $state<string | null>(null);
    const placeForm = useForm({
        name: '',
        latitude: '' as string | number,
        longitude: '' as string | number,
        radius_m: 150,
    });

    function startPlace(place: OfficePlace | null) {
        placeId = place?.id ?? null;
        placeOpen = true;
        locateError = null;
        placeForm.clearErrors();
        placeForm.name = place?.name ?? '';
        placeForm.latitude = place?.latitude ?? '';
        placeForm.longitude = place?.longitude ?? '';
        placeForm.radius_m = place?.radius_m ?? 150;
    }

    function useMyLocation() {
        if (!navigator.geolocation) {
            locateError = "This browser can't share its location.";

            return;
        }

        locating = true;
        locateError = null;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                placeForm.latitude = Number(
                    position.coords.latitude.toFixed(7),
                );
                placeForm.longitude = Number(
                    position.coords.longitude.toFixed(7),
                );
                locating = false;
            },
            () => {
                locateError = "Couldn't get your location. Type it instead.";
                locating = false;
            },
            { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 },
        );
    }

    function savePlace(e: SubmitEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => (placeOpen = false),
        };

        if (placeId === null) {
            placeForm.post(`${base}/places`, options);
        } else {
            placeForm.patch(`${base}/places/${placeId}`, options);
        }
    }

    function removePlace(place: OfficePlace) {
        if (confirm(`Remove ${place.name}?`)) {
            router.delete(`${base}/places/${place.id}`, {
                preserveScroll: true,
            });
        }
    }

    // ---- Office networks -----------------------------------------------

    let networkId = $state<number | null>(null);
    let networkOpen = $state(false);
    const networkForm = useForm({ label: '', cidr: '' });

    function startNetwork(network: OfficeNetwork | null) {
        networkId = network?.id ?? null;
        networkOpen = true;
        networkForm.clearErrors();
        networkForm.label = network?.label ?? '';
        networkForm.cidr = network?.cidr ?? '';
    }

    function saveNetwork(e: SubmitEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => (networkOpen = false),
        };

        if (networkId === null) {
            networkForm.post(`${base}/networks`, options);
        } else {
            networkForm.patch(`${base}/networks/${networkId}`, options);
        }
    }

    function removeNetwork(network: OfficeNetwork) {
        if (confirm(`Remove ${network.label} (${network.cidr})?`)) {
            router.delete(`${base}/networks/${network.id}`, {
                preserveScroll: true,
            });
        }
    }

    // ---- Holidays ------------------------------------------------------

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

    function longDate(value: string): string {
        return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString(
            undefined,
            {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            },
        );
    }
</script>

<svelte:head><title>Attendance settings · Workspace</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="hidden text-fg-muted sm:inline">Attendance</span>
            <span class="hidden text-fg-faint sm:inline">/</span>
            <span class="truncate font-medium">Settings</span>
        </div>
        {#if organizations.length > 0}
            <select
                aria-label="Organization"
                class="input h-7 w-auto max-w-44 py-0"
                value={organization.id}
                onchange={switchOrganization}
            >
                {#each organizations as option (option.id)}
                    <option value={option.id}>{option.name}</option>
                {/each}
            </select>
        {/if}
    {/snippet}

    <div class="max-w-3xl space-y-10">
        <!-- The switch -->
        <section class="space-y-2">
            <h2 class="section-title">Attendance for {organization.name}</h2>
            <div class="panel flex flex-wrap items-center gap-3 p-4">
                <p class="min-w-0 flex-1 text-[13px] text-fg-muted">
                    {#if settings.enabled}
                        <span class="font-medium text-fg">On.</span>
                        Everyone active here clocks in from the top bar.
                        {#if settings.enabled_at}
                            Turned on {longDate(
                                settings.enabled_at,
                            )}{settings.enabled_by
                                ? ` by ${settings.enabled_by}`
                                : ''}.
                        {/if}
                    {:else}
                        <span class="font-medium text-fg">Off.</span>
                        Nobody clocks in yet.
                        {#if !canEnable}A super-admin turns it on; the hours
                            below are ready for then.{/if}
                    {/if}
                </p>
                {#if canEnable}
                    {#if settings.enabled}
                        <button
                            type="button"
                            class="btn-danger"
                            onclick={() => setEnabled(false)}>Turn off</button
                        >
                    {:else}
                        <button
                            type="button"
                            class="btn-primary"
                            onclick={() => setEnabled(true)}>Turn on</button
                        >
                    {/if}
                {/if}
            </div>
        </section>

        <!-- Hours -->
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
                        Other hours between two dates each year, like winter
                        hours from 11-02 to 01-29. Dates are month-day.
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
                                class="btn-icon"
                                aria-label="Remove season"
                                onclick={() => removeSeason(i)}
                                ><X class="h-3.5 w-3.5" /></button
                            >
                            {#each ['name', 'from', 'to', 'start', 'end'] as field (field)}
                                {#if hoursErrors[`seasons.${i}.${field}`]}
                                    <p
                                        class="col-span-full text-xs text-danger"
                                    >
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

        <!-- Office places -->
        <section class="space-y-2">
            <div class="flex items-center gap-2">
                <h2 class="section-title">
                    Office places <span class="section-count"
                        >{places.length}</span
                    >
                </h2>
                <button
                    type="button"
                    class="btn ml-auto"
                    onclick={() => startPlace(null)}
                    ><Plus class="h-3.5 w-3.5" />Add place</button
                >
            </div>
            <p class="text-xs text-fg-muted">
                A press within a place's radius counts as near the office.
            </p>
            {#if placeOpen}
                <form onsubmit={savePlace} class="panel space-y-3 p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <label class="label" for="{uid}-place-name"
                                >Name</label
                            >
                            <input
                                id="{uid}-place-name"
                                class="input"
                                placeholder="Head office"
                                bind:value={placeForm.name}
                                required
                            />
                            {#if placeForm.errors.name}
                                <p class="text-xs text-danger">
                                    {placeForm.errors.name}
                                </p>
                            {/if}
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="label" for="{uid}-place-lat"
                                >Latitude</label
                            >
                            <input
                                id="{uid}-place-lat"
                                type="number"
                                step="any"
                                class="input font-mono"
                                bind:value={placeForm.latitude}
                                required
                            />
                            {#if placeForm.errors.latitude}
                                <p class="text-xs text-danger">
                                    {placeForm.errors.latitude}
                                </p>
                            {/if}
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="label" for="{uid}-place-lng"
                                >Longitude</label
                            >
                            <input
                                id="{uid}-place-lng"
                                type="number"
                                step="any"
                                class="input font-mono"
                                bind:value={placeForm.longitude}
                                required
                            />
                            {#if placeForm.errors.longitude}
                                <p class="text-xs text-danger">
                                    {placeForm.errors.longitude}
                                </p>
                            {/if}
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="label" for="{uid}-place-radius"
                                >Radius (metres)</label
                            >
                            <input
                                id="{uid}-place-radius"
                                type="number"
                                min="20"
                                max="5000"
                                class="input"
                                bind:value={placeForm.radius_m}
                                required
                            />
                            {#if placeForm.errors.radius_m}
                                <p class="text-xs text-danger">
                                    {placeForm.errors.radius_m}
                                </p>
                            {/if}
                        </div>
                        <div class="flex flex-col justify-end gap-1">
                            <button
                                type="button"
                                class="btn justify-center"
                                disabled={locating}
                                onclick={useMyLocation}
                                ><LocateFixed class="h-3.5 w-3.5" />{locating
                                    ? 'Finding you…'
                                    : 'Use my location'}</button
                            >
                        </div>
                    </div>
                    {#if locateError}
                        <p class="text-xs text-danger">{locateError}</p>
                    {:else}
                        <p class="text-xs text-fg-faint">
                            Stand in the office when you use your location.
                        </p>
                    {/if}
                    <div class="flex justify-end gap-1.5">
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => (placeOpen = false)}>Cancel</button
                        >
                        <button
                            type="submit"
                            class="btn-primary"
                            disabled={placeForm.processing}
                            >{placeId === null ? 'Add place' : 'Save'}</button
                        >
                    </div>
                </form>
            {/if}
            <div class="border-t border-line">
                {#each places as place (place.id)}
                    <div class="row min-h-11 px-2 lg:px-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{place.name}</div>
                            <div class="font-mono text-xs text-fg-muted">
                                {place.latitude}, {place.longitude} · {formatDistance(
                                    place.radius_m,
                                )}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => startPlace(place)}>Edit</button
                        >
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => removePlace(place)}>Remove</button
                        >
                    </div>
                {:else}
                    <p class="py-3 text-xs text-fg-muted">
                        No places yet. Without one, a press off the office
                        network asks for a note.
                    </p>
                {/each}
            </div>
        </section>

        <!-- Office networks -->
        <section class="space-y-2">
            <div class="flex items-center gap-2">
                <h2 class="section-title">
                    Office networks <span class="section-count"
                        >{networks.length}</span
                    >
                </h2>
                <button
                    type="button"
                    class="btn ml-auto"
                    onclick={() => startNetwork(null)}
                    ><Plus class="h-3.5 w-3.5" />Add network</button
                >
            </div>
            <p class="text-xs text-fg-muted">
                A press from an address in these ranges counts as the office
                network. Your address now:
                <span class="font-mono text-fg">{yourAddress ?? 'unknown'}</span
                >.
            </p>
            <p class="flex items-start gap-1.5 text-xs text-fg-muted">
                <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0 text-warn" />
                <span
                    >Add only the office's own internet. Never add the VPN's
                    range: anyone on the VPN, anywhere, would count as in the
                    office.</span
                >
            </p>
            {#if networkOpen}
                <form onsubmit={saveNetwork} class="panel space-y-3 p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1">
                            <label class="label" for="{uid}-net-label"
                                >Label</label
                            >
                            <input
                                id="{uid}-net-label"
                                class="input"
                                placeholder="Office Wi-Fi"
                                bind:value={networkForm.label}
                                required
                            />
                            {#if networkForm.errors.label}
                                <p class="text-xs text-danger">
                                    {networkForm.errors.label}
                                </p>
                            {/if}
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="label" for="{uid}-net-cidr"
                                >Address or range</label
                            >
                            <input
                                id="{uid}-net-cidr"
                                class="input font-mono"
                                placeholder="203.0.113.0/24"
                                bind:value={networkForm.cidr}
                                required
                            />
                            {#if networkForm.errors.cidr}
                                <p class="text-xs text-danger">
                                    {networkForm.errors.cidr}
                                </p>
                            {/if}
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-end gap-1.5">
                        {#if yourAddress}
                            <button
                                type="button"
                                class="btn-ghost mr-auto"
                                onclick={() =>
                                    (networkForm.cidr = yourAddress ?? '')}
                                >Use my address</button
                            >
                        {/if}
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => (networkOpen = false)}>Cancel</button
                        >
                        <button
                            type="submit"
                            class="btn-primary"
                            disabled={networkForm.processing}
                            >{networkId === null
                                ? 'Add network'
                                : 'Save'}</button
                        >
                    </div>
                </form>
            {/if}
            <div class="border-t border-line">
                {#each networks as network (network.id)}
                    <div class="row min-h-11 px-2 lg:px-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">
                                {network.label}
                            </div>
                            <div class="font-mono text-xs text-fg-muted">
                                {network.cidr}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => startNetwork(network)}>Edit</button
                        >
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => removeNetwork(network)}
                            >Remove</button
                        >
                    </div>
                {:else}
                    <p class="py-3 text-xs text-fg-muted">
                        No networks yet. Presses are judged by location only.
                    </p>
                {/each}
            </div>
        </section>

        <!-- Holidays -->
        <section class="space-y-2">
            <div class="flex items-center gap-2">
                <h2 class="section-title">
                    Holidays <span class="section-count">{holidays.length}</span
                    >
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
                            >{longDate(holiday.date)}</span
                        >
                        <span class="min-w-0 flex-1 truncate"
                            >{holiday.name}</span
                        >
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => startHoliday(holiday)}>Edit</button
                        >
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => removeHoliday(holiday)}
                            >Remove</button
                        >
                    </div>
                {:else}
                    <p class="py-3 text-xs text-fg-muted">No holidays yet.</p>
                {/each}
            </div>
        </section>
    </div>
</AppShell>
