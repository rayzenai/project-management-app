<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { LocateFixed, Plus } from '@lucide/svelte';
    import type { OfficePlace } from '../../../lib/attendance';
    import { formatDistance } from '../../../lib/format';

    /**
     * The office places a punch is judged "near": add one where you stand
     * ("Use my location"), change or remove one.
     */
    let {
        base,
        places,
    }: {
        /** The organization's attendance URL (the page's `base`). */
        base: string;
        places: OfficePlace[];
    } = $props();

    const uid = $props.id();

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
</script>

<section class="space-y-2">
    <div class="flex items-center gap-2">
        <h2 class="section-title">
            Office places <span class="section-count">{places.length}</span>
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
                    <label class="label" for="{uid}-place-name">Name</label>
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
                    <label class="label" for="{uid}-place-lat">Latitude</label>
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
                    <label class="label" for="{uid}-place-lng">Longitude</label>
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
                No places yet. Without one, a press off the office network asks
                for a note.
            </p>
        {/each}
    </div>
</section>
