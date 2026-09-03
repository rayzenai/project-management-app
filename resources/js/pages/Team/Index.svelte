<script lang="ts">
    import { page, router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import { initials } from '../../lib/format';
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

    const inputClass =
        'w-full rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-fg';
</script>

<svelte:head><title>Team · Workspace</title></svelte:head>

<AppShell>
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Team</h1>
        <p class="mt-1 text-sm text-fg-muted">
            People who can be assigned work — with or without a login — and the
            teams that group them.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <!-- Teams panel -->
        <section class="xl:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="ws-eyebrow text-fg-muted">Teams · {teams.length}</h2>
                {#if isSuperAdmin}
                    <button
                        type="button"
                        class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim"
                        onclick={() => (creatingTeam = !creatingTeam)}
                        >{creatingTeam ? 'Cancel' : '+ New team'}</button
                    >
                {/if}
            </div>

            {#if isSuperAdmin && creatingTeam}
                <form
                    onsubmit={createTeam}
                    class="mb-3 rounded-xl border border-line bg-surface p-4"
                >
                    <label
                        class="mb-1 block text-xs font-medium text-fg-muted"
                        for="team-name">Name</label
                    >
                    <input
                        id="team-name"
                        type="text"
                        bind:value={teamForm.name}
                        required
                        class={inputClass}
                    />
                    <label
                        class="mt-3 mb-1 block text-xs font-medium text-fg-muted"
                        for="team-description">Description (optional)</label
                    >
                    <input
                        id="team-description"
                        type="text"
                        bind:value={teamForm.description}
                        class={inputClass}
                    />
                    <div class="mt-3 flex justify-end">
                        <button
                            type="submit"
                            disabled={teamForm.processing ||
                                !teamForm.name.trim()}
                            class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
                            >Create team</button
                        >
                    </div>
                    {#if teamForm.errors.name}<p
                            class="mt-2 text-xs text-danger"
                        >
                            {teamForm.errors.name}
                        </p>{/if}
                </form>
            {/if}

            {#if visibleTeams.length === 0 && !creatingTeam}
                <div
                    class="rounded-xl border border-dashed border-line bg-surface p-8 text-center text-sm text-fg-muted"
                >
                    No teams yet. Until a project is attached to a team, every
                    active member is assignable everywhere.
                </div>
            {/if}

            <div class="space-y-3">
                {#each visibleTeams as team (team.id)}
                    <div class="rounded-xl border border-line bg-surface p-4">
                        <div class="flex items-center gap-2">
                            {#if editingTeamId === team.id}
                                <form
                                    onsubmit={(e) => saveTeam(e, team)}
                                    class="w-full"
                                >
                                    <label
                                        class="mb-1 block text-xs font-medium text-fg-muted"
                                        for={`team-${team.id}-name`}>Name</label
                                    >
                                    <input
                                        id={`team-${team.id}-name`}
                                        type="text"
                                        bind:value={teamEditForm.name}
                                        required
                                        class={inputClass}
                                        onkeydown={(e) => {
                                            if (e.key === 'Escape') {
                                                editingTeamId = null;
                                            }
                                        }}
                                    />
                                    <label
                                        class="mt-3 mb-1 block text-xs font-medium text-fg-muted"
                                        for={`team-${team.id}-description`}
                                        >Description (optional)</label
                                    >
                                    <input
                                        id={`team-${team.id}-description`}
                                        type="text"
                                        bind:value={teamEditForm.description}
                                        class={inputClass}
                                    />
                                    <div class="mt-3 flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-line px-3 py-1.5 text-sm font-medium text-fg-muted transition hover:bg-surface-alt"
                                            onclick={() =>
                                                (editingTeamId = null)}
                                            >Cancel</button
                                        >
                                        <button
                                            type="submit"
                                            disabled={teamEditForm.processing ||
                                                !teamEditForm.name.trim()}
                                            class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
                                            >Save</button
                                        >
                                    </div>
                                    {#if teamEditForm.errors.name}<p
                                            class="mt-2 text-xs text-danger"
                                        >
                                            {teamEditForm.errors.name}
                                        </p>{/if}
                                </form>
                            {:else}
                                {#if isSuperAdmin}
                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 truncate text-left text-base font-semibold hover:text-accent"
                                        title="Edit"
                                        onclick={() => startEditTeam(team)}
                                        >{team.name}</button
                                    >
                                {:else}
                                    <span
                                        class="min-w-0 flex-1 truncate text-base font-semibold"
                                        >{team.name}</span
                                    >
                                {/if}
                                <span class="ws-eyebrow shrink-0 text-fg-muted">
                                    {team.member_ids?.length ?? 0} member{(team
                                        .member_ids?.length ?? 0) === 1
                                        ? ''
                                        : 's'}
                                </span>
                                {#if isSuperAdmin}
                                    <button
                                        type="button"
                                        class="shrink-0 text-xs text-fg-muted hover:text-fg"
                                        onclick={() => startEditTeam(team)}
                                        >Edit</button
                                    >
                                    <button
                                        type="button"
                                        aria-label={`Delete ${team.name}`}
                                        class="shrink-0 rounded p-1 text-fg-faint hover:text-danger"
                                        onclick={() => deleteTeam(team)}
                                        >✕</button
                                    >
                                {/if}
                            {/if}
                        </div>
                        {#if team.description && editingTeamId !== team.id}
                            <p class="mt-1 text-sm text-fg-muted">
                                {team.description}
                            </p>
                        {/if}
                        {#if canManageTeam(team) && members.length > 0}
                            <div class="mt-3 space-y-1.5">
                                {#each members.filter((m) => m.is_active !== false) as member (member.id)}
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="truncate text-sm"
                                            >{member.name}</span
                                        >
                                        <div
                                            class="flex shrink-0 items-center gap-2"
                                        >
                                            {#if (team.member_ids ?? []).includes(member.id)}
                                                {#if isLeaderOf(team, member)}
                                                    <button
                                                        type="button"
                                                        class="text-xs text-accent hover:underline"
                                                        onclick={() =>
                                                            setTeamRole(
                                                                team,
                                                                member,
                                                                'member',
                                                            )}>Leader ✓</button
                                                    >
                                                {:else}
                                                    <button
                                                        type="button"
                                                        class="text-xs text-fg-muted hover:text-accent"
                                                        onclick={() =>
                                                            setTeamRole(
                                                                team,
                                                                member,
                                                                'leader',
                                                            )}
                                                        >Make leader</button
                                                    >
                                                {/if}
                                                <button
                                                    type="button"
                                                    class="text-xs text-fg-muted hover:text-danger"
                                                    onclick={() =>
                                                        removeMemberFromTeam(
                                                            team,
                                                            member,
                                                        )}>Remove</button
                                                >
                                            {:else}
                                                <button
                                                    type="button"
                                                    class="text-xs text-fg-muted hover:text-accent"
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
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                {#each members.filter( (m) => (team.member_ids ?? []).includes(m.id) ) as member (member.id)}
                                    <span
                                        class="rounded-full bg-accent/20 px-2.5 py-1 text-xs font-medium text-accent ring-1 ring-accent/60"
                                    >
                                        {member.name}
                                    </span>
                                {/each}
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        </section>

        <!-- Members panel -->
        <section class="xl:col-span-3">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="ws-eyebrow text-fg-muted">
                    Members · {members.length}
                </h2>
                {#if isSuperAdmin}
                    <button
                        type="button"
                        class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim"
                        onclick={() => (addingMember = !addingMember)}
                        >{addingMember ? 'Cancel' : '+ Add person'}</button
                    >
                {/if}
            </div>

            {#if isSuperAdmin && addingMember}
                <form
                    onsubmit={addMember}
                    class="mb-3 rounded-xl border border-line bg-surface p-4"
                >
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-fg-muted"
                                for="member-name">Name</label
                            >
                            <input
                                id="member-name"
                                type="text"
                                bind:value={memberForm.name}
                                required
                                class={inputClass}
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-fg-muted"
                                for="member-email"
                                >Email {memberForm.password
                                    ? ''
                                    : '(optional)'}</label
                            >
                            <input
                                id="member-email"
                                type="email"
                                bind:value={memberForm.email}
                                required={!!memberForm.password}
                                class={inputClass}
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-fg-muted"
                                for="member-password">Password (optional)</label
                            >
                            <input
                                id="member-password"
                                type="password"
                                bind:value={memberForm.password}
                                minlength="8"
                                autocomplete="new-password"
                                class={inputClass}
                            />
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-fg-muted"
                                for="member-title">Title (optional)</label
                            >
                            <input
                                id="member-title"
                                type="text"
                                bind:value={memberForm.title}
                                class={inputClass}
                            />
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-fg-muted">
                        Set a password to give them a login right away — or
                        leave it blank and upgrade them later from Edit.
                    </p>
                    <div class="mt-3 flex justify-end">
                        <button
                            type="submit"
                            disabled={memberForm.processing ||
                                !memberForm.name.trim()}
                            class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
                            >Add person</button
                        >
                    </div>
                    {#if memberForm.errors.name}<p
                            class="mt-2 text-xs text-danger"
                        >
                            {memberForm.errors.name}
                        </p>{/if}
                    {#if memberForm.errors.email}<p
                            class="mt-2 text-xs text-danger"
                        >
                            {memberForm.errors.email}
                        </p>{/if}
                    {#if memberForm.errors.password}<p
                            class="mt-2 text-xs text-danger"
                        >
                            {memberForm.errors.password}
                        </p>{/if}
                </form>
            {/if}

            {#if members.length === 0 && !addingMember}
                <div
                    class="rounded-xl border border-dashed border-line bg-surface p-8 text-center text-sm text-fg-muted"
                >
                    No members yet. Add anyone you assign work to — give them a
                    password now or upgrade them to a login later.
                </div>
            {/if}

            <div class="space-y-2">
                {#each members as member (member.id)}
                    <div
                        class={`rounded-xl border border-line bg-surface p-4 ${
                            member.is_active === false ? 'opacity-60' : ''
                        }`}
                    >
                        {#if editingMemberId === member.id}
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <input
                                    type="text"
                                    bind:value={memberEditForm.name}
                                    required
                                    class={inputClass}
                                    placeholder="Name"
                                />
                                <input
                                    type="email"
                                    bind:value={memberEditForm.email}
                                    class={inputClass}
                                    placeholder="Email"
                                />
                                <input
                                    type="text"
                                    bind:value={memberEditForm.title}
                                    class={inputClass}
                                    placeholder="Title"
                                />
                                <input
                                    type="password"
                                    bind:value={memberEditForm.password}
                                    minlength="8"
                                    autocomplete="new-password"
                                    class={inputClass}
                                    placeholder={member.user_id
                                        ? 'New password (leave blank to keep)'
                                        : 'Set a password to enable login'}
                                />
                            </div>
                            {#if memberEditForm.errors.email}<p
                                    class="mt-2 text-xs text-danger"
                                >
                                    {memberEditForm.errors.email}
                                </p>{/if}
                            {#if memberEditForm.errors.password}<p
                                    class="mt-2 text-xs text-danger"
                                >
                                    {memberEditForm.errors.password}
                                </p>{/if}
                            <div
                                class="mt-3 flex items-center justify-end gap-2"
                            >
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-sm text-fg-muted hover:bg-surface-alt"
                                    onclick={() => (editingMemberId = null)}
                                    >Cancel</button
                                >
                                <button
                                    type="button"
                                    disabled={memberEditForm.processing}
                                    class="rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-bg hover:bg-accent-dim disabled:opacity-50"
                                    onclick={() => saveMember(member)}
                                    >Save</button
                                >
                            </div>
                        {:else}
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-alt text-xs font-semibold text-fg-muted"
                                >
                                    {initials(member.name)}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-semibold"
                                            >{member.name}</span
                                        >
                                        {#if !member.user_id}
                                            <span
                                                class="rounded-full bg-accent/15 px-2 py-0.5 text-[10px] font-medium text-accent"
                                                title="Edit and set a password to enable login"
                                                >no login</span
                                            >
                                        {/if}
                                        {#if member.is_active === false}
                                            <span
                                                class="rounded-full bg-surface-alt px-2 py-0.5 text-[10px] font-medium text-fg-muted"
                                                >inactive</span
                                            >
                                        {/if}
                                    </div>
                                    <div class="truncate text-xs text-fg-muted">
                                        {[member.title, member.email]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                        {#if memberTeamNames(member).length > 0}
                                            · {memberTeamNames(member).join(
                                                ', ',
                                            )}
                                        {/if}
                                    </div>
                                </div>
                                {#if canEditMember(member)}
                                    <button
                                        type="button"
                                        class="shrink-0 text-xs text-fg-muted hover:text-fg"
                                        onclick={() => startEditMember(member)}
                                        >Edit</button
                                    >
                                    {#if member.is_active === false}
                                        <button
                                            type="button"
                                            class="shrink-0 text-xs text-success hover:underline"
                                            onclick={() =>
                                                setMemberActive(member, true)}
                                            >Reactivate</button
                                        >
                                    {:else}
                                        <button
                                            type="button"
                                            class="shrink-0 text-xs text-fg-muted hover:text-fg"
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
                                        class="shrink-0 rounded p-1 text-fg-faint hover:text-danger"
                                        onclick={() => deleteMember(member)}
                                        >✕</button
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
