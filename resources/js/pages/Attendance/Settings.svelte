<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AttendanceSwitch from '../../components/attendance/settings/AttendanceSwitch.svelte';
    import HolidaysPanel from '../../components/attendance/settings/HolidaysPanel.svelte';
    import HoursForm from '../../components/attendance/settings/HoursForm.svelte';
    import NetworksPanel from '../../components/attendance/settings/NetworksPanel.svelte';
    import PlacesPanel from '../../components/attendance/settings/PlacesPanel.svelte';
    import OrganizationSelect from '../../components/filters/OrganizationSelect.svelte';
    import type {
        AttendanceSettings,
        Holiday,
        OfficeNetwork,
        OfficePlace,
    } from '../../lib/attendance';
    import type { Id, OrganizationOption } from '../../lib/types';

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
        organizations: OrganizationOption[];
        yourAddress: string | null;
    } = $props();

    const base = $derived(
        `/workspace/organizations/${organization.id}/attendance`,
    );

    function switchOrganization(id: Id) {
        router.get(
            '/workspace/attendance/settings',
            { organization: id },
            { preserveScroll: false },
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
        <OrganizationSelect
            options={organizations}
            value={organization.id}
            onchange={switchOrganization}
        />
    {/snippet}

    <!-- When people work on the left, where and the exceptions on the
         right, side by side once the screen has room for both. -->
    <div
        class="grid max-w-4xl items-start gap-10 2xl:max-w-7xl 2xl:grid-cols-2"
    >
        <div class="min-w-0 space-y-10">
            <AttendanceSwitch {organization} {base} {settings} {canEnable} />
            <HoursForm {base} {settings} />
        </div>
        <div class="min-w-0 space-y-10">
            <PlacesPanel {base} {places} />
            <NetworksPanel {base} {networks} {yourAddress} />
            <HolidaysPanel {base} {holidays} />
        </div>
    </div>
</AppShell>
