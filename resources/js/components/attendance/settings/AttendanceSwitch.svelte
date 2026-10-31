<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import type { AttendanceSettings } from '../../../lib/attendance';
    import { fullDay } from '../../../lib/dates';
    import type { Id } from '../../../lib/types';

    /**
     * The organization's on/off switch: on and off for super-admins, and when
     * and by whom it was last turned on.
     */
    let {
        organization,
        base,
        settings,
        canEnable,
    }: {
        organization: { id: Id; name: string };
        /** The organization's attendance URL (the page's `base`). */
        base: string;
        settings: AttendanceSettings;
        canEnable: boolean;
    } = $props();

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
</script>

<section class="space-y-2">
    <h2 class="section-title">Attendance for {organization.name}</h2>
    <div class="panel flex flex-wrap items-center gap-3 p-4">
        <p class="min-w-0 flex-1 text-[13px] text-fg-muted">
            {#if settings.enabled}
                <span class="font-medium text-fg">On.</span>
                Everyone active here clocks in from the top bar.
                {#if settings.enabled_at}
                    Turned on {fullDay(settings.enabled_at)}{settings.enabled_by
                        ? ` by ${settings.enabled_by}`
                        : ''}.
                {/if}
            {:else}
                <span class="font-medium text-fg">Off.</span>
                Nobody clocks in yet.
                {#if !canEnable}A super-admin turns it on; the hours below are
                    ready for then.{/if}
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
