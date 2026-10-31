<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Plus } from '@lucide/svelte';
    import { tick } from 'svelte';
    import { designationsFor } from '../../lib/structure';
    import type { DesignationOption } from '../../lib/structure';
    import type { Id } from '../../lib/types';

    /**
     * The person form's designation, and a way to add the one it needs
     * without leaving the form: a name and a rank, added to the
     * organization's list (the same list the organogram keeps) and chosen.
     * Plain inputs, not a form: it sits inside the person form.
     */
    let {
        id,
        value,
        organizationId,
        options,
        canAdd = false,
        error = undefined,
        onchange,
    }: {
        id: string;
        value: Id | null;
        organizationId: Id | null;
        options: DesignationOption[];
        /** The viewer may add to this organization's designations. */
        canAdd?: boolean;
        error?: string;
        onchange: (designationId: Id | null) => void;
    } = $props();

    let adding = $state(false);
    let name = $state('');
    let rank = $state<number | null>(null);
    let nameInput = $state<HTMLInputElement | null>(null);
    let saving = $state(false);
    let addError = $state<string | null>(null);

    const choices = $derived(designationsFor(options, organizationId));

    async function open() {
        adding = true;
        name = '';
        rank = null;
        addError = null;
        await tick();
        nameInput?.focus();
    }

    function add() {
        const wanted = name.trim();

        if (!wanted || organizationId === null || saving) {
            return;
        }

        saving = true;
        addError = null;
        router.post(
            `/workspace/organizations/${organizationId}/designations`,
            { name: wanted, rank: typeof rank === 'number' ? rank : null },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const fresh = (page.props.designationOptions ??
                        []) as DesignationOption[];
                    const made = designationsFor(fresh, organizationId).find(
                        (option) =>
                            option.name.toLowerCase() === wanted.toLowerCase(),
                    );

                    if (made) {
                        onchange(made.id);
                    }

                    adding = false;
                },
                onError: (errors) => {
                    addError = errors.name ?? errors.rank ?? "Couldn't add it.";
                },
                onFinish: () => (saving = false),
            },
        );
    }

    function onKeydown(e: KeyboardEvent) {
        if (e.key === 'Enter') {
            // Inside the person form: Enter adds this, never saves the person.
            e.preventDefault();
            add();
        } else if (e.key === 'Escape') {
            e.stopPropagation();
            adding = false;
        }
    }
</script>

<div class="flex flex-col gap-1">
    <div class="flex items-center justify-between gap-2">
        <label class="label" for={`${id}-designation`}>Designation</label>
        {#if canAdd && organizationId !== null && !adding}
            <button
                type="button"
                class="btn-ghost h-5 px-1 text-xs"
                onclick={open}><Plus class="h-3 w-3" />New</button
            >
        {/if}
    </div>
    <select
        id={`${id}-designation`}
        class="input"
        disabled={organizationId === null}
        value={value ?? ''}
        onchange={(e) =>
            onchange(
                e.currentTarget.value ? Number(e.currentTarget.value) : null,
            )}
    >
        <option value=""
            >{organizationId === null
                ? 'Choose an organization first'
                : 'None'}</option
        >
        {#each choices as designation (designation.id)}
            <option value={designation.id}>{designation.name}</option>
        {/each}
    </select>
    {#if adding}
        <div class="flex gap-1.5">
            <input
                class="input min-w-0 flex-1"
                placeholder="New designation"
                aria-label="New designation"
                maxlength="120"
                bind:this={nameInput}
                bind:value={name}
                onkeydown={onKeydown}
            />
            <input
                class="input w-16 font-mono"
                type="number"
                min="0"
                max="32767"
                placeholder="100"
                aria-label="Rank: smaller is more senior"
                title="Rank: smaller is more senior"
                bind:value={rank}
                onkeydown={onKeydown}
            />
        </div>
        <div class="flex items-center justify-end gap-1.5">
            <button
                type="button"
                class="btn-ghost"
                onclick={() => (adding = false)}>Cancel</button
            >
            <button
                type="button"
                class="btn-primary"
                disabled={saving || !name.trim()}
                onclick={add}>Add</button
            >
        </div>
        {#if addError}
            <p class="text-xs text-danger">{addError}</p>
        {/if}
    {/if}
    {#if error}
        <p class="text-xs text-danger">{error}</p>
    {/if}
</div>
