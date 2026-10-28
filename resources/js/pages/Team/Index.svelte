<script lang="ts">
    import { page, router, useForm } from '@inertiajs/svelte';
    import { Copy, RefreshCw } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import { writeToClipboard } from '../../lib/clipboard';
    import { toast } from '../../lib/toast.svelte';
    import type { Member, OrganizationOption, Team } from '../../lib/types';

    let {
        teams,
        members,
        organizations = [],
        organizationFilter = null,
        officeAdminOrganization = null,
        canCreateMember = false,
    }: {
        teams: Team[];
        members: Member[];
        organizations?: OrganizationOption[];
        organizationFilter?: number | null;
        officeAdminOrganization?: OrganizationOption | null;
        canCreateMember?: boolean;
    } = $props();

    const isSuperAdmin = $derived<boolean>(
        (page.props as any).isSuperAdmin ?? false,
    );

    /** The organization new people land in when it is not chosen. */
    const defaultOrganizationId = $derived(
        organizationFilter ??
            (organizations.length === 1 ? organizations[0].id : null),
    );

    function filterByOrganization(value: string) {
        router.get('/workspace/team', value ? { organization: value } : {}, {
            preserveScroll: true,
        });
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

    // The projects a person works on: each project's team carries its name.
    const memberTeamNames = $derived((member: Member) =>
        teams
            .filter((t) => (member.team_ids ?? []).includes(t.id))
            .map((t) => t.name),
    );

    const managesPeople = $derived(isSuperAdmin || canCreateMember);
</script>

<svelte:head><title>People · Workspace</title></svelte:head>

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
            <span class="truncate font-medium">People</span>
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
