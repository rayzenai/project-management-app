<script lang="ts">
    import { Check, Search } from '@lucide/svelte';
    import { plural } from '../../lib/format';
    import { toggled } from '../../lib/memberRoles';
    import type {
        Id,
        Member,
        RoleManager,
        RoleOption,
        RoleOptions,
    } from '../../lib/types';

    /**
     * The person form's roles: the programs of the person's organization
     * they manage, and its projects, one row each, with whether they manage
     * it and whether they are on its team. A program they manage is handed
     * over by choosing someone else, so it shows ticked and fixed here; a
     * project they stop managing is left with no manager, and keeps them on
     * its team. Long project lists get a search, and the person's own
     * projects come first.
     */
    let {
        options,
        person = null,
        programIds = $bindable(),
        projectIds = $bindable(),
        teamIds = $bindable(),
        errors = {},
    }: {
        /** The person's organization's programs and projects. */
        options: RoleOptions;
        /** The person being edited; null while adding one. */
        person?: Member | null;
        programIds: Id[];
        projectIds: Id[];
        teamIds: Id[];
        errors?: {
            managed_program_ids?: string;
            managed_project_ids?: string;
            team_ids?: string;
        };
    } = $props();

    const heldPrograms = $derived(person?.managed_program_ids ?? []);
    const holdsAProgram = $derived(
        options.programs.some((p) => heldPrograms.includes(p.id)),
    );

    /** A search box once the list is too long to scan. */
    const SEARCH_FROM = 9;

    let query = $state('');

    /**
     * Their projects first, as they stood when the form opened, so a row
     * never jumps while it is being ticked.
     */
    const ordered = $derived.by(() => {
        const managed = new Set(person?.managed_project_ids ?? []);
        const teams = new Set(person?.team_ids ?? []);
        const theirs = (p: RoleOption) =>
            managed.has(p.id) || (p.team_id !== null && teams.has(p.team_id));

        return [
            ...options.projects.filter(theirs),
            ...options.projects.filter((p) => !theirs(p)),
        ];
    });

    const matching = $derived.by(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? ordered
            : ordered.filter((p) =>
                  `${p.title} ${p.program ?? ''}`
                      .toLowerCase()
                      .includes(needle),
              );
    });

    const managesCount = $derived(
        options.projects.filter((p) => projectIds.includes(p.id)).length,
    );
    const onTeamCount = $derived(
        options.projects.filter((p) => isOnTeam(p)).length,
    );

    /** A manager is on their team: that wins. */
    function isOnTeam(project: RoleOption): boolean {
        return (
            projectIds.includes(project.id) ||
            (project.team_id !== null && teamIds.includes(project.team_id))
        );
    }

    function managerNote(manager: RoleManager | null): string | null {
        return manager && manager.id !== person?.id
            ? `Now ${manager.name}`
            : null;
    }
</script>

