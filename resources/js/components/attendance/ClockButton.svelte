<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        ChevronDown,
        Coffee,
        LogIn,
        LogOut,
        MapPin,
        MapPinOff,
        Wifi,
    } from '@lucide/svelte';
    import {
        ACTION_LABELS,
        clockTime,
        describePunch,
        stateLabel,
    } from '../../lib/attendance';
    import type { MyAttendance, PunchKind } from '../../lib/attendance';
    import Spinner from '../Spinner.svelte';

    /**
     * The top bar's clock, for everyone who can punch (the shared
     * `attendance` prop is null otherwise). Before clocking in it is one
     * button; afterwards it says where the day stands and opens to the
     * other allowed steps and where the last punch came from. Each press
     * asks the browser for one location reading (nothing in between) and
     * punches without one when it is refused or takes too long. When the
     * server asks why the press is away from the office, the popover asks
     * for a short note and resends the same press.
     */
    type Reading = { latitude: number; longitude: number; accuracy_m: number };

    const day = $derived(
        ((page.props as Record<string, unknown>).attendance ??
            null) as MyAttendance | null,
    );
    const last = $derived(day?.punches.at(-1) ?? null);

    const icons = {
        in: LogIn,
        lunch_start: Coffee,
        lunch_end: Coffee,
        out: LogOut,
    };
    const placeIcons = {
        office_network: Wifi,
        near_office: MapPin,
        outside: MapPin,
        unknown: MapPinOff,
    };

    let wrapper = $state<HTMLElement | null>(null);
    let open = $state(false);
    let busy = $state<PunchKind | null>(null);
    // A press the server wants a note for, with the reading taken then.
    let pending = $state<{ kind: PunchKind; reading: Reading | null } | null>(
        null,
    );
    let note = $state('');
    let noteError = $state<string | null>(null);

    const hours = $derived.by(() => {
        if (!day) {
            return '';
        }

        if (day.today.holiday) {
            return `Holiday: ${day.today.holiday}`;
        }

        return day.today.is_workday
            ? `Hours today ${day.today.start} to ${day.today.end}`
            : 'Not a working day';
    });

    function locate(): Promise<Reading | null> {
        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            return Promise.resolve(null);
        }

        return new Promise((resolve) =>
            navigator.geolocation.getCurrentPosition(
                (position) =>
                    resolve({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy_m: position.coords.accuracy,
                    }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 },
            ),
        );
    }

    async function punch(kind: PunchKind): Promise<void> {
        if (busy) {
            return;
        }

        busy = kind;
        const resend = pending?.kind === kind ? pending : null;
        const reading = resend ? resend.reading : await locate();

        router.post(
            '/workspace/attendance/punches',
            {
                kind,
                ...(reading ?? {}),
                note: resend ? note : null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['attendance', 'flash'],
                onSuccess: () => {
                    pending = null;
                    note = '';
                    noteError = null;
                    open = false;
                },
                onError: (errors) => {
                    if (errors.note) {
                        noteError = resend ? errors.note : null;
                        pending = { kind, reading };
                        open = true;
                    } else {
                        pending = null;
                    }
                },
                onFinish: () => {
                    busy = null;
                },
            },
        );
    }

    function press(): void {
        if (day?.state === 'not_in' && !pending) {
            void punch('in');

            return;
        }

        open = !open;
    }

    function cancelNote(): void {
        pending = null;
        note = '';
        noteError = null;
        open = false;
    }

    function sendNote(e: SubmitEvent): void {
        e.preventDefault();

        if (pending) {
            void punch(pending.kind);
        }
    }

    $effect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!wrapper?.contains(event.target as Node)) {
                open = false;
            }
        };
        const onKeydown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                open = false;
            }
        };

        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('keydown', onKeydown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            document.removeEventListener('keydown', onKeydown);
        };
    });
</script>

{#if day}
    {@const StateIcon = last ? icons[last.kind] : LogIn}
    <span bind:this={wrapper} class="relative inline-flex shrink-0">
        <button
            type="button"
            class={day.state === 'not_in' ? 'btn-primary' : 'btn'}
            aria-haspopup="dialog"
            aria-expanded={open}
            disabled={busy !== null && !open}
            onclick={press}
        >
            {#if busy && !open}
                <Spinner size={14} class="text-current" label="Clocking" />
            {:else}
                <StateIcon
                    class={`h-3.5 w-3.5 ${day.state === 'in' || day.state === 'at_lunch' ? 'text-success' : ''}`}
                />
            {/if}
            {#if day.state === 'not_in'}
                {ACTION_LABELS.in}
            {:else}
                <span class="hidden sm:inline">{stateLabel(day)}</span>
                <span class="font-mono tabular-nums sm:hidden"
                    >{last ? clockTime(last.at) : ''}</span
                >
                <ChevronDown class="h-3.5 w-3.5 text-fg-faint" />
            {/if}
        </button>

        {#if open}
            <div
                class="popover absolute top-full right-0 z-50 mt-1.5 w-72 max-w-[calc(100vw-2rem)] p-1"
                role="dialog"
                aria-label="Clock"
            >
                {#if pending}
                    <form onsubmit={sendNote} class="space-y-2 p-2">
                        <label class="label" for="clock-note"
                            >Why are you away from the office?</label
                        >
                        <input
                            id="clock-note"
                            class="input"
                            maxlength="500"
                            placeholder="Meeting, field visit"
                            bind:value={note}
                        />
                        {#if noteError}
                            <p class="text-xs text-danger">{noteError}</p>
                        {/if}
                        <p class="text-xs text-fg-muted">
                            You're not on the office network or near the office.
                            Your higher authority will see this.
                        </p>
                        <div class="flex justify-end gap-1.5">
                            <button
                                type="button"
                                class="btn-ghost"
                                onclick={cancelNote}>Cancel</button
                            >
                            <button
                                type="submit"
                                class="btn-primary"
                                disabled={busy !== null || !note.trim()}
                            >
                                {#if busy}<Spinner
                                        size={14}
                                        class="text-current"
                                        label="Clocking"
                                    />{/if}
                                {ACTION_LABELS[pending.kind]} from here
                            </button>
                        </div>
                    </form>
                {:else}
                    {#each day.next as kind (kind)}
                        {@const Icon = icons[kind]}
                        <button
                            type="button"
                            class="menu-item"
                            disabled={busy !== null}
                            onclick={() => punch(kind)}
                        >
                            {#if busy === kind}
                                <Spinner size={15} label="Clocking" />
                            {:else}
                                <Icon class="h-[15px] w-[15px] text-fg-faint" />
                            {/if}
                            {ACTION_LABELS[kind]}
                        </button>
                    {/each}
                    {#if day.next.length > 0}
                        <div class="my-1 border-t border-line"></div>
                    {/if}
                    {#if last}
                        {@const PlaceIcon = placeIcons[last.place]}
                        <div
                            class="flex items-start gap-2 px-2 py-1.5 text-xs text-fg-muted"
                        >
                            <PlaceIcon
                                class="mt-px h-3.5 w-3.5 shrink-0 text-fg-faint"
                            />
                            <span>
                                {describePunch(last)}
                                {#if last.note}
                                    <span class="block text-fg-faint"
                                        >Note: {last.note}</span
                                    >
                                {/if}
                            </span>
                        </div>
                    {/if}
                    <p class="px-2 py-1.5 text-xs text-fg-faint">
                        {hours}. Your network and location are kept only when
                        you press, never in between.
                    </p>
                {/if}
            </div>
        {/if}
    </span>
{/if}
