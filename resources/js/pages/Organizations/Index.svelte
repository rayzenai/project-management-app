<script lang="ts">
    import { inertia, router, useForm } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import type { Organization } from '../../lib/types';

    let { organizations }: { organizations: Organization[] } = $props();

    const uid = $props.id();
    let creating = $state(false);
    let renamingId = $state<number | null>(null);

    const createForm = useForm({ name: '' });
    const renameForm = useForm({ name: '' });

    function create(e: SubmitEvent) {
        e.preventDefault();
        createForm.post('/workspace/organizations', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                creating = false;
            },
        });
    }

    function startRename(organization: Organization) {
        renamingId = organization.id;
        renameForm.clearErrors();
        renameForm.name = organization.name;
    }

    function rename(e: SubmitEvent, organization: Organization) {
        e.preventDefault();
        renameForm.patch(`/workspace/organizations/${organization.id}`, {
            preserveScroll: true,
            onSuccess: () => (renamingId = null),
        });
    }

    function isEmpty(organization: Organization): boolean {
        return (
            (organization.members_count ?? 0) === 0 &&
            (organization.teams_count ?? 0) === 0 &&
            (organization.projects_count ?? 0) === 0
        );
    }

    function destroy(organization: Organization) {
        if (!confirm(`Delete "${organization.name}"?`)) {
            return;
        }

        router.delete(`/workspace/organizations/${organization.id}`, {
            preserveScroll: true,
        });
    }

    function count(
        n: number | undefined,
        one: string,
        many = `${one}s`,
    ): string {
        const value = n ?? 0;

        return `${value} ${value === 1 ? one : many}`;
    }
</script>

<svelte:head><title>Organizations · Workspace</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Organizations</span>
        </div>
        <button
            type="button"
            class={creating ? 'btn' : 'btn-primary'}
            aria-expanded={creating}
            onclick={() => (creating = !creating)}
        >
            {#if creating}Cancel{:else}<Plus class="h-3.5 w-3.5" />New
                organization{/if}
        </button>
    {/snippet}

    <div class="space-y-6">
        {#if creating}
            <form onsubmit={create} class="panel max-w-lg space-y-3 p-4">
                <h2 class="section-title">New organization</h2>
                <div class="flex flex-col gap-1">
                    <label class="label" for="{uid}-name">Name</label>
                    <input
                        id="{uid}-name"
                        type="text"
                        bind:value={createForm.name}
                        required
                        placeholder="Ministry of Health and Food Safety"
                        class="input"
                    />
                    {#if createForm.errors.name}
                        <p class="text-xs text-danger">
                            {createForm.errors.name}
                        </p>
                    {/if}
                </div>
                <p class="text-xs text-fg-muted">
                    Then add its office admin from the Team page: Add person,
                    choose this organization, tick Office admin.
                </p>
                <div class="flex justify-end gap-1.5">
                    <button
                        type="button"
                        class="btn-ghost"
                        onclick={() => (creating = false)}>Cancel</button
                    >
                    <button
                        type="submit"
                        class="btn-primary"
                        disabled={createForm.processing ||
                            !createForm.name.trim()}>Create</button
                    >
                </div>
            </form>
        {/if}

        <section>
            <h2 class="section-title mb-2">
                Organizations
                <span class="section-count">{organizations.length}</span>
            </h2>

            {#if organizations.length === 0}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    No organizations yet. Each office or ministry using the
                    workspace is one.
                </p>
            {/if}

            <div class="border-t border-line">
                {#each organizations as organization (organization.id)}
                    <div class="row min-h-12 px-2 py-2">
                        {#if renamingId === organization.id}
                            <form
                                onsubmit={(e) => rename(e, organization)}
                                class="flex min-w-0 flex-1 items-start gap-1.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <input
                                        type="text"
                                        aria-label="Organization name"
                                        bind:value={renameForm.name}
                                        required
                                        class="input"
                                    />
                                    {#if renameForm.errors.name}
                                        <p class="mt-1 text-xs text-danger">
                                            {renameForm.errors.name}
                                        </p>
                                    {/if}
                                </div>
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    onclick={() => (renamingId = null)}
                                    >Cancel</button
                                >
                                <button
                                    type="submit"
                                    class="btn-primary"
                                    disabled={renameForm.processing}
                                    >Save</button
                                >
                            </form>
                        {:else}
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium text-fg">
                                    {organization.name}
                                </div>
                                <div
                                    class="flex flex-wrap gap-x-3 text-xs text-fg-muted"
                                >
                                    <span class="font-mono tabular-nums"
                                        >{count(
                                            organization.members_count,
                                            'person',
                                            'people',
                                        )} · {count(
                                            organization.teams_count,
                                            'team',
                                        )} · {count(
                                            organization.projects_count,
                                            'project',
                                        )}</span
                                    >
                                    <span class="truncate">
                                        {(organization.office_admins ?? [])
                                            .length > 0
                                            ? `Office admin: ${(organization.office_admins ?? []).map((a) => a.name).join(', ')}`
                                            : 'No office admin yet'}
                                    </span>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <a
                                    href={`/workspace/team?organization=${organization.id}`}
                                    use:inertia
                                    class="btn-ghost">People and teams</a
                                >
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    onclick={() => startRename(organization)}
                                    >Rename</button
                                >
                                <button
                                    type="button"
                                    class="btn-danger"
                                    disabled={!isEmpty(organization)}
                                    title={isEmpty(organization)
                                        ? undefined
                                        : 'Move or delete its people, teams and projects first'}
                                    aria-label={`Delete ${organization.name}`}
                                    onclick={() => destroy(organization)}
                                    >Delete</button
                                >
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        </section>
    </div>
</AppShell>