<!-- One row of a list: its name (with the program, which tells apart the
     programs' General projects, as projectLabel does in text) and who
     manages it now. -->
{#snippet name(label: string, program: string | null, note: string | null)}
    <span class="min-w-0">
        <span class="block truncate text-[13px] text-fg"
            >{label}{#if program}
                <span class="ml-1 text-fg-muted">{program}</span>{/if}</span
        >
        {#if note}
            <span class="block truncate text-[11.5px] text-fg-muted"
                >{note}</span
            >
        {/if}
    </span>
{/snippet}

<div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
    <fieldset class="min-w-0">
        <legend class="label mb-1">Program manager of</legend>
        {#if options.programs.length === 0}
            <!-- Shown anyway, so the role can be found. -->
            <p class="text-xs text-fg-muted">
                No programs yet. Create one from Projects.
            </p>
        {:else}
            <div
                class="max-h-80 overflow-y-auto rounded-md border border-line bg-surface"
            >
                {#each options.programs as program (program.id)}
                    <label
                        class="flex cursor-pointer items-start gap-2.5 border-b border-line-soft px-3 py-2 last:border-b-0 hover:bg-hover"
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 shrink-0"
                            checked={programIds.includes(program.id)}
                            disabled={heldPrograms.includes(program.id)}
                            onchange={(e) =>
                                (programIds = toggled(
                                    programIds,
                                    program.id,
                                    e.currentTarget.checked,
                                ))}
                        />
                        {@render name(
                            program.name,
                            null,
                            managerNote(program.manager),
                        )}
                    </label>
                {/each}
            </div>
            <p class="mt-2 text-xs text-fg-muted">
                In a ministry, usually a Joint Secretary.{holdsAProgram
                    ? ' To hand one over, choose another manager.'
                    : ''}
            </p>
        {/if}
        {#if errors.managed_program_ids}
            <p class="mt-1 text-xs text-danger">
                {errors.managed_program_ids}
            </p>
        {/if}
    </fieldset>
    {#if options.projects.length > 0}
        <fieldset class="min-w-0">
            <legend class="label mb-1">Projects</legend>
            <div class="rounded-md border border-line bg-surface">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2"
                >
                    <span class="text-xs text-fg-muted">
                        Manages {managesCount} · On {plural(
                            onTeamCount,
                            'team',
                        )}
                    </span>
                    {#if options.projects.length >= SEARCH_FROM}
                        <label class="relative w-full sm:w-60">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
                            />
                            <input
                                type="search"
                                class="input h-7 py-0 pl-8"
                                placeholder="Search {options.projects
                                    .length} projects"
                                aria-label="Search projects"
                                bind:value={query}
                                onkeydown={(e) => {
                                    // Enter searches; it never submits the form.
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                    }
                                }}
                            />
                        </label>
                    {/if}
                </div>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem] border-b border-line-soft px-3 py-1.5"
                >
                    <span class="col-head">Project</span>
                    <span class="col-head text-center">Manager</span>
                    <span class="col-head text-center">Member</span>
                </div>
                <div class="max-h-80 overflow-y-auto">
                    {#each matching as project (project.id)}
                        <div
                            class="grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem] items-center border-b border-line-soft px-3 py-2 last:border-b-0 hover:bg-hover"
                        >
                            {@render name(
                                project.title,
                                project.program,
                                managerNote(project.manager),
                            )}
                            <span class="flex justify-center">
                                <input
                                    type="checkbox"
                                    aria-label={`Manager of ${project.title}`}
                                    checked={projectIds.includes(project.id)}
                                    onchange={(e) =>
                                        (projectIds = toggled(
                                            projectIds,
                                            project.id,
                                            e.currentTarget.checked,
                                        ))}
                                />
                            </span>
                            <span class="flex justify-center">
                                {#if projectIds.includes(project.id)}
                                    <!-- A manager is always on the team. -->
                                    <span
                                        title="A manager is always on the team"
                                        aria-label={`On the team of ${project.title}, as its manager`}
                                        ><Check
                                            class="h-3.5 w-3.5 text-fg-faint"
                                        /></span
                                    >
                                {:else if project.team_id !== null}
                                    {@const teamId = project.team_id}
                                    <input
                                        type="checkbox"
                                        aria-label={`Member of ${project.title}`}
                                        checked={isOnTeam(project)}
                                        onchange={(e) =>
                                            (teamIds = toggled(
                                                teamIds,
                                                teamId,
                                                e.currentTarget.checked,
                                            ))}
                                    />
                                {/if}
                            </span>
                        </div>
                    {:else}
                        <p class="px-3 py-3 text-xs text-fg-muted">
                            No project matches "{query.trim()}".
                        </p>
                    {/each}
                </div>
            </div>
            <p class="mt-2 text-xs text-fg-muted">
                In a ministry, a project's manager is usually an Under
                Secretary.
            </p>
            {#if errors.managed_project_ids}
                <p class="mt-1 text-xs text-danger">
                    {errors.managed_project_ids}
                </p>
            {/if}
            {#if errors.team_ids}
                <p class="mt-1 text-xs text-danger">{errors.team_ids}</p>
            {/if}
        </fieldset>
    {/if}
</div>
