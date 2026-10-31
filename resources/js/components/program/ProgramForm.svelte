<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import type {
        OrganizationOption,
        PersonRef,
        Program,
    } from '../../lib/types';
    import AssigneePicker from '../AssigneePicker.svelte';

    /**
     * Create a program (no `program`) or edit one. A super-admin creating one
     * picks its organization first; the manager comes from it.
     */
    let {
        program = null,
        managerCandidates,
        isSuperAdmin = false,
        organizations = [],
        onsaved,
        oncancel,
    }: {
        program?: Program | null;
        /** Active people who may manage it (with `organization_id` when creating as a super-admin). */
        /** With their organization where a super-admin chooses it first. */
        managerCandidates: (PersonRef & { organization_id?: number })[];
        isSuperAdmin?: boolean;
        organizations?: OrganizationOption[];
        onsaved: () => void;
        /** Shows a Cancel button; leave it out when the page has its own. */
        oncancel?: () => void;
    } = $props();

    const uid = $props.id();

    // Seeded once: the parent renders this behind an {#if}, so re-opening
    // remounts it with fresh values.
    function seed() {
        return {
            name: program?.name ?? '',
            description: program?.description ?? '',
            manager_id: null as number | null,
            // With a single organization there is nothing to choose.
            organization_id:
                organizations.length === 1
                    ? organizations[0].id
                    : (null as number | null),
        };
    }

    function seedManager(): number[] {
        return program?.manager ? [program.manager.id] : [];
    }

    const form = useForm(seed());
    /** The picked manager: the searchable picker works on a list of ids. */
    let managerIds = $state<number[]>(seedManager());

    const choosesOrganization = $derived(isSuperAdmin && !program);

    const managers = $derived(
        choosesOrganization
            ? managerCandidates.filter(
                  (m) => m.organization_id === form.organization_id,
              )
            : managerCandidates,
    );

    function submit(e: SubmitEvent) {
        e.preventDefault();

        if (program) {
            form.transform((data) => ({
                name: data.name,
                description: data.description,
                manager_id: managerIds[0] ?? null,
            })).patch(`/workspace/programs/${program.slug}`, {
                preserveScroll: true,
                onSuccess: () => onsaved(),
            });

            return;
        }

        form.transform((data) => ({
            ...data,
            manager_id: managerIds[0] ?? null,
        })).post('/workspace/programs', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                managerIds = [];
                onsaved();
            },
        });
    }
</script>

<form onsubmit={submit} class="max-w-2xl">
    <h2 class="mb-4 text-[15px] font-semibold text-fg">
        {program ? 'Edit program' : 'New program'}
    </h2>
    <div>
        <label for={`${uid}-name`} class="label mb-1 block">Name</label>
        <input
            id={`${uid}-name`}
            type="text"
            bind:value={form.name}
            required
            class="input"
        />
        {#if form.errors.name}<p class="mt-1 text-xs text-danger">
                {form.errors.name}
            </p>{/if}
    </div>
    <div class="mt-3">
        <label for={`${uid}-description`} class="label mb-1 block"
            >Description</label
        >
        <textarea
            id={`${uid}-description`}
            bind:value={form.description}
            rows="2"
            class="input"
        ></textarea>
        {#if form.errors.description}<p class="mt-1 text-xs text-danger">
                {form.errors.description}
            </p>{/if}
    </div>
    {#if choosesOrganization}
        <div class="mt-3">
            <label for={`${uid}-organization`} class="label mb-1 block"
                >Organization</label
            >
            <select
                id={`${uid}-organization`}
                class="input"
                required
                value={form.organization_id ?? ''}
                onchange={(e) => {
                    form.organization_id = e.currentTarget.value
                        ? Number(e.currentTarget.value)
                        : null;
                    managerIds = [];
                }}
            >
                <option value="" disabled>Choose an organization</option>
                {#each organizations as organization (organization.id)}
                    <option value={organization.id}>{organization.name}</option>
                {/each}
            </select>
            {#if form.errors.organization_id}<p
                    class="mt-1 text-xs text-danger"
                >
                    {form.errors.organization_id}
                </p>{/if}
        </div>
    {/if}
    <div class="mt-3">
        <span class="label mb-1 block">Program manager</span>
        {#if choosesOrganization && form.organization_id === null}
            <p class="text-xs text-fg-muted">Choose an organization first.</p>
        {:else}
            <AssigneePicker
                team={managers}
                bind:selectedIds={managerIds}
                placeholder="Search or choose who manages it"
            />
        {/if}
        <p class="mt-1 text-xs text-fg-muted">
            In a ministry, usually a Joint Secretary.
        </p>
        <p class="mt-1 text-xs text-fg-muted">
            They run every project in the program as its manager would.
        </p>
        {#if form.errors.manager_id}<p class="mt-1 text-xs text-danger">
                {form.errors.manager_id}
            </p>{/if}
    </div>

    <div class="mt-4 flex items-center justify-end gap-1.5">
        {#if oncancel}
            <button type="button" class="btn-ghost" onclick={oncancel}
                >Cancel</button
            >
        {/if}
        <button type="submit" disabled={form.processing} class="btn-primary"
            >{program ? 'Save' : 'Create'}</button
        >
    </div>
</form>
