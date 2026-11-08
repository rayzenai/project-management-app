<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { X } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import type {
        FoundPerson,
        GitRepository,
        RepositoryAccess,
        RepositoryGrant,
    } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import PersonSearch from '../PersonSearch.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * Who may pull and push a linked repository on the forge, under its row
     * on the Code tab: its maintainers, whose access follows their role, and
     * the people given read or write access, whom whoever runs the project
     * adds, changes and removes here. Every change goes through the server,
     * which answers with a flash; the list is then asked for again.
     */
    let {
        repository,
        url,
        id,
    }: {
        repository: GitRepository;
        /** The repository's access route. */
        url: string;
        /** The panel's id, for the toggle's aria-controls. */
        id?: string;
    } = $props();

    type Permission = RepositoryGrant['permission'];

    const WAITING = "Hasn't signed in to SDOC Git yet";
    const NO_ACCESS =
        'No access now: deactivated or no longer in this organization or team';

    let access = $state<RepositoryAccess | null>(null);
    let failed = $state(false);
    let saving = $state(false);
    let error = $state<string | null>(null);
    let seq = 0;

    let permission = $state<Permission>('write');

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    async function load(address: string): Promise<void> {
        const mine = ++seq;
        failed = false;

        try {
            const response = await fetch(address, {
                headers,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as { data: RepositoryAccess };

            if (mine === seq) {
                access = json.data;
            }
        } catch {
            if (mine === seq) {
                failed = true;
            }
        }
    }

    $effect(() => {
        const address = url;
        untrack(() => void load(address));
    });

    /**
     * One change at a time. Nothing on the page changes but the flash, so
     * only that is reloaded; the list is asked for again either way, and
     * the controls stay locked until it is back, so a grant just removed
     * can't be removed twice. `undo` runs whenever the change didn't save:
     * refused, forbidden, an expired page, no network or a cancelled visit.
     */
    function visit(after?: () => void, undo?: () => void) {
        saving = true;
        error = null;
        let saved = false;

        return {
            only: ['flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved = true;
                after?.();
            },
            onError: (errors: Record<string, string>) => {
                error =
                    errors.member_id ??
                    errors.permission ??
                    errors.__global ??
                    "That change didn't save.";
            },
            onFinish: () => {
                if (!saved) {
                    undo?.();
                }

                void load(url).finally(() => (saving = false));
            },
        };
    }

    function add(person: FoundPerson) {
        router.post(url, { member_id: person.id, permission }, visit());
    }

    /** A change that didn't save puts the select back as it was. */
    function change(grant: RepositoryGrant, select: HTMLSelectElement) {
        router.patch(
            `${url}/${grant.id}`,
            { permission: select.value },
            visit(undefined, () => (select.value = grant.permission)),
        );
    }

    function remove(grant: RepositoryGrant) {
        router.delete(`${url}/${grant.id}`, visit());
    }
</script>

{#snippet person(name: string, designation: string | null, note: string | null)}
    <Avatar {name} size="md" />
    <div class="min-w-0 flex-1 leading-tight">
        <div class="truncate font-medium text-fg">{name}</div>
        {#if designation}
            <div class="truncate text-xs text-fg-muted">{designation}</div>
        {/if}
        {#if note}
            <div class="text-xs text-warn">{note}</div>
        {/if}
    </div>
{/snippet}

<section
    {id}
    class="mt-2 rounded-md border border-line px-3 py-2.5"
    aria-label={`Who can reach ${repository.full_name}`}
>
    {#if failed}
        <div class="mb-2">
            <p class="text-xs text-danger">Access didn't load.</p>
            <button
                type="button"
                class="btn-ghost mt-1 -ml-2"
                onclick={() => void load(url)}>Try again</button
            >
        </div>
    {/if}

    {#if access === null}
        {#if !failed}
            <p class="flex items-center gap-2 text-xs text-fg-muted">
                <Spinner size={14} />Loading
            </p>
        {/if}
    {:else}
        <h3 class="text-xs font-medium text-fg-muted">Maintainers</h3>
        {#if access.maintainers.length === 0}
            <p class="mt-0.5 text-xs text-fg-faint">
                None: the project has no manager.
            </p>
        {:else}
            <ul>
                {#each access.maintainers as maintainer (maintainer.id)}
                    <li class="flex min-h-10 items-center gap-2.5 py-1">
                        {@render person(
                            maintainer.name,
                            // The role first: on a phone the title gives way.
                            [
                                maintainer.role === 'manager'
                                    ? 'Manager'
                                    : 'Program manager',
                                maintainer.designation,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                            maintainer.waiting ? WAITING : null,
                        )}
                        <span class="shrink-0 text-xs text-fg-muted"
                            >Manages the repository</span
                        >
                    </li>
                {/each}
            </ul>
        {/if}

        <h3 class="mt-3 text-xs font-medium text-fg-muted">
            People with access <span class="section-count"
                >{access.grants.length}</span
            >
        </h3>
        {#if access.grants.length === 0}
            <p class="mt-0.5 text-xs text-fg-faint">Nobody else yet.</p>
        {:else}
            <ul>
                {#each access.grants as grant (grant.id)}
                    <li class="flex min-h-10 items-center gap-2.5 py-1">
                        {@render person(
                            grant.member.name,
                            grant.member.designation,
                            !grant.effective
                                ? NO_ACCESS
                                : grant.waiting
                                  ? WAITING
                                  : null,
                        )}
                        <select
                            class="input h-7 w-auto shrink-0 py-0"
                            aria-label={`${grant.member.name}'s access`}
                            value={grant.permission}
                            disabled={saving || !grant.effective}
                            onchange={(e) => change(grant, e.currentTarget)}
                        >
                            <option value="read">Read</option>
                            <option value="write">Write</option>
                        </select>
                        <button
                            type="button"
                            class="btn-icon shrink-0 disabled:opacity-50"
                            title="Remove access"
                            aria-label={`Remove ${grant.member.name}'s access`}
                            disabled={saving}
                            onclick={() => remove(grant)}
                            ><X class="h-3.5 w-3.5" /></button
                        >
                    </li>
                {/each}
            </ul>
        {/if}

        <div class="mt-3">
            <PersonSearch
                url={`${url}/candidates`}
                label="Add someone"
                placeholder="Name or email"
                empty="Nobody matches who can be given access."
                disabled={saving}
                onpick={add}
            >
                {#snippet beside()}
                    <select
                        class="input w-auto shrink-0"
                        aria-label="Access to give"
                        bind:value={permission}
                    >
                        <option value="read">Read</option>
                        <option value="write">Write</option>
                    </select>
                {/snippet}
            </PersonSearch>
        </div>

        {#if error}
            <p role="alert" class="mt-2 text-xs text-danger">{error}</p>
        {/if}

        <p class="mt-3 text-xs text-fg-faint">
            Organizational admins and super-admins reach every repository.
        </p>
    {/if}
</section>
