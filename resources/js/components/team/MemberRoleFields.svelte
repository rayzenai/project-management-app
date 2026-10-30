<script lang="ts">
    import { toggled } from '../../lib/memberRoles';
    import type { Id, Member, RoleManager, RoleOptions } from '../../lib/types';

    /**
     * The person form's roles: the programs and projects of the person's
     * organization they manage, and the projects whose team they are on. A
     * manager role they hold is handed over by choosing someone else, so it
     * shows ticked and fixed here.
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
    const heldProjects = $derived(person?.managed_project_ids ?? []);
    const holdsAProgram = $derived(
        options.programs.some((p) => heldPrograms.includes(p.id)),
    );
    const holdsAProject = $derived(
        options.projects.some((p) => heldProjects.includes(p.id)),
    );
    const memberOptions = $derived(
        options.projects.filter((p) => p.team_id !== null),
    );

    function managerNote(manager: RoleManager | null): string | null {
        return manager && manager.id !== person?.id
            ? `now ${manager.name}`
            : null;
    }
</script>

{#snippet option(
    label: string,
    note: string | null,
    checked: boolean,
    disabled: boolean,
    onchange: (on: boolean) => void,
)}
    <label class="flex items-start gap-2 text-[13px] text-fg">
        <input
            type="checkbox"
            class="mt-0.5 shrink-0"
            {checked}
            {disabled}
            onchange={(e) => onchange(e.currentTarget.checked)}
        />
        <span class="min-w-0">
            <span class="block truncate">{label}</span>
            {#if note}
                <span class="block truncate text-xs text-fg-muted">{note}</span>
            {/if}
        </span>
    </label>
{/snippet}

{#if options.programs.length > 0 || options.projects.length > 0}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {#if options.programs.length > 0}
            <fieldset class="min-w-0 space-y-1">
                <legend class="label mb-1">Program manager of</legend>
                <div class="max-h-56 space-y-1 overflow-y-auto">
                    {#each options.programs as program (program.id)}
                        {@render option(
                            program.name,
                            managerNote(program.manager),
                            programIds.includes(program.id),
                            heldPrograms.includes(program.id),
                            (on) =>
                                (programIds = toggled(
                                    programIds,
                                    program.id,
                                    on,
                                )),
                        )}
                    {/each}
                </div>
                <p class="text-xs text-fg-muted">
                    In a ministry, usually a Joint Secretary.
                </p>
                {#if holdsAProgram}
                    <p class="text-xs text-fg-muted">
                        To hand this over, choose another manager.
                    </p>
                {/if}
                {#if errors.managed_program_ids}
                    <p class="text-xs text-danger">
                        {errors.managed_program_ids}
                    </p>
                {/if}
            </fieldset>
        {/if}
        {#if options.projects.length > 0}
            <fieldset class="min-w-0 space-y-1">
                <legend class="label mb-1">Project manager of</legend>
                <div class="max-h-56 space-y-1 overflow-y-auto">
                    {#each options.projects as project (project.id)}
                        {@render option(
                            project.title,
                            managerNote(project.manager),
                            projectIds.includes(project.id),
                            heldProjects.includes(project.id),
                            (on) =>
                                (projectIds = toggled(
                                    projectIds,
                                    project.id,
                                    on,
                                )),
                        )}
                    {/each}
                </div>
                <p class="text-xs text-fg-muted">
                    In a ministry, usually an Under Secretary.
                </p>
                {#if holdsAProject}
                    <p class="text-xs text-fg-muted">
                        To hand this over, choose another manager.
                    </p>
                {/if}
                {#if errors.managed_project_ids}
                    <p class="text-xs text-danger">
                        {errors.managed_project_ids}
                    </p>
                {/if}
            </fieldset>
            <fieldset class="min-w-0 space-y-1">
                <legend class="label mb-1">Member of</legend>
                <div class="max-h-56 space-y-1 overflow-y-auto">
                    {#each memberOptions as project (project.id)}
                        {@const teamId = project.team_id as Id}
                        <!-- A manager is on their team: that wins. -->
                        {@render option(
                            project.title,
                            null,
                            teamIds.includes(teamId) ||
                                projectIds.includes(project.id),
                            projectIds.includes(project.id),
                            (on) => (teamIds = toggled(teamIds, teamId, on)),
                        )}
                    {/each}
                </div>
                {#if errors.team_ids}
                    <p class="text-xs text-danger">{errors.team_ids}</p>
                {/if}
            </fieldset>
        {/if}
    </div>
{/if}
