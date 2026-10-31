<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import type { OrganizationOption, Program } from '../../lib/types';
    import AssigneePicker from '../AssigneePicker.svelte';

    interface ManagerCandidate {
        id: number;
        name: string;
        email?: string | null;
        designation?: string | null;
        organization_id?: number | null;
    }

    /**
     * The New project form: on the Projects page (programs to choose from)
     * and on a program's page (`program` fixed, so there is nothing to choose
     * but the manager).
     */
    let {
        managerCandidates = [],
        programRequired = false,
        defaultManagerId = null,
        isSuperAdmin = false,
        canPublish = false,
        organizations = [],
        programs = [],
        program = null,
        onsaved,
    }: {
        /** Who may manage a new project: active people of its organization. */
        managerCandidates?: ManagerCandidate[];
        /** A program manager creates only in their programs: one is required. */
        programRequired?: boolean;
        /** Who the manager picker starts on: a program manager themselves. */
        defaultManagerId?: number | null;
        isSuperAdmin?: boolean;
        canPublish?: boolean;
        organizations?: OrganizationOption[];
        /** The programs the viewer may open. */
        programs?: Program[];
        /** Fixes the program (and so the organization) of the new project. */
        program?: Program | null;
        onsaved: () => void;
    } = $props();

    const uid = $props.id();

    // A new project may join a program the viewer may create in (all of an
    // admin's; a program manager's own).
    function creatablePrograms() {
        return programs.filter((p) => p.can_create_project);
    }

    // Seeded once: the parent renders this behind an {#if}, so re-opening
    // remounts it with fresh values.
    function seed() {
        const own = creatablePrograms();

        return {
            title: '',
            title_np: '',
            description: '',
            is_public: false,
            manager_id: null as number | null,
            // With a single program to choose from there is nothing to choose.
            program_id:
                program?.id ??
                (programRequired && own.length === 1
                    ? own[0].id
                    : (null as number | null)),
            // A fixed program fixes its organization; with a single
            // organization there is nothing to choose either.
            organization_id:
                program?.organization.id ??
                (organizations.length === 1
                    ? organizations[0].id
                    : (null as number | null)),
        };
    }

    const form = useForm(seed());

    // Seeded once too. Not read again after a create: that lands on the new
    // project, so the page that passed it (and its derived) is gone by then.
    const seededManagerIds = untrack((): number[] =>
        defaultManagerId === null ? [] : [defaultManagerId],
    );

    /** The picked manager: the searchable picker works on a list of ids. */
    let managerIds = $state<number[]>([...seededManagerIds]);

    // A super-admin picks the organization first; the manager comes from it.
    const formManagers = $derived(
        isSuperAdmin && !program
            ? managerCandidates.filter(
                  (m) => m.organization_id === form.organization_id,
              )
            : managerCandidates,
    );

    // Those of the organization it lands in.
    const formPrograms = $derived(
        isSuperAdmin
            ? creatablePrograms().filter(
                  (p) => p.organization.id === form.organization_id,
              )
            : creatablePrograms(),
    );

    // The errors of fields shown above sit under them; any other field's
    // (the program page hides the organization and the program) shows at
    // the bottom, so a refused create always says why.
    const errorsBelow = $derived(
        Object.entries(form.errors).flatMap(([field, message]) => {
            const shownAbove =
                field === 'manager_id' ||
                (field === 'organization_id' && isSuperAdmin && !program) ||
                (field === 'program_id' && !program && formPrograms.length > 0);

            return message && !shownAbove ? [{ field, message }] : [];
        }),
    );

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            manager_id: managerIds[0] ?? null,
        })).post('/workspace/projects', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                managerIds = [...seededManagerIds];
                onsaved();
            },
        });
    }
</script>

<form onsubmit={submit}>
    <h2 class="mb-4 text-[15px] font-semibold text-fg">New project</h2>
    {#if program}
        <p class="-mt-2 mb-4 text-xs text-fg-muted">
            In the {program.name} program.
        </p>
    {/if}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label for={`${uid}-title`} class="label mb-1 block">Title</label>
            <input
                id={`${uid}-title`}
                type="text"
                bind:value={form.title}
                required
                class="input"
            />
        </div>
        <div>
            <label for={`${uid}-title-np`} class="label mb-1 block"
                >Title (Nepali)</label
            >
            <input
                id={`${uid}-title-np`}
                type="text"
                bind:value={form.title_np}
                class="input font-np"
            />
        </div>
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
    </div>
    {#if isSuperAdmin && !program}
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
                    form.program_id = null;
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
        <span class="label mb-1 block">Project manager</span>
        {#if isSuperAdmin && !program && form.organization_id === null}
            <p class="text-xs text-fg-muted">Choose an organization first.</p>
        {:else}
            <AssigneePicker
                team={formManagers}
                bind:selectedIds={managerIds}
                placeholder="Search or choose who manages it"
            />
        {/if}
        <p class="mt-1 text-xs text-fg-muted">
            In a ministry, usually an Under Secretary.
        </p>
        <p class="mt-1 text-xs text-fg-muted">
            The project gets its own team, led by its manager, who then adds the
            people.
        </p>
        {#if form.errors.manager_id}<p class="mt-1 text-xs text-danger">
                {form.errors.manager_id}
            </p>{/if}
    </div>
    {#if !program && formPrograms.length > 0}
        <div class="mt-3">
            <label for={`${uid}-program`} class="label mb-1 block"
                >{programRequired ? 'Program' : 'Program (optional)'}</label
            >
            <select
                id={`${uid}-program`}
                class="input"
                required={programRequired}
                value={form.program_id ?? ''}
                onchange={(e) =>
                    (form.program_id = e.currentTarget.value
                        ? Number(e.currentTarget.value)
                        : null)}
            >
                {#if programRequired}
                    <option value="" disabled
                        >Choose one of your programs</option
                    >
                {:else}
                    <option value="">No program</option>
                {/if}
                {#each formPrograms as program (program.id)}
                    <option value={program.id}>{program.name}</option>
                {/each}
            </select>
            {#if form.errors.program_id}<p class="mt-1 text-xs text-danger">
                    {form.errors.program_id}
                </p>{/if}
        </div>
    {/if}

    {#if canPublish && !program}
        <label class="mt-4 flex items-center gap-2 text-fg-muted">
            <input type="checkbox" bind:checked={form.is_public} /> Public (everyone
            in its organization can see it)
        </label>
    {/if}

    <div class="mt-4 flex items-center gap-2">
        <div class="flex-1"></div>
        <button type="submit" disabled={form.processing} class="btn-primary"
            >Create</button
        >
    </div>
    {#each errorsBelow as error (error.field)}
        <p class="mt-2 text-xs text-danger">{error.message}</p>
    {/each}
</form>
