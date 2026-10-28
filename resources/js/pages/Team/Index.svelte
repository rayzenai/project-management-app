<script lang="ts">
    import { inertia, page, router, useForm } from '@inertiajs/svelte';
    import { ChartGantt, Copy, RefreshCw } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import RosterSearch from '../../components/team/RosterSearch.svelte';
    import { writeToClipboard } from '../../lib/clipboard';
    import { toast } from '../../lib/toast.svelte';
    import type {
        Member,
        OrganizationOption,
        RosterEntry,
        Team,
    } from '../../lib/types';

    let {
        teams,
        members,
        organizations = [],
        organizationFilter = null,
        officeAdminOrganization = null,
        canCreateTeam = false,
        canCreateMember = false,
    }: {
        teams: Team[];
        members: Member[];
        organizations?: OrganizationOption[];
        organizationFilter?: number | null;
        officeAdminOrganization?: OrganizationOption | null;
        canCreateTeam?: boolean;
        canCreateMember?: boolean;
    } = $props();

    const isSuperAdmin = $derived<boolean>(
        (page.props as any).isSuperAdmin ?? false,
    );

    /** The organization new teams and people land in when it is not chosen. */
    const defaultOrganizationId = $derived(
        organizationFilter ??
            (organizations.length === 1 ? organizations[0].id : null),
    );

    function filterByOrganization(value: string) {
        router.get('/workspace/team', value ? { organization: value } : {}, {
            preserveScroll: true,
        });
    }

    // ---- Teams ----
    let creatingTeam = $state(false);
    let editingTeamId = $state<number | null>(null);

    const teamForm = useForm({
        name: '',
        description: '',
        organization_id: null as number | null,
        is_cross_organization: false,
    });
    const teamEditForm = useForm({
        name: '',
        description: '',
        is_cross_organization: false,
    });

    function openTeamForm() {
        creatingTeam = !creatingTeam;
        teamForm.organization_id = defaultOrganizationId;
    }

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
        teamEditForm.is_cross_organization =
            team.is_cross_organization ?? false;
    }

    function saveTeam(e: SubmitEvent, team: Team) {
        e.preventDefault();

        if (!teamEditForm.name.trim()) {
            return;
        }

        teamEditForm
            .transform((data) =>
                isSuperAdmin
                    ? data
                    : { name: data.name, description: data.description },
            )
            .patch(`/workspace/teams/${team.id}`, {
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

    function removeFromTeam(team: Team, entry: RosterEntry) {
        router.delete(`/workspace/teams/${team.id}/members/${entry.id}`, {
            preserveScroll: true,
        });
    }

    function setTeamRole(
        team: Team,
        entry: RosterEntry,
        role: 'member' | 'leader',
    ) {
        router.patch(
            `/workspace/teams/${team.id}/members/${entry.id}`,
            { role },
            { preserveScroll: true },
        );
    }

    // ---- People ----
    let addingMember = $state(false);
    let editingMemberId = $state<number | null>(null);

    // Readable aloud or over the phone: no 0/O or 1/l/I, three groups of four.
    const PASSWORD_ALPHABET =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    function generatePassword(): string {
        const picks = new Uint32Array(12);

        for (;;) {
            crypto.getRandomValues(picks);
            const chars = Array.from(
                picks,
                (n) => PASSWORD_ALPHABET[n % PASSWORD_ALPHABET.length],
            );
            const password = [0, 4, 8]
                .map((i) => chars.slice(i, i + 4).join(''))
                .join('-');

            if (
                /[A-Z]/.test(password) &&
                /[a-z]/.test(password) &&
                /\d/.test(password)
            ) {
                return password;
            }
        }
    }

    const memberForm = useForm({
        name: '',
        email: '',
        password: generatePassword(),
        title: '',
        organization_id: null as number | null,
        is_office_admin: false,
    });

    function openMemberForm() {
        addingMember = !addingMember;
        memberForm.organization_id = defaultOrganizationId;
    }

    // The login just created, shown once so the admin can try it or pass it
    // on; it never comes back from the server.
    let createdLogin = $state<{
        name: string;
        email: string;
        password: string;
    } | null>(null);

    const loginUrl = `${window.location.origin}/workspace/login`;

    function copyLogin() {
        if (!createdLogin) {
            return;
        }

        writeToClipboard(
            `Sign in at ${loginUrl}\nEmail: ${createdLogin.email}\nPassword: ${createdLogin.password}`,
        );
        toast.show('Copied login details', { duration: 2000 });
    }

    const memberEditForm = useForm({
        name: '',
        email: '',
        password: '',
        title: '',
        organization_id: null as number | null,
        is_office_admin: false,
    });

    function addMember(e: SubmitEvent) {
        e.preventDefault();

        if (!memberForm.name.trim()) {
            return;
        }

        const login = {
            name: memberForm.name.trim(),
            email: memberForm.email.trim(),
            password: memberForm.password,
        };

        memberForm
            .transform((data) =>
                isSuperAdmin
                    ? data
                    : {
                          name: data.name,
                          email: data.email,
                          password: data.password,
                          title: data.title,
                      },
            )
            .post('/workspace/members', {
                preserveScroll: true,
                onSuccess: () => {
                    createdLogin = login;
                    memberForm.reset();
                    memberForm.password = generatePassword();
                    addingMember = false;
                },
            });
    }

    function startEditMember(member: Member) {
        editingMemberId = member.id;
        memberEditForm.clearErrors();
        memberEditForm.name = member.name;
        memberEditForm.email = member.email ?? '';
        memberEditForm.password = '';
        memberEditForm.title = member.title ?? '';
        memberEditForm.organization_id = member.organization_id ?? null;
        memberEditForm.is_office_admin = member.is_office_admin ?? false;
    }

    function saveMember(member: Member) {
        memberEditForm
            .transform((data) => {
                const base = {
                    name: data.name,
                    email: data.email,
                    password: data.password,
                    title: data.title,
                };

                // Organization and office-admin status are a super-admin's
                // call, and a super-admin's own member has no organization.
                return isSuperAdmin && member.organization_id
                    ? {
                          ...base,
                          organization_id: data.organization_id,
                          is_office_admin: data.is_office_admin,
                      }
                    : base;
            })
            .patch(`/workspace/members/${member.id}`, {
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

    const managesPeople = $derived(isSuperAdmin || canCreateMember);
</script>

<svelte:head><title>Team · Workspace</title></svelte:head>

{#snippet organizationSelect(
    id: string,
    value: number | null,
    onchange: (value: number | null) => void,
)}
    <select
        {id}
        class="input"
        required
        value={value ?? ''}
        onchange={(e) =>
            onchange(
                e.currentTarget.value ? Number(e.currentTarget.value) : null,
            )}
    >
        <option value="" disabled>Choose an organization</option>
        {#each organizations as organization (organization.id)}
            <option value={organization.id}>{organization.name}</option>
        {/each}
    </select>
{/snippet}

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Team</span>
            {#if officeAdminOrganization}
                <span class="truncate text-fg-muted"
                    >· {officeAdminOrganization.name}</span
                >
            {/if}
        </div>
        <div class="flex items-center gap-1.5">
            {#if isSuperAdmin && organizations.length > 0}
                <select
                    class="input h-7 w-auto max-w-56 py-0"
                    aria-label="Show one organization"
                    value={organizationFilter ?? ''}
                    onchange={(e) =>
                        filterByOrganization(e.currentTarget.value)}
                >
                    <option value="">All organizations</option>
                    {#each organizations as organization (organization.id)}
                        <option value={organization.id}
                            >{organization.name}</option
                        >
                    {/each}
                </select>
            {/if}
            {#if canCreateTeam}
                <button
                    type="button"
                    class="btn"
                    aria-expanded={creatingTeam}
                    onclick={openTeamForm}
                    >{creatingTeam ? 'Cancel' : 'New team'}</button
                >
            {/if}
            {#if canCreateMember}
                <button
                    type="button"
                    class="btn-primary"
                    aria-expanded={addingMember}
                    onclick={openMemberForm}
                    >{addingMember ? 'Cancel' : 'Add person'}</button
                >
            {/if}
        </div>
    {/snippet}

    <div class="space-y-8">
        {#if canCreateTeam && creatingTeam}
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
                {#if isSuperAdmin}
                    <div class="flex flex-col gap-1">
                        <label class="label" for="team-organization"
                            >Organization</label
                        >
                        {@render organizationSelect(
                            'team-organization',
                            teamForm.organization_id,
                            (v) => (teamForm.organization_id = v),
                        )}
                        {#if teamForm.errors.organization_id}
                            <p class="text-xs text-danger">
                                {teamForm.errors.organization_id}
                            </p>
                        {/if}
                    </div>
                    <label class="flex items-start gap-2 text-[13px] text-fg">
                        <input
                            type="checkbox"
                            class="mt-0.5"
                            bind:checked={teamForm.is_cross_organization}
                        />
                        <span>
                            Cross-organization
                            <span class="block text-xs text-fg-muted"
                                >Its leaders can add people from any
                                organization.</span
                            >
                        </span>
                    </label>
                {:else if officeAdminOrganization}
                    <p class="text-xs text-fg-muted">
                        The team belongs to {officeAdminOrganization.name}.
                    </p>
                {/if}
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

        {#if canCreateMember && addingMember}
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
                        <label class="label" for="member-email">Email</label>
                        <input
                            id="member-email"
                            type="email"
                            bind:value={memberForm.email}
                            required
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
                            >Password</label
                        >
                        <div class="flex items-center gap-1">
                            <input
                                id="member-password"
                                type="text"
                                bind:value={memberForm.password}
                                required
                                minlength="8"
                                autocomplete="off"
                                spellcheck="false"
                                class="input font-mono"
                            />
                            <button
                                type="button"
                                class="btn-icon shrink-0"
                                title="Generate another password"
                                aria-label="Generate another password"
                                onclick={() =>
                                    (memberForm.password = generatePassword())}
                                ><RefreshCw class="h-4 w-4" /></button
                            >
                        </div>
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
                    {#if isSuperAdmin}
                        <div class="flex flex-col gap-1">
                            <label class="label" for="member-organization"
                                >Organization</label
                            >
                            {@render organizationSelect(
                                'member-organization',
                                memberForm.organization_id,
                                (v) => (memberForm.organization_id = v),
                            )}
                            {#if memberForm.errors.organization_id}
                                <p class="text-xs text-danger">
                                    {memberForm.errors.organization_id}
                                </p>
                            {/if}
                        </div>
                        <label
                            class="flex items-center gap-2 self-end pb-2 text-[13px] text-fg"
                        >
                            <input
                                type="checkbox"
                                bind:checked={memberForm.is_office_admin}
                            />
                            Office admin of this organization
                        </label>
                    {/if}
                </div>
                <p class="text-xs text-fg-muted">
                    We email them this password too. After adding, you can copy
                    their login details to share or try yourself.
                    {#if officeAdminOrganization}
                        They join {officeAdminOrganization.name}.
                    {/if}
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
                            !memberForm.name.trim() ||
                            !memberForm.email.trim() ||
                            memberForm.password.length < 8}
                        class="btn-primary">Add person</button
                    >
                </div>
            </form>
        {/if}

        {#if createdLogin}
            <div class="panel max-w-2xl space-y-3 p-4">
                <h2 class="section-title">
                    {createdLogin.name} can sign in now
                </h2>
                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[13px]"
                >
                    <dt class="text-fg-muted">Sign in at</dt>
                    <dd class="min-w-0 truncate font-mono">{loginUrl}</dd>
                    <dt class="text-fg-muted">Email</dt>
                    <dd class="min-w-0 truncate font-mono">
                        {createdLogin.email}
                    </dd>
                    <dt class="text-fg-muted">Password</dt>
                    <dd class="min-w-0 font-mono break-all select-all">
                        {createdLogin.password}
                    </dd>
                </dl>
                <p class="text-xs text-fg-muted">
                    The same details went to their email. This is the only time
                    the password is shown; Edit can set a new one.
                </p>
                <div class="flex justify-end gap-1.5">
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => (createdLogin = null)}>Done</button
                    >
                    <button type="button" class="btn" onclick={copyLogin}
                        ><Copy class="h-3.5 w-3.5" />Copy login details</button
                    >
                </div>
            </div>
        {/if}

        <!-- Teams -->
        <section>
            <h2 class="section-title mb-2">
                {managesPeople ? 'Teams' : 'Your teams'}
                <span class="section-count">{teams.length}</span>
            </h2>

            {#if teams.length === 0 && !creatingTeam}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    {canCreateTeam
                        ? 'No teams yet. Projects are shared with teams, so start with one.'
                        : 'You are not on a team yet.'}
                </p>
            {/if}

            {#each teams as team (team.id)}
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
                            {#if isSuperAdmin}
                                <label
                                    class="flex items-start gap-2 text-[13px] text-fg"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5"
                                        bind:checked={
                                            teamEditForm.is_cross_organization
                                        }
                                    />
                                    <span>
                                        Cross-organization
                                        <span
                                            class="block text-xs text-fg-muted"
                                            >Its leaders can add people from any
                                            organization.</span
                                        >
                                    </span>
                                </label>
                                {#if teamEditForm.errors.is_cross_organization}
                                    <p class="text-xs text-danger">
                                        {teamEditForm.errors
                                            .is_cross_organization}
                                    </p>
                                {/if}
                            {/if}
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
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1"
                        >
                            <h3 class="section-title min-w-0">
                                <span class="truncate">{team.name}</span>
                                <span class="section-count">
                                    {team.roster?.length ?? 0} member{(team
                                        .roster?.length ?? 0) === 1
                                        ? ''
                                        : 's'}
                                </span>
                            </h3>
                            {#if team.is_cross_organization}
                                <span class="chip">Cross-organization</span>
                            {/if}
                            {#if isSuperAdmin && team.organization}
                                <span class="truncate text-xs text-fg-faint"
                                    >{team.organization}</span
                                >
                            {/if}
                            {#if team.description}
                                <span
                                    class="min-w-0 truncate text-xs text-fg-muted"
                                    >{team.description}</span
                                >
                            {/if}
                            <div
                                class="ml-auto flex shrink-0 items-center gap-1"
                            >
                                {#if team.can_view_progress}
                                    <a
                                        href={`/workspace/teams/${team.id}/progress`}
                                        use:inertia
                                        class="btn-ghost"
                                        ><ChartGantt
                                            class="h-3.5 w-3.5"
                                        />Progress</a
                                    >
                                {/if}
                                {#if team.can_edit}
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
                                {/if}
                            </div>
                        </div>
                    {/if}

                    <div class="mt-2">
                        {#each team.roster ?? [] as entry (entry.id)}
                            <div
                                class={`row min-h-10 px-2 py-1 ${entry.is_active ? '' : 'opacity-60'}`}
                            >
                                <Avatar name={entry.name} size="md" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate font-medium text-fg"
                                            >{entry.name}</span
                                        >
                                        {#if entry.role === 'leader' && !team.can_edit}
                                            <span class="chip chip-accent"
                                                >Leader</span
                                            >
                                        {/if}
                                    </div>
                                    <div class="truncate text-xs text-fg-muted">
                                        {[
                                            entry.title || entry.email,
                                            team.is_cross_organization
                                                ? entry.organization
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    {#if team.can_edit}
                                        <select
                                            class="input h-7 w-auto py-0"
                                            aria-label={`Role of ${entry.name} in ${team.name}`}
                                            value={entry.role}
                                            onchange={(e) =>
                                                setTeamRole(
                                                    team,
                                                    entry,
                                                    e.currentTarget.value as
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
                                    {/if}
                                    {#if entry.can_remove}
                                        <button
                                            type="button"
                                            class="btn-ghost"
                                            onclick={() =>
                                                removeFromTeam(team, entry)}
                                            >Remove</button
                                        >
                                    {/if}
                                </div>
                            </div>
                        {/each}
                        {#if (team.roster ?? []).length === 0}
                            <p class="px-2 py-1 text-xs text-fg-muted">
                                Nobody on this team yet.
                            </p>
                        {/if}
                        {#if team.can_manage_roster}
                            <RosterSearch {team} />
                        {/if}
                    </div>
                </div>
            {/each}
        </section>

        <!-- People -->
        <section>
            <h2 class="section-title mb-2">
                {managesPeople ? 'People' : 'Your teammates'}
                <span class="section-count">{members.length}</span>
            </h2>

            {#if members.length === 0 && !addingMember}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    Nobody here yet.
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
                                            required
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
                                    {#if isSuperAdmin && member.organization_id}
                                        <div class="flex flex-col gap-1">
                                            <label
                                                class="label"
                                                for={`member-${member.id}-organization`}
                                                >Organization</label
                                            >
                                            {@render organizationSelect(
                                                `member-${member.id}-organization`,
                                                memberEditForm.organization_id,
                                                (v) =>
                                                    (memberEditForm.organization_id =
                                                        v),
                                            )}
                                            {#if memberEditForm.errors.organization_id}
                                                <p class="text-xs text-danger">
                                                    {memberEditForm.errors
                                                        .organization_id}
                                                </p>
                                            {/if}
                                        </div>
                                        <label
                                            class="flex items-center gap-2 self-end pb-2 text-[13px] text-fg"
                                        >
                                            <input
                                                type="checkbox"
                                                bind:checked={
                                                    memberEditForm.is_office_admin
                                                }
                                            />
                                            Office admin of this organization
                                        </label>
                                    {/if}
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
                            <Avatar name={member.name} size="md" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium text-fg"
                                        >{member.name}</span
                                    >
                                    {#if member.is_office_admin}
                                        <span class="chip chip-accent"
                                            >Office admin</span
                                        >
                                    {/if}
                                    {#if !member.user_id}
                                        <span class="chip">No login</span>
                                    {/if}
                                    {#if member.is_active === false}
                                        <span class="chip">Inactive</span>
                                    {/if}
                                </div>
                                <div
                                    class="flex flex-wrap gap-x-3 text-xs text-fg-muted"
                                >
                                    {#if member.title}<span class="truncate"
                                            >{member.title}</span
                                        >{/if}
                                    {#if member.email}<span class="truncate"
                                            >{member.email}</span
                                        >{/if}
                                    {#if isSuperAdmin}
                                        <span class="truncate text-fg-faint"
                                            >{member.organization ??
                                                'No organization'}</span
                                        >
                                    {/if}
                                    {#if memberTeamNames(member).length > 0}
                                        <span class="truncate text-fg-faint"
                                            >{memberTeamNames(member).join(
                                                ', ',
                                            )}</span
                                        >
                                    {/if}
                                </div>
                            </div>
                            {#if member.can_manage}
                                <div class="flex shrink-0 items-center gap-1">
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
                                    <button
                                        type="button"
                                        aria-label={`Delete ${member.name}`}
                                        class="btn-danger"
                                        onclick={() => deleteMember(member)}
                                        >Delete</button
                                    >
                                </div>
                            {/if}
                        {/if}
                    </div>
                {/each}
            </div>
        </section>
    </div>
</AppShell>
