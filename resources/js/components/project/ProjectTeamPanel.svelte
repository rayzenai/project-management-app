<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import type { ProjectTeam, ProjectTeamMember } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import RosterSearch from '../team/RosterSearch.svelte';

    /**
     * A project's team: its manager first, then everyone who can be given
     * its tasks. Its manager (or the office admin) adds and removes people;
     * only the office admin or its program's manager makes someone the
     * manager (`team.can_choose_manager`, per person `can_make_manager`).
     */
    let {
        team,
        projectTitle,
    }: {
        team: ProjectTeam;
        projectTitle: string;
    } = $props();

    const hasManager = $derived(team.members.some((m) => m.is_manager));

    function makeManager(member: ProjectTeamMember) {
        if (!confirm(`Make ${member.name} the manager of ${projectTitle}?`)) {
            return;
        }

        router.patch(
            `/workspace/teams/${team.id}/members/${member.id}`,
            { role: 'leader' },
            { preserveScroll: true, preserveState: true },
        );
    }

    function remove(member: ProjectTeamMember) {
        router.delete(`/workspace/teams/${team.id}/members/${member.id}`, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function detail(member: ProjectTeamMember): string {
        return [
            member.title,
            team.is_cross_organization ? member.organization : null,
            member.is_active ? null : 'Deactivated',
        ]
            .filter(Boolean)
            .join(' · ');
    }
</script>

<section>
    <h2 class="section-title mb-1">
        Team <span class="section-count">{team.members.length}</span>
        {#if team.is_cross_organization}
            <span class="chip ml-1 font-normal">Cross-organization</span>
        {/if}
    </h2>
    <p class="mb-2 text-xs text-fg-muted">
        Everyone here can be given this project's tasks.
    </p>

    {#if !hasManager}
        <p class="mb-2 text-xs text-warn">
            No manager yet.{team.can_choose_manager
                ? ' Make someone on the team its manager.'
                : ''}
        </p>
    {/if}

    {#if team.members.length > 0}
        <ul class="max-w-3xl border-t border-line">
            {#each team.members as member (member.id)}
                <li
                    class={`row min-h-11 gap-2.5 px-1 py-1.5 ${member.is_active ? '' : 'opacity-60'}`}
                >
                    <Avatar name={member.name} size="md" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium text-fg"
                                >{member.name}</span
                            >
                            {#if member.is_manager}
                                <span class="chip chip-accent">Manager</span>
                            {/if}
                        </div>
                        {#if detail(member)}
                            <div class="truncate text-xs text-fg-muted">
                                {detail(member)}
                            </div>
                        {/if}
                    </div>
                    {#if member.can_make_manager}
                        <button
                            type="button"
                            class="btn-ghost shrink-0"
                            onclick={() => makeManager(member)}
                            >Make manager</button
                        >
                    {/if}
                    {#if member.can_remove}
                        <button
                            type="button"
                            class="btn-ghost shrink-0"
                            onclick={() => remove(member)}>Remove</button
                        >
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}

    {#if team.can_manage}
        <RosterSearch
            team={{
                id: team.id,
                name: projectTitle,
                is_cross_organization: team.is_cross_organization,
            }}
        />
    {/if}
</section>
