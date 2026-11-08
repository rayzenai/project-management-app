<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { designationsFor } from '../../lib/structure';
    import type { DesignationOption } from '../../lib/structure';
    import type { Id, OrganizationOption } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import Dialog from '../Dialog.svelte';

    /**
     * Invite many people at once: paste "Name, email" lines, see what
     * sending would do for each (the server's preview, never decided here),
     * choose a designation for the new ones, and send each a link to set a
     * password. Ticking the "never signed in" box lists those people too,
     * so what is sent is what is shown. The server builds the plan again
     * when it sends, and refuses more than 200 at once.
     */
    let {
        organizations,
        initialOrganizationId,
        canChooseOrganization = false,
        designationOptions = [],
        onclose,
    }: {
        /** Where people can be invited: the admin's own, or a super-admin's choices. */
        organizations: OrganizationOption[];
        initialOrganizationId: Id | null;
        /** A super-admin picks the organization; it is sent with every request. */
        canChooseOrganization?: boolean;
        designationOptions?: DesignationOption[];
        onclose: () => void;
    } = $props();

    type InviteStatus =
        | 'ready'
        | 'resend'
        | 'invited_recently'
        | 'needs_email'
        | 'needs_name'
        | 'already_here'
        | 'deactivated'
        | 'not_allowed';

    interface InviteRow {
        name: string;
        email: string | null;
        designation_id: Id | null;
        status: InviteStatus;
        member_id: Id | null;
    }

    const STATUS_WORDS: Record<InviteStatus, string> = {
        ready: 'Ready',
        resend: 'Will get a new link',
        invited_recently: 'Invited a moment ago',
        needs_email: 'Needs an email',
        needs_name: 'Needs a name',
        already_here: 'Already here',
        deactivated: 'Deactivated',
        not_allowed: "Can't invite",
    };

    const MAX_PEOPLE = 200;
    const PLACEHOLDER =
        'Deepak Karki, deepak.karki@example.gov.np\nSunita Rai <sunita.rai@example.gov.np>';

    // Seeded once: the page mounts the dialog behind an {#if}, so opening it
    // again starts afresh.
    function seedOrganization(): Id | null {
        return initialOrganizationId;
    }

    let organizationId = $state<Id | null>(seedOrganization());
    let text = $state('');
    let rows = $state<InviteRow[]>([]);
    let withoutLoginCount = $state(0);
    let includeWithoutLogin = $state(false);
    /** A preview is due or on its way: sending waits for it. */
    let checking = $state(true);
    let checkError = $state<string | null>(null);
    let sending = $state(false);
    let sendErrors = $state<Record<string, string>>({});
    /** The designation chosen for a new person, by lowercased email. */
    const chosenDesignations = new SvelteMap<string, Id | null>();
    let seq = 0;

    const organizationName = $derived(
        organizations.find((o) => o.id === organizationId)?.name ?? null,
    );
    const designations = $derived(
        designationsFor(designationOptions, organizationId),
    );
    const pastedLines = $derived(
        text.split(/\r?\n/).filter((line) => line.trim() !== '').length,
    );
    const willInvite = $derived(
        rows.filter((r) => r.status === 'ready' || r.status === 'resend')
            .length,
    );

    function isNewPerson(row: InviteRow): boolean {
        return row.status === 'ready' && row.member_id === null;
    }

    function emailKey(row: InviteRow): string {
        return (row.email ?? '').toLowerCase();
    }

    function designationName(id: Id | null): string | null {
        return designations.find((d) => d.id === id)?.name ?? null;
    }

    /** Laravel's XSRF cookie, which stays current after sign-in. */
    function csrfHeaders(): Record<string, string> {
        const cookie = document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='));

        return cookie
            ? {
                  'X-XSRF-TOKEN': decodeURIComponent(
                      cookie.slice('XSRF-TOKEN='.length),
                  ),
              }
            : {};
    }

    async function preview(
        body: { text: string; organization_id: Id; without_login: boolean },
        mine: number,
    ) {
        try {
            const response = await fetch('/workspace/invites/preview', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await response.json().catch(() => null);

            if (mine !== seq) {
                return;
            }

            if (!response.ok) {
                // Nothing listed that the text no longer says.
                rows = [];
                checkError =
                    response.status === 422 && json?.message
                        ? json.message
                        : response.status === 429
                          ? 'Checked too often. Wait a minute, then change the list again.'
                          : "Couldn't check the list. Try again.";

                return;
            }

            rows = json.rows as InviteRow[];
            withoutLoginCount = json.without_login_count as number;
            checkError = null;
        } catch {
            if (mine === seq) {
                rows = [];
                checkError = "Couldn't check the list. Try again.";
            }
        } finally {
            if (mine === seq) {
                checking = false;
            }
        }
    }

    // Preview 400 ms after the last change; a newer change drops an older answer.
    $effect(() => {
        const pasted = text;
        const organization = organizationId;
        const withoutLogin = includeWithoutLogin;
        const mine = ++seq;

        if (organization === null) {
            rows = [];
            withoutLoginCount = 0;
            checking = false;

            return;
        }

        checking = true;
        const timer = setTimeout(
            () =>
                void preview(
                    {
                        text: pasted,
                        organization_id: organization,
                        without_login: withoutLogin,
                    },
                    mine,
                ),
            400,
        );

        return () => clearTimeout(timer);
    });

    function chooseOrganization(value: string) {
        organizationId = value ? Number(value) : null;
        chosenDesignations.clear();
        includeWithoutLogin = false;
    }

    function send() {
        if (
            willInvite === 0 ||
            checking ||
            checkError !== null ||
            sending ||
            organizationId === null
        ) {
            return;
        }

        sending = true;
        sendErrors = {};
        router.post(
            '/workspace/invites',
            {
                ...(canChooseOrganization
                    ? { organization_id: organizationId }
                    : {}),
                people: rows
                    .filter(
                        (r) => r.status === 'ready' || r.status === 'resend',
                    )
                    .map((r) => ({
                        name: r.name,
                        email: r.email,
                        designation_id: isNewPerson(r)
                            ? (chosenDesignations.get(emailKey(r)) ?? null)
                            : null,
                    })),
            },
            {
                preserveScroll: true,
                onSuccess: () => onclose(),
                onError: (errors) => {
                    sendErrors = errors;
                },
                onFinish: () => {
                    sending = false;
                },
            },
        );
    }
</script>

<Dialog
    label={organizationName
        ? `Invite people to ${organizationName}`
        : 'Invite people'}
    width={680}
    center
    {onclose}
>
    <div class="shrink-0 px-5 pt-4 sm:px-6">
        <p class="text-fg-muted">
            Paste names and emails, one person a line. Each gets an email with a
            link to set a password.
        </p>
        {#if canChooseOrganization}
            <select
                class="input mt-3"
                aria-label="Organization"
                value={organizationId ?? ''}
                onchange={(e) => chooseOrganization(e.currentTarget.value)}
            >
                <option value="" disabled>Choose an organization</option>
                {#each organizations as organization (organization.id)}
                    <option value={organization.id}>{organization.name}</option>
                {/each}
            </select>
            {#if sendErrors.organization_id}
                <p class="mt-1 text-xs text-danger">
                    {sendErrors.organization_id}
                </p>
            {/if}
        {/if}
        <textarea
            bind:value={text}
            data-autofocus
            class="input mt-3 max-h-28 min-h-[96px] sm:max-h-40 resize-y font-mono leading-[21px] lg:text-[13px]"
            rows="4"
            aria-label="Paste names and emails, one person a line."
            placeholder={PLACEHOLDER}
            spellcheck="false"
            autocomplete="off"
        ></textarea>
        {#if pastedLines > MAX_PEOPLE}
            <p class="mt-1 text-xs text-fg-muted">
                Only the first {MAX_PEOPLE} people are read. Invite the rest next.
            </p>
        {/if}
    </div>

    {#if rows.length > 0}
        <ul
            class="mt-3 min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 sm:px-6"
            aria-label="People to invite"
            aria-busy={checking}
        >
            {#each rows as row, i (i)}
                <li
                    class="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-b border-line-soft py-2.5 last:border-b-0 sm:grid-cols-[24px_minmax(0,1fr)_160px_128px]"
                >
                    <Avatar name={row.name || row.email} size="md" />
                    <span class="min-w-0">
                        {#if row.name}
                            <span class="block truncate font-medium text-fg"
                                >{row.name}</span
                            >
                        {:else}
                            <span class="block truncate text-danger"
                                >{STATUS_WORDS.needs_name}</span
                            >
                        {/if}
                        {#if row.email}
                            <span
                                class="block truncate text-[13px] text-fg-muted"
                                >{row.email}</span
                            >
                        {:else}
                            <span class="block truncate text-[13px] text-danger"
                                >{STATUS_WORDS.needs_email}</span
                            >
                        {/if}
                    </span>
                    {#if isNewPerson(row) && designations.length > 0}
                        <select
                            class="input col-span-2 col-start-2 row-start-2 sm:col-span-1 sm:col-start-3 sm:row-start-1"
                            aria-label={`Designation of ${row.name}`}
                            value={chosenDesignations.get(emailKey(row)) ??
                                row.designation_id ??
                                ''}
                            onchange={(e) =>
                                chosenDesignations.set(
                                    emailKey(row),
                                    e.currentTarget.value
                                        ? Number(e.currentTarget.value)
                                        : null,
                                )}
                        >
                            <option value="">Designation</option>
                            {#each designations as designation (designation.id)}
                                <option value={designation.id}
                                    >{designation.name}</option
                                >
                            {/each}
                        </select>
                    {:else if row.member_id !== null && designationName(row.designation_id)}
                        <span
                            class="col-span-2 col-start-2 row-start-2 truncate text-[13px] text-fg-muted sm:col-span-1 sm:col-start-3 sm:row-start-1"
                            >{designationName(row.designation_id)}</span
                        >
                    {/if}
                    <span
                        class={`col-start-3 row-start-1 text-right text-[13px] sm:col-start-4 sm:text-left ${
                            row.status === 'needs_email' ||
                            row.status === 'needs_name'
                                ? 'text-danger'
                                : row.status === 'ready' ||
                                    row.status === 'resend'
                                  ? 'text-fg'
                                  : 'text-fg-muted'
                        }`}
                    >
                        {row.status === 'needs_email' ||
                        row.status === 'needs_name'
                            ? 'Skipped'
                            : STATUS_WORDS[row.status]}
                    </span>
                </li>
            {/each}
        </ul>
    {/if}

    <div class="shrink-0 px-5 pt-3 pb-5 sm:px-6">
        {#if withoutLoginCount > 0}
            <label
                class="flex cursor-pointer items-start gap-2.5 rounded-md bg-surface-alt px-3.5 py-3 text-fg"
            >
                <input
                    type="checkbox"
                    class="mt-1"
                    bind:checked={includeWithoutLogin}
                />
                <span>
                    {withoutLoginCount === 1
                        ? 'Also invite the 1 person in the organogram who has never signed in'
                        : `Also invite the ${withoutLoginCount} people in the organogram who have never signed in`}
                </span>
            </label>
        {/if}
        {#if checkError}
            <p class="mt-2 text-xs text-danger">{checkError}</p>
        {/if}
        {#if sendErrors.people}
            <p class="mt-2 text-xs text-danger">{sendErrors.people}</p>
        {/if}
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="flex-1 text-[13px] text-fg-muted"
                >Links work for 7 days.</span
            >
            <button type="button" class="btn-ghost" onclick={onclose}
                >Cancel</button
            >
            <button
                type="button"
                class="btn-primary"
                disabled={willInvite === 0 ||
                    checking ||
                    checkError !== null ||
                    sending}
                onclick={send}
                >{willInvite === 1
                    ? 'Send 1 invite'
                    : `Send ${willInvite} invites`}</button
            >
        </div>
    </div>
</Dialog>
