<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { peopleCount } from '../../lib/structure';
    import type { Designation } from '../../lib/structure';
    import type { Id } from '../../lib/types';

    /**
     * An organization's designations, for its managers: add one, rename it
     * or change its rank, or remove it (its holders keep no designation).
     */
    let {
        organizationId,
        designations,
    }: {
        organizationId: Id;
        designations: Designation[];
    } = $props();

    const addForm = useForm({ name: '', rank: 100 as number | null });
    const editForm = useForm({ name: '', rank: 100 as number | null });
    let editingDesignationId = $state<number | null>(null);

    function addDesignation(e: SubmitEvent) {
        e.preventDefault();

        if (!addForm.name.trim()) {
            return;
        }

        addForm.post(
            `/workspace/organizations/${organizationId}/designations`,
            {
                preserveScroll: true,
                onSuccess: () => addForm.reset(),
            },
        );
    }

    function startEdit(designation: Designation) {
        editingDesignationId = designation.id;
        editForm.clearErrors();
        editForm.name = designation.name;
        editForm.rank = designation.rank;
    }

    function saveDesignation(e: SubmitEvent, designation: Designation) {
        e.preventDefault();
        editForm.patch(`/workspace/designations/${designation.id}`, {
            preserveScroll: true,
            onSuccess: () => (editingDesignationId = null),
        });
    }

    function deleteDesignation(designation: Designation) {
        const holders = designation.people_count ?? 0;

        if (
            !confirm(
                holders > 0
                    ? `Remove ${designation.name}? ${peopleCount(holders)} will have no designation.`
                    : `Remove ${designation.name}?`,
            )
        ) {
            return;
        }

        router.delete(`/workspace/designations/${designation.id}`, {
            preserveScroll: true,
        });
    }
</script>

<section
    class="min-w-0 border-line px-5 py-5 lg:border-l lg:px-6"
    aria-label="Designations"
>
    <h2 class="section-title mb-1">
        Designations <span class="section-count">{designations.length}</span>
    </h2>
    <p class="mb-3 text-xs text-fg-muted">
        Rank orders them: smaller is more senior.
    </p>
    <div class="border-t border-line">
        {#each designations as designation (designation.id)}
            <div class="border-b border-line py-1.5">
                {#if editingDesignationId === designation.id}
                    <form
                        class="flex flex-col gap-2"
                        onsubmit={(e) => saveDesignation(e, designation)}
                    >
                        <div class="flex gap-1.5">
                            <input
                                class="input"
                                aria-label="Name"
                                maxlength="120"
                                required
                                bind:value={editForm.name}
                            />
                            <input
                                class="input w-20 font-mono"
                                type="number"
                                min="0"
                                max="32767"
                                aria-label="Rank"
                                bind:value={editForm.rank}
                            />
                        </div>
                        {#if editForm.errors.name || editForm.errors.rank}
                            <p class="text-xs text-danger">
                                {editForm.errors.name ?? editForm.errors.rank}
                            </p>
                        {/if}
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="btn-danger"
                                aria-label={`Remove ${designation.name}`}
                                onclick={() => deleteDesignation(designation)}
                                >Remove</button
                            >
                            <span class="flex-1"></span>
                            <button
                                type="button"
                                class="btn-ghost"
                                onclick={() => (editingDesignationId = null)}
                                >Cancel</button
                            >
                            <button
                                type="submit"
                                class="btn-primary"
                                disabled={editForm.processing ||
                                    !editForm.name.trim()}>Save</button
                            >
                        </div>
                    </form>
                {:else}
                    <div class="flex items-center gap-2">
                        <span
                            class="w-8 shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                            title="Rank">{designation.rank}</span
                        >
                        <span
                            class="min-w-0 flex-1 truncate text-[13px] text-fg"
                            >{designation.name}</span
                        >
                        <span
                            class="shrink-0 text-xs text-fg-faint tabular-nums"
                            title={`Held by ${peopleCount(
                                designation.people_count ?? 0,
                            )}`}>{designation.people_count ?? 0}</span
                        >
                        <button
                            type="button"
                            class="btn-ghost h-6 px-1.5"
                            onclick={() => startEdit(designation)}>Edit</button
                        >
                    </div>
                {/if}
            </div>
        {:else}
            <p class="border-b border-line py-2 text-xs text-fg-muted">
                None yet. Add the ones this organization uses.
            </p>
        {/each}
    </div>
    <form class="mt-3 flex flex-col gap-2" onsubmit={addDesignation}>
        <div class="flex gap-1.5">
            <input
                class="input"
                placeholder="New designation"
                aria-label="New designation"
                maxlength="120"
                bind:value={addForm.name}
            />
            <input
                class="input w-20 font-mono"
                type="number"
                min="0"
                max="32767"
                aria-label="Rank"
                title="Rank"
                bind:value={addForm.rank}
            />
        </div>
        {#if addForm.errors.name || addForm.errors.rank}
            <p class="text-xs text-danger">
                {addForm.errors.name ?? addForm.errors.rank}
            </p>
        {/if}
        <div class="flex justify-end">
            <button
                type="submit"
                class="btn"
                disabled={addForm.processing || !addForm.name.trim()}
                >Add</button
            >
        </div>
    </form>
</section>
