<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import {
        ChevronDown,
        ChevronRight,
        Ellipsis,
        GripVertical,
        ListTree,
        Network,
        Printer,
    } from '@lucide/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import Popover from '../../components/Popover.svelte';
    import { buildOrgTree, idsBelow, peopleCount } from '../../lib/structure';
    import type {
        Designation,
        Organogram,
        OrgNode,
        OrgPerson,
    } from '../../lib/structure';
    import type { OrganizationOption } from '../../lib/types';

    /**
     * The organogram: an outline to fold, unfold and rearrange (drag a row
     * by its handle onto someone, or pick "Reports to" in its menu), a
     * top-down chart, and a printout of that chart. Managers (whoever
     * administers the organization) also keep its designations here.
     */
    let {
        organogram,
        organizations = [],
    }: {
        organogram: Organogram | null;
        /** A super-admin's choice of organization; empty for anyone else. */
        organizations?: OrganizationOption[];
    } = $props();

    const tree = $derived(organogram ? buildOrgTree(organogram.people) : []);
    const people = $derived(organogram?.people ?? []);
    const canManage = $derived(organogram?.can_manage ?? false);
    const designations = $derived(organogram?.designations ?? []);

    /** Everyone who has people below them. */
    const managers = $derived.by(() => {
        const found: OrgNode[] = [];
        const walk = (node: OrgNode) => {
            if (node.children.length > 0) {
                found.push(node);
                node.children.forEach(walk);
            }
        };
        tree.forEach(walk);

        return found;
    });

    let view = $state<'outline' | 'chart'>('outline');
    const folded = new SvelteSet<number>();

    function toggleFold(id: number) {
        if (folded.has(id)) {
            folded.delete(id);
        } else {
            folded.add(id);
        }
    }

    function foldAll(fold: boolean) {
        folded.clear();

        if (fold) {
            for (const node of managers) {
                folded.add(node.person.id);
            }
        }
    }

    function pickOrganization(value: string) {
        router.get(
            '/workspace/organogram',
            value ? { organization: value } : {},
        );
    }

    function printChart() {
        window.print();
    }

    // ---- Reporting lines ----

    function moveTo(person: OrgPerson, bossId: number | null) {
        if (person.reports_to_id === bossId) {
            return;
        }

        router.put(
            `/workspace/members/${person.id}/reports-to`,
            { reports_to_id: bossId },
            { preserveScroll: true, preserveState: true },
        );
    }

    /** Whom a person may report to: anyone but themselves and their people. */
    function bossOptions(node: OrgNode): OrgPerson[] {
        const excluded = new Set([node.person.id, ...idsBelow(node)]);

        return people.filter((person) => !excluded.has(person.id));
    }

    let dragging = $state<OrgNode | null>(null);
    let dropTarget = $state<number | 'top' | null>(null);
    // Bumped by every start and end, so a start that ended before its
    // frame came round never marks anyone as dragged.
    let dragTicket = 0;

    function startDrag(event: DragEvent, node: OrgNode) {
        if (event.dataTransfer) {
            event.dataTransfer.setData('text/plain', String(node.person.id));
            event.dataTransfer.effectAllowed = 'move';

            // The person's name as the picture, not the whole row: a
            // full-width drag image is what Chrome on macOS chokes on.
            const name = (event.currentTarget as HTMLElement)
                .closest('[data-row]')
                ?.querySelector('[data-drag-image]');

            if (name instanceof HTMLElement) {
                event.dataTransfer.setDragImage(name, 12, 12);
            }
        }

        // Change nothing on the page until the drag is under way: Chrome on
        // macOS drops a drag whose page changes while it starts, and never
        // says so (no dragend), which left the outline stuck mid-drag.
        const ticket = ++dragTicket;

        requestAnimationFrame(() => {
            if (ticket === dragTicket) {
                dragging = node;
            }
        });
    }

    function endDrag() {
        dragTicket++;
        dragging = null;
        dropTarget = null;
    }

    // A live drag gets no pointer events, so one arriving while a person is
    // marked as dragged means the browser ended the drag without telling us.
    function settleDrag() {
        if (dragging) {
            endDrag();
        }
    }

    /** A row takes the drop unless it is the person or already their boss. */
    function accepts(target: number | 'top'): boolean {
        if (!dragging) {
            return false;
        }

        const current = dragging.person.reports_to_id;

        return target === 'top'
            ? current !== null
            : target !== dragging.person.id && target !== current;
    }

    function dragOver(event: DragEvent, target: number | 'top') {
        if (!accepts(target)) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        dropTarget = target;
    }

    function dragLeave(event: DragEvent, target: number | 'top') {
        const next = event.relatedTarget as Node | null;

        if (
            dropTarget === target &&
            !(event.currentTarget as HTMLElement).contains(next)
        ) {
            dropTarget = null;
        }
    }

    function drop(event: DragEvent, target: number | 'top') {
        event.preventDefault();
        const node = accepts(target) ? dragging : null;
        endDrag();

        if (node) {
            moveTo(node.person, target === 'top' ? null : target);
        }
    }

    function focusOnMount(node: HTMLElement) {
        queueMicrotask(() => node.focus());
    }

    // ---- Designations ----

    const addForm = useForm({ name: '', rank: 100 as number | null });
    const editForm = useForm({ name: '', rank: 100 as number | null });
    let editingDesignationId = $state<number | null>(null);

    function addDesignation(e: SubmitEvent) {
        e.preventDefault();

        if (!organogram || !addForm.name.trim()) {
            return;
        }

        addForm.post(
            `/workspace/organizations/${organogram.organization.id}/designations`,
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

    /** Moves the print-only chart to <body>, so printing can hide the rest. */
    function portal(node: HTMLElement) {
        document.body.appendChild(node);

        return {
            destroy() {
                node.remove();
            },
        };
    }
</script>

<svelte:window
    onpointermove={settleDrag}
    onkeydown={(e) => e.key === 'Escape' && settleDrag()}
/>

<svelte:head>
    <title
        >Organogram{organogram ? ` · ${organogram.organization.name}` : ''} · Workspace</title
    >
</svelte:head>

{#snippet outlineRow(node: OrgNode, depth: number)}
    {@const person = node.person}
    {@const open = !folded.has(person.id)}
    <div
        data-row
        role="treeitem"
        aria-selected="false"
        aria-level={depth + 1}
        aria-expanded={node.children.length > 0 ? open : undefined}
        tabindex="-1"
        class={`row min-h-11 gap-2 py-1.5 ${dropTarget === person.id ? 'bg-accent-soft' : ''} ${dragging?.person.id === person.id ? 'opacity-50' : ''}`}
        style={`--depth:${depth}`}
        ondragenter={(e) => dragOver(e, person.id)}
        ondragover={(e) => dragOver(e, person.id)}
        ondragleave={(e) => dragLeave(e, person.id)}
        ondrop={(e) => drop(e, person.id)}
    >
        <div class="outline-indent flex min-w-0 flex-1 items-center gap-1.5">
            {#if canManage}
                <span
                    draggable="true"
                    role="button"
                    tabindex="-1"
                    aria-label={`Drag ${person.name} onto the person they report to`}
                    title="Drag onto the person they report to"
                    class="hidden h-6 w-4 shrink-0 cursor-grab items-center justify-center text-fg-faint hover:text-fg sm:inline-flex"
                    ondragstart={(e) => startDrag(e, node)}
                    ondragend={endDrag}
                >
                    <GripVertical class="h-3.5 w-3.5" />
                </span>
            {/if}
            {#if node.children.length > 0}
                <button
                    type="button"
                    class="btn-icon h-6 w-6 shrink-0"
                    aria-label={open
                        ? `Fold ${person.name}'s people`
                        : `Unfold ${person.name}'s people`}
                    onclick={() => toggleFold(person.id)}
                >
                    {#if open}
                        <ChevronDown class="h-3.5 w-3.5" />
                    {:else}
                        <ChevronRight class="h-3.5 w-3.5" />
                    {/if}
                </button>
            {:else}
                <span class="h-6 w-6 shrink-0" aria-hidden="true"></span>
            {/if}
            <Avatar name={person.name} size="md" />
            <div
                class="flex min-w-0 flex-1 flex-col sm:flex-row sm:items-baseline sm:gap-2"
            >
                <span data-drag-image class="truncate font-medium text-fg"
                    >{person.name}</span
                >
                {#if person.designation}
                    <span class="truncate text-xs text-fg-muted"
                        >{person.designation}</span
                    >
                {/if}
            </div>
        </div>
        {#if node.below > 0}
            <span
                class="shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                title={`${peopleCount(node.below)} below`}
                >{peopleCount(node.below)}</span
            >
        {/if}
        {#if canManage}
            <Popover
                role="dialog"
                align="right"
                triggerClass="btn-icon h-6 w-6"
                triggerLabel={`Change whom ${person.name} reports to`}
            >
                {#snippet trigger()}
                    <Ellipsis class="h-3.5 w-3.5" />
                {/snippet}
                <div class="flex w-64 flex-col gap-1 p-2">
                    <label class="label" for={`reports-to-${person.id}`}
                        >Reports to</label
                    >
                    <select
                        id={`reports-to-${person.id}`}
                        class="input"
                        use:focusOnMount
                        value={person.reports_to_id ?? ''}
                        onchange={(e) =>
                            moveTo(
                                person,
                                e.currentTarget.value
                                    ? Number(e.currentTarget.value)
                                    : null,
                            )}
                    >
                        <option value="">No one above</option>
                        {#each bossOptions(node) as boss (boss.id)}
                            <option value={boss.id}
                                >{boss.name}{boss.designation
                                    ? `, ${boss.designation}`
                                    : ''}</option
                            >
                        {/each}
                    </select>
                </div>
            </Popover>
        {/if}
    </div>
    {#if dropTarget === person.id && dragging}
        <div
            class="outline-indent border-b border-line py-1.5 pr-5 lg:pr-6"
            style={`--depth:${depth + 1}`}
        >
            <div
                class="rounded-md border border-dashed border-accent px-3 py-1.5 text-xs text-accent"
            >
                {dragging.person.name} will report to {person.name}
            </div>
        </div>
    {/if}
    {#if open}
        {#each node.children as child (child.person.id)}
            {@render outlineRow(child, depth + 1)}
        {/each}
    {/if}
{/snippet}

{#snippet chartCard(person: OrgPerson, below: number, small: boolean)}
    <div
        class={`chart-card flex items-center gap-2 rounded-md border border-line bg-surface text-left ${small ? 'px-2 py-1' : 'px-2.5 py-1.5'}`}
    >
        <Avatar name={person.name} size={small ? 'sm' : 'md'} />
        <span class="flex min-w-0 flex-col">
            <span
                class={`truncate font-medium text-fg ${small ? 'text-[12.5px]' : 'text-[13px]'}`}
                >{person.name}</span
            >
            {#if person.designation || below > 0}
                <span
                    class={`truncate text-fg-muted ${small ? 'text-[11px]' : 'text-[11.5px]'}`}
                    >{[
                        person.designation,
                        below > 0 ? peopleCount(below) : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}</span
                >
            {/if}
        </span>
    </div>
{/snippet}

{#snippet chartBranch(node: OrgNode)}
    <li>
        {@render chartCard(node.person, node.below, false)}
        {#if node.children.length > 0}
            {#if node.children.every((child) => child.children.length === 0)}
                <div class="chart-stack">
                    {#each node.children as child (child.person.id)}
                        {@render chartCard(child.person, 0, true)}
                    {/each}
                </div>
            {:else}
                <ul>
                    {#each node.children as child (child.person.id)}
                        {@render chartBranch(child)}
                    {/each}
                </ul>
            {/if}
        {/if}
    </li>
{/snippet}

{#snippet chart()}
    <div class="chart">
        <ul>
            {#each tree as root (root.person.id)}
                {@render chartBranch(root)}
            {/each}
        </ul>
    </div>
{/snippet}

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            {#if organogram}
                <span class="hidden truncate text-fg-muted sm:inline"
                    >{organogram.organization.name}</span
                >
                <span class="hidden text-fg-faint sm:inline">/</span>
            {/if}
            <span class="truncate font-medium">Organogram</span>
        </div>
        <div class="flex items-center gap-1.5">
            {#if organizations.length > 0}
                <select
                    class="input h-7 w-auto max-w-40 py-0 sm:max-w-56"
                    aria-label="Organization"
                    value={organogram?.organization.id ?? ''}
                    onchange={(e) => pickOrganization(e.currentTarget.value)}
                >
                    <option value="" disabled>Organization</option>
                    {#each organizations as organization (organization.id)}
                        <option value={organization.id}
                            >{organization.name}</option
                        >
                    {/each}
                </select>
            {/if}
            {#if organogram}
                <div class="flex items-center" role="group" aria-label="View">
                    <button
                        type="button"
                        class={view === 'outline' ? 'btn' : 'btn-ghost'}
                        aria-pressed={view === 'outline'}
                        aria-label="Outline"
                        onclick={() => (view = 'outline')}
                        ><ListTree class="h-3.5 w-3.5" /><span
                            class="hidden sm:inline">Outline</span
                        ></button
                    >
                    <button
                        type="button"
                        class={view === 'chart' ? 'btn' : 'btn-ghost'}
                        aria-pressed={view === 'chart'}
                        aria-label="Chart"
                        onclick={() => (view = 'chart')}
                        ><Network class="h-3.5 w-3.5" /><span
                            class="hidden sm:inline">Chart</span
                        ></button
                    >
                </div>
                <button
                    type="button"
                    class="btn-ghost"
                    aria-label="Print the chart"
                    onclick={printChart}
                    ><Printer class="h-3.5 w-3.5" /><span
                        class="hidden sm:inline">Print</span
                    ></button
                >
            {/if}
        </div>
    {/snippet}

    {#if !organogram}
        <div class="px-5 py-6 lg:px-8 lg:py-8">
            <div class="panel max-w-md p-4">
                <h2 class="section-title mb-1">Choose an organization</h2>
                <p class="mb-3 text-xs text-fg-muted">
                    Its people, designations and reporting lines.
                </p>
                {#if organizations.length === 0}
                    <p class="text-xs text-fg-muted">
                        There are no organizations yet.
                    </p>
                {/if}
                <div class="flex flex-col">
                    {#each organizations as organization (organization.id)}
                        <button
                            type="button"
                            class="menu-item"
                            onclick={() =>
                                pickOrganization(String(organization.id))}
                            >{organization.name}</button
                        >
                    {/each}
                </div>
            </div>
        </div>
    {:else if view === 'chart'}
        <div class="px-5 py-6 lg:px-8 lg:py-8">
            {#if tree.length === 0}
                <p class="text-xs text-fg-muted">Nobody here yet.</p>
            {:else}
                {@render chart()}
            {/if}
        </div>
    {:else}
        <div
            class={`grid min-w-0 ${canManage ? 'lg:grid-cols-[minmax(0,1fr)_300px]' : ''}`}
        >
            <section class="min-w-0" aria-label="Reporting lines">
                <div class="group-head justify-between border-t-0">
                    <span
                        >People <span class="section-count"
                            >{people.length}</span
                        ></span
                    >
                    <span class="flex items-center gap-1">
                        <button
                            type="button"
                            class="btn-ghost h-6"
                            onclick={() => foldAll(false)}>Unfold all</button
                        >
                        <button
                            type="button"
                            class="btn-ghost h-6"
                            onclick={() => foldAll(true)}>Fold all</button
                        >
                    </span>
                </div>
                {#if canManage}
                    <!-- Always there, so starting a drag shifts no row. -->
                    <div
                        role="region"
                        aria-label="No one above"
                        class={`mx-5 mt-3 rounded-md border border-dashed px-3 py-2 text-xs lg:mx-6 ${dropTarget === 'top' ? 'border-accent bg-accent-soft text-accent' : 'border-line text-fg-muted'}`}
                        ondragenter={(e) => dragOver(e, 'top')}
                        ondragover={(e) => dragOver(e, 'top')}
                        ondragleave={(e) => dragLeave(e, 'top')}
                        ondrop={(e) => drop(e, 'top')}
                    >
                        {#if dragging}
                            {dragging.person.reports_to_id === null
                                ? `${dragging.person.name} reports to no one now. Drop them onto someone.`
                                : `No one above: drop here and ${dragging.person.name} reports to no one.`}
                        {:else}
                            <span class="hidden sm:inline"
                                >Drag someone by the handle onto the person they
                                report to, or here for no one above. The menu at
                                the end of each row does the same.</span
                            ><span class="sm:hidden"
                                >Use the menu at the end of a row to choose whom
                                someone reports to.</span
                            >
                        {/if}
                    </div>
                {/if}
                {#if tree.length === 0}
                    <p class="px-5 py-3 text-xs text-fg-muted lg:px-6">
                        Nobody here yet.
                    </p>
                {:else}
                    <div
                        role="tree"
                        aria-label="Who reports to whom"
                        class="mt-2"
                    >
                        {#each tree as root (root.person.id)}
                            {@render outlineRow(root, 0)}
                        {/each}
                    </div>
                {/if}
            </section>

            {#if canManage}
                <section
                    class="min-w-0 border-line px-5 py-5 lg:border-l lg:px-6"
                    aria-label="Designations"
                >
                    <h2 class="section-title mb-1">
                        Designations <span class="section-count"
                            >{designations.length}</span
                        >
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
                                        onsubmit={(e) =>
                                            saveDesignation(e, designation)}
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
                                                {editForm.errors.name ??
                                                    editForm.errors.rank}
                                            </p>
                                        {/if}
                                        <div class="flex items-center gap-1.5">
                                            <button
                                                type="button"
                                                class="btn-danger"
                                                aria-label={`Remove ${designation.name}`}
                                                onclick={() =>
                                                    deleteDesignation(
                                                        designation,
                                                    )}>Remove</button
                                            >
                                            <span class="flex-1"></span>
                                            <button
                                                type="button"
                                                class="btn-ghost"
                                                onclick={() =>
                                                    (editingDesignationId =
                                                        null)}>Cancel</button
                                            >
                                            <button
                                                type="submit"
                                                class="btn-primary"
                                                disabled={editForm.processing ||
                                                    !editForm.name.trim()}
                                                >Save</button
                                            >
                                        </div>
                                    </form>
                                {:else}
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="w-8 shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                                            title="Rank"
                                            >{designation.rank}</span
                                        >
                                        <span
                                            class="min-w-0 flex-1 truncate text-[13px] text-fg"
                                            >{designation.name}</span
                                        >
                                        <span
                                            class="shrink-0 text-xs text-fg-faint tabular-nums"
                                            title={`Held by ${peopleCount(
                                                designation.people_count ?? 0,
                                            )}`}
                                            >{designation.people_count ??
                                                0}</span
                                        >
                                        <button
                                            type="button"
                                            class="btn-ghost h-6 px-1.5"
                                            onclick={() =>
                                                startEdit(designation)}
                                            >Edit</button
                                        >
                                    </div>
                                {/if}
                            </div>
                        {:else}
                            <p
                                class="border-b border-line py-2 text-xs text-fg-muted"
                            >
                                None yet. Add the ones this organization uses.
                            </p>
                        {/each}
                    </div>
                    <form
                        class="mt-3 flex flex-col gap-2"
                        onsubmit={addDesignation}
                    >
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
                                disabled={addForm.processing ||
                                    !addForm.name.trim()}>Add</button
                            >
                        </div>
                    </form>
                </section>
            {/if}
        </div>
    {/if}
</AppShell>

{#if organogram && tree.length > 0}
    <div class="organogram-print" use:portal aria-hidden="true">
        <h1 class="print-title">
            {organogram.organization.name}: organogram
        </h1>
        {@render chart()}
    </div>
{/if}

<style>
    .outline-indent {
        padding-left: calc(var(--depth, 0) * min(1.5rem, 5vw));
    }

    /* The chart: each person above the people who report to them. A
       manager whose people have nobody under them stacks them, so wide
       teams stay narrow. */
    .chart {
        overflow-x: auto;
        padding-bottom: 8px;
    }
    .chart ul {
        position: relative;
        display: flex;
        justify-content: center;
        margin: 0;
        padding: 16px 0 0;
    }
    .chart > ul {
        padding-top: 0;
        width: max-content;
        min-width: 100%;
    }
    .chart li {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 16px 6px 0;
        list-style: none;
    }
    .chart > ul > li,
    .chart li:only-child {
        padding-top: 0;
    }
    .chart li::before,
    .chart li::after {
        content: '';
        position: absolute;
        top: 0;
        right: 50%;
        width: 50%;
        height: 16px;
        border-top: 1px solid var(--ws-line);
    }
    .chart li::after {
        right: auto;
        left: 50%;
        border-left: 1px solid var(--ws-line);
    }
    .chart > ul > li::before,
    .chart > ul > li::after,
    .chart li:only-child::before,
    .chart li:only-child::after {
        display: none;
    }
    .chart li:first-child::before,
    .chart li:last-child::after {
        border: 0 none;
    }
    .chart li:last-child::before {
        border-right: 1px solid var(--ws-line);
        border-radius: 0 6px 0 0;
    }
    .chart li:first-child::after {
        border-radius: 6px 0 0 0;
    }
    .chart ul ul::before {
        content: '';
        position: absolute;
        top: 0;
        left: 50%;
        height: 16px;
        border-left: 1px solid var(--ws-line);
    }
    .chart :global(.chart-card) {
        max-width: 220px;
    }
    .chart-stack {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: 10px;
        padding-left: 12px;
        border-left: 1px solid var(--ws-line);
    }

    .organogram-print {
        display: none;
    }

    @media print {
        @page {
            size: landscape;
            margin: 12mm;
        }

        :global(body > *:not(.organogram-print)) {
            display: none !important;
        }

        :global(body) {
            background: #fff !important;
        }

        .organogram-print {
            display: block;
            color: #111;
            print-color-adjust: exact;
        }

        .print-title {
            margin: 0 0 16px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }

        .organogram-print .chart {
            overflow: visible;
        }

        .organogram-print :global(.chart-card) {
            border-color: #bbb;
            background: #fff;
            color: #111;
            break-inside: avoid;
        }

        .organogram-print :global(.chart-card *) {
            color: #111;
        }

        .organogram-print li::before,
        .organogram-print li::after,
        .organogram-print ul ul::before,
        .organogram-print .chart-stack {
            border-color: #999;
        }
    }
</style>
