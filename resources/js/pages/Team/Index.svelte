<script lang="ts">
    import { page, router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import type { Member, Team } from '../../lib/types';

    let { teams, members }: { teams: Team[]; members: Member[] } = $props();

    // ---- Role capabilities ----
    const isSuperAdmin = $derived<boolean>(
        (page.props as any).isSuperAdmin ?? false,
    );
    const ledTeamIds = $derived<number[]>((page.props as any).ledTeamIds ?? []);

    function canManageTeam(team: Team): boolean {
        return isSuperAdmin || ledTeamIds.includes(team.id);
    }

    function canEditMember(member: Member): boolean {
        return (
            isSuperAdmin ||
            (member.team_ids ?? []).some((id) => ledTeamIds.includes(id))
        );
    }

    // ---- Teams panel ----
    let creatingTeam = $state(false);
    let editingTeamId = $state<number | null>(null);

    const teamForm = useForm({ name: '', description: '' });
    const teamEditForm = useForm({ name: '', description: '' });

    function createTeam(e: SubmitEvent) {
        e.preventDefault();

        if (!teamForm.name.trim()) {
            return;
        }

        teamForm.post('/workspace/teams', {
            preserveScroll: true,
            onSuccess: () => {
                teamForm.reset();
                creatingTeam = false;
            },
        });
    }

    function startEditTeam(team: Team) {
        editingTeamId = team.id;
        teamEditForm.name = team.name;
        teamEditForm.description = team.description ?? '';
    }

    function saveTeam(e: SubmitEvent, team: Team) {
        e.preventDefault();

        if (!teamEditForm.name.trim()) {
            return;
        }

        teamEditForm.patch(`/workspace/teams/${team.id}`, {
            preserveScroll: true,
            onSuccess: () => (editingTeamId = null),
        });
    }

    function deleteTeam(team: Team) {
        if (
            !confirm(
                `Delete team "${team.name}"? Members are kept; only the grouping is removed.`,
            )
        ) {
            return;
        }

        router.delete(`/workspace/teams/${team.id}`, { preserveScroll: true });
    }

    // ---- Team-scoped roster helpers ----
    function addMemberToTeam(team: Team, member: Member) {
        router.post(
            `/workspace/teams/${team.id}/members`,
            { member_id: member.id },
            { preserveScroll: true },
        );
    }

    function removeMemberFromTeam(team: Team, member: Member) {
        router.delete(`/workspace/teams/${team.id}/members/${member.id}`, {
            preserveScroll: true,
        });
    }

    function setTeamRole(
        team: Team,
        member: Member,
        role: 'member' | 'leader',
    ) {
        router.patch(
            `/workspace/teams/${team.id}/members/${member.id}`,
            { role },
            { preserveScroll: true },
        );
    }

    function isLeaderOf(team: Team, member: Member): boolean {
        return (team.leader_ids ?? []).includes(member.id);
    }

    // ---- Members panel ----
    let addingMember = $state(false);
    let editingMemberId = $state<number | null>(null);

    const memberForm = useForm({
        name: '',
        email: '',
        password: '',
        title: '',
    });
    const memberEditForm = useForm({
        name: '',
        email: '',
        password: '',
        title: '',
    });

    function addMember(e: SubmitEvent) {
        e.preventDefault();

        if (!memberForm.name.trim()) {
            return;
        }

        memberForm.post('/workspace/members', {
            preserveScroll: true,
            onSuccess: () => {
                memberForm.reset();
                addingMember = false;
            },
        });
    }

    function startEditMember(member: Member) {
        editingMemberId = member.id;
        memberEditForm.name = member.name;
        memberEditForm.email = member.email ?? '';
        memberEditForm.password = '';
        memberEditForm.title = member.title ?? '';
    }

    function saveMember(member: Member) {
        memberEditForm.patch(`/workspace/members/${member.id}`, {
            preserveScroll: true,
            onSuccess: () => (editingMemberId = null),
        });
    }

    function setMemberActive(member: Member, active: boolean) {
        router.patch(
            `/workspace/members/${member.id}`,
            { is_active: active },
            { preserveScroll: true },
        );
    }

    function deleteMember(member: Member) {
        if (
            !confirm(
                `Remove ${member.name} entirely? Their task assignments${member.user_id ? ' and login' : ''} are deleted too. Prefer "Deactivate" to keep history.`,
            )
        ) {
            return;
        }

        router.delete(`/workspace/members/${member.id}`, {
            preserveScroll: true,
        });
    }

    const memberTeamNames = $derived((member: Member) =>
        teams
            .filter((t) => (member.team_ids ?? []).includes(t.id))
            .map((t) => t.name),
    );

    const visibleTeams = $derived(
        isSuperAdmin ? teams : teams.filter((t) => canManageTeam(t)),
    );

    const activeMembers = $derived(
        members.filter((m) => m.is_active !== false),
    );
</script>

<svelte:head><title>Team · Workspace</title></svelte:head>

{#snippet memberIdentity(member: Member)}
    <Avatar name={member.name} size="md" />
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <span class="truncate font-medium text-fg">{member.name}</span>
            {#if !member.user_id}
                <span
                    class="chip"
                    title="Edit and set a password to enable login"
                    >No login</span
                >
            {/if}
            {#if member.is_active === false}
                <span class="chip">Inactive</span>
            {/if}
        </div>
        <div class="flex flex-wrap gap-x-3 text-xs text-fg-muted">
            {#if member.title}<span class="truncate">{member.title}</span>{/if}
            {#if member.email}<span class="truncate">{member.email}</span>{/if}
            {#if memberTeamNames(member).length > 0}
                <span class="truncate text-fg-faint"
                    >{memberTeamNames(member).join(', ')}</span
                >
            {/if}
        </div>
    </div>
{/snippet}

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Team</span>
        </div>
        {#if isSuperAdmin}
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    class="btn"
                    aria-expanded={creatingTeam}
                    onclick={() => (creatingTeam = !creatingTeam)}
                    >{creatingTeam ? 'Cancel' : 'New team'}</button
                >
                <button
                    type="button"
                    class="btn-primary"
                    aria-expanded={addingMember}
                    onclick={() => (addingMember = !addingMember)}
                    >{addingMember ? 'Cancel' : 'Add person'}</button
                >
            </div>
        {/if}
    {/snippet}

    <div class="space-y-8">
        {#if isSuperAdmin && creatingTeam}
            <form onsubmit={createTeam} class="panel max-w-lg space-y-3 p-4">
                <h2 class="section-title">New team</h2>
                <div class="flex flex-col gap-1">
                    <label class="label" for="team-name">Name</label>
                    <input
                        id="team-name"
                        type="text"
                        bind:value={teamForm.name}
                        required
                        class="input"
                    />
                    {#if teamForm.errors.name}
                        <p class="text-xs text-danger">
                            {teamForm.errors.name}
                        </p>
                    {/if}
                </div>
                <div class="flex flex-col gap-1">
                    <label class="label" for="team-description"
                        >Description (optional)</label
                    >
                    <input
                        id="team-description"
                        type="text"
                        bind:value={teamForm.description}
                        class="input"
                    />
                </div>
                <div class="flex justify-end gap-1.5">
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => (creatingTeam = false)}>Cancel</button
                    >
                    <button
                        type="submit"
                        disabled={teamForm.processing || !teamForm.name.trim()}
                        class="btn-primary">Create team</button
                    >
                </div>
            </form>
        {/if}

        {#if isSuperAdmin && addingMember}
            <form onsubmit={addMember} class="panel max-w-2xl space-y-3 p-4">
                <h2 class="section-title">Add person</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label class="label" for="member-name">Name</label>
                        <input
                            id="member-name"
                            type="text"
                            bind:value={memberForm.name}
                            required
                            class="input"
                        />
                        {#if memberForm.errors.name}
                            <p class="text-xs text-danger">
                                {memberForm.errors.name}
                            </p>
                        {/if}
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="label" for="member-email"
                            >Email {memberForm.password
                                ? ''
                                : '(optional)'}</label
                        >
                        <input
                            id="member-email"
                            type="email"
                            bind:value={memberForm.email}
                            required={!!memberForm.password}
                            class="input"
                        />
                        {#if memberForm.errors.email}
                            <p class="text-xs text-danger">
                                {memberForm.errors.email}
                            </p>
                        {/if}
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="label" for="member-password"
                            >Password (optional)</label
                        >
                        <input
                            id="member-password"
                            type="password"
                            bind:value={memberForm.password}
                            minlength="8"
                            autocomplete="new-password"
                            class="input"
                        />
                        {#if memberForm.errors.password}
                            <p class="text-xs text-danger">
                                {memberForm.errors.password}
                            </p>
                        {/if}
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="label" for="member-title"
                            >Title (optional)</label
                        >
                        <input
                            id="member-title"
                            type="text"
                            bind:value={memberForm.title}
                            class="input"
                        />
                    </div>
                </div>
                <p class="text-xs text-fg-muted">
                    Set a password to give them a login right away, or leave it
                    blank and upgrade them later from Edit.
                </p>
                <div class="flex justify-end gap-1.5">
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => (addingMember = false)}>Cancel</button
                    >
                    <button
                        type="submit"
                        disabled={memberForm.processing ||
                            !memberForm.name.trim()}
                        class="btn-primary">Add person</button
                    >
                </div>
            </form>
        {/if}

        <!-- Teams -->
        <section>
            <h2 class="section-title mb-2">
                Teams
                <span class="section-count">{teams.length}</span>
            </h2>

            {#if visibleTeams.length === 0 && !creatingTeam}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    No teams yet. Until a project is attached to a team, every
                    active member is assignable everywhere.
                </p>
            {/if}

            {#each visibleTeams as team (team.id)}
                <div class="border-t border-line py-3">
                    {#if editingTeamId === team.id}
                        <form
                            onsubmit={(e) => saveTeam(e, team)}
                            class="max-w-lg space-y-3"
                        >
                            <div class="flex flex-col gap-1">
                                <label
                                    class="label"
                                    for={`team-${team.id}-name`}>Name</label
                                >
                                <input
                                    id={`team-${team.id}-name`}
                                    type="text"
                                    bind:value={teamEditForm.name}
                                    required
                                    class="input"
                                    onkeydown={(e) => {
                                        if (e.key === 'Escape') {
                                            editingTeamId = null;
                                        }
                                    }}
                                />
                                {#if teamEditForm.errors.name}
                                    <p class="text-xs text-danger">
                                        {teamEditForm.errors.name}
                                    </p>
                                {/if}
                            </div>
                            <div class="flex flex-col gap-1">
                                <label
                                    class="label"
                                    for={`team-${team.id}-description`}
                                    >Description (optional)</label
                                >
                                <input
                                    id={`team-${team.id}-description`}
                                    type="text"
                                    bind:value={teamEditForm.description}
                                    class="input"
                                />
                            </div>
                            <div class="flex justify-end gap-1.5">
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    onclick={() => (editingTeamId = null)}
                                    >Cancel</button
                                >
                                <button
                                    type="submit"
                                    disabled={teamEditForm.processing ||
                                        !teamEditForm.name.trim()}
                                    class="btn-primary">Save</button
                                >
                            </div>
                        </form>
                    {:else}
                        <div class="flex items-center gap-3">
                            <h3 class="section-title min-w-0">
                                <span class="truncate">{team.name}</span>
                                <span class="section-count">
                                    {team.member_ids?.length ?? 0} member{(team
                                        .member_ids?.length ?? 0) === 1
                                        ? ''
                                        : 's'}
                                </span>
                            </h3>
                            {#if team.description}
                                <span
                                    class="min-w-0 truncate text-xs text-fg-muted"
                                    >{team.description}</span
                                >
                            {/if}
                            {#if isSuperAdmin}
                                <div
                                    class="ml-auto flex shrink-0 items-center gap-1"
                                >
                                    <button
                                        type="button"
                                        class="btn-ghost"
                                        onclick={() => startEditTeam(team)}
                                        >Edit</button
                                    >
                                    <button
                                        type="button"
                                        aria-label={`Delete ${team.name}`}
                                        class="btn-danger"
                                        onclick={() => deleteTeam(team)}
                                        >Delete</button
                                    >
                                </div>
                            {/if}
                        </div>
                    {/if}

                    {#if canManageTeam(team) && members.length > 0}
                        <div class="mt-2">
                            {#each activeMembers as member (member.id)}
                                {@const inTeam = (
                                    team.member_ids ?? []
                                ).includes(member.id)}
                                <div class="row min-h-10 px-2 py-1">
                                    <Avatar name={member.name} size="md" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="truncate font-medium text-fg"
                                                >{member.name}</span
                                            >
                                            {#if inTeam && isLeaderOf(team, member)}
                                                <span class="chip chip-accent"
                                                    >Leader</span
                                                >
                                            {/if}
                                        </div>
                                        {#if member.title || member.email}
                                            <div
                                                class="truncate text-xs text-fg-muted"
                                            >
                                                {member.title || member.email}
                                            </div>
                                        {/if}
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-1"
                                    >
                                        {#if inTeam}
                                            <select
                                                class="input h-7 w-auto py-0"
                                                aria-label={`Role of ${member.name} in ${team.name}`}
                                                value={isLeaderOf(team, member)
                                                    ? 'leader'
                                                    : 'member'}
                                                onchange={(e) =>
                                                    setTeamRole(
                                                        team,
                                                        member,
                                                        e.currentTarget
                                                            .value as
                                                            'member' | 'leader',
                                                    )}
                                            >
                                                <option value="member"
                                                    >Member</option
                                                >
                                                <option value="leader"
                                                    >Leader</option
                                                >
                                            </select>
                                            <button
                                                type="button"
                                                class="btn-ghost"
                                                onclick={() =>
                                                    removeMemberFromTeam(
                                                        team,
                                                        member,
                                                    )}>Remove</button
                                            >
                                        {:else}
                                            <button
                                                type="button"
                                                class="btn-ghost"
                                                onclick={() =>
                                                    addMemberToTeam(
                                                        team,
                                                        member,
                                                    )}>Add</button
                                            >
                                        {/if}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {:else if members.length > 0}
                        <div class="mt-2">
                            {#each members.filter( (m) => (team.member_ids ?? []).includes(m.id) ) as member (member.id)}
                                <div class="row min-h-10 px-2 py-1">
                                    <Avatar name={member.name} size="md" />
                                    <span class="truncate font-medium text-fg"
                                        >{member.name}</span
                                    >
                                    {#if isLeaderOf(team, member)}
                                        <span class="chip chip-accent"
                                            >Leader</span
                                        >
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>
            {/each}
        </section>

        <!-- People -->
        <section>
            <h2 class="section-title mb-2">
                People
                <span class="section-count">{members.length}</span>
            </h2>

            {#if members.length === 0 && !addingMember}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    No members yet. Add anyone you assign work to, and give them
                    a password now or upgrade them to a login later.
                </p>
            {/if}

            <div class="border-t border-line">
                {#each members as member (member.id)}
                    <div
                        class={`row min-h-11 px-2 py-1.5 ${
                            member.is_active === false ? 'opacity-60' : ''
                        }`}
                    >
                        {#if editingMemberId === member.id}
                            <div class="min-w-0 flex-1 space-y-3 py-1.5">
                                <div
                                    class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                                >
                                    <div class="flex flex-col gap-1">
                                        <label
                                            class="label"
                                            for={`member-${member.id}-name`}
                                            >Name</label
                                        >
                                        <input
                                            id={`member-${member.id}-name`}
                                            type="text"
                                            bind:value={memberEditForm.name}
                                            required
                                            class="input"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label
                                            class="label"
                                            for={`member-${member.id}-email`}
                                            >Email</label
                                        >
                                        <input
                                            id={`member-${member.id}-email`}
                                            type="email"
                                            bind:value={memberEditForm.email}
                                            class="input"
                                        />
                                        {#if memberEditForm.errors.email}
                                            <p class="text-xs text-danger">
                                                {memberEditForm.errors.email}
                                            </p>
                                        {/if}
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label
                                            class="label"
                                            for={`member-${member.id}-title`}
                                            >Title</label
                                        >
                                        <input
                                            id={`member-${member.id}-title`}
                                            type="text"
                                            bind:value={memberEditForm.title}
                                            class="input"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label
                                            class="label"
                                            for={`member-${member.id}-password`}
                                            >Password</label
                                        >
                                        <input
                                            id={`member-${member.id}-password`}
                                            type="password"
                                            bind:value={memberEditForm.password}
                                            minlength="8"
                                            autocomplete="new-password"
                                            class="input"
                                            placeholder={member.user_id
                                                ? 'New password (leave blank to keep)'
                                                : 'Set a password to enable login'}
                                        />
                                        {#if memberEditForm.errors.password}
                                            <p class="text-xs text-danger">
                                                {memberEditForm.errors.password}
                                            </p>
                                        {/if}
                                    </div>
                                </div>
                                <div
                                    class="flex items-center justify-end gap-1.5"
                                >
                                    <button
                                        type="button"
                                        class="btn-ghost"
                                        onclick={() => (editingMemberId = null)}
                                        >Cancel</button
                                    >
                                    <button
                                        type="button"
                                        disabled={memberEditForm.processing}
                                        class="btn-primary"
                                        onclick={() => saveMember(member)}
                                        >Save</button
                                    >
                                </div>
                            </div>
                        {:else}
                            {@render memberIdentity(member)}
                            <div class="flex shrink-0 items-center gap-1">
                                {#if canEditMember(member)}
                                    <button
                                        type="button"
                                        class="btn-ghost"
                                        onclick={() => startEditMember(member)}
                                        >Edit</button
                                    >
                                    {#if member.is_active === false}
                                        <button
                                            type="button"
                                            class="btn-ghost"
                                            onclick={() =>
                                                setMemberActive(member, true)}
                                            >Reactivate</button
                                        >
                                    {:else}
                                        <button
                                            type="button"
                                            class="btn-ghost"
                                            onclick={() =>
                                                setMemberActive(member, false)}
                                            >Deactivate</button
                                        >
                                    {/if}
                                {/if}
                                {#if isSuperAdmin}
                                    <button
                                        type="button"
                                        aria-label={`Delete ${member.name}`}
                                        class="btn-danger"
                                        onclick={() => deleteMember(member)}
                                        >Delete</button
                                    >
                                {/if}
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        </section>
    </div>
</AppShell>
