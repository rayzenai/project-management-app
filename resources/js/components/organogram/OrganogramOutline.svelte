<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        ChevronDown,
        ChevronRight,
        Ellipsis,
        GripVertical,
    } from '@lucide/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import { idsBelow, peopleCount } from '../../lib/structure';
    import type { Designation, OrgNode, OrgPerson } from '../../lib/structure';
    import Avatar from '../Avatar.svelte';
    import Popover from '../Popover.svelte';

    /**
     * The organogram as an outline to fold, unfold and rearrange: drag a row
     * by its handle onto someone, or onto "No one above", or pick "Reports
     * to" in its menu, where its designation is chosen too. Only managers
     * (whoever administers the organization) change it; everyone else reads.
     */
    let {
        tree,
        people,
        designations,
        canManage,
    }: {
        tree: OrgNode[];
        people: OrgPerson[];
        designations: Designation[];
        canManage: boolean;
    } = $props();

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

    function setDesignation(person: OrgPerson, designationId: number | null) {
        if (person.designation_id === designationId) {
            return;
        }

        router.put(
            `/workspace/members/${person.id}/designation`,
            { designation_id: designationId },
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
</script>

<svelte:window
    onpointermove={settleDrag}
    onkeydown={(e) => e.key === 'Escape' && settleDrag()}
/>

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
                triggerLabel={`Change ${person.name}'s designation or whom they report to`}
            >
                {#snippet trigger()}
                    <Ellipsis class="h-3.5 w-3.5" />
                {/snippet}
                <div class="flex w-64 flex-col gap-1 p-2">
                    <label class="label" for={`designation-${person.id}`}
                        >Designation</label
                    >
                    <select
                        id={`designation-${person.id}`}
                        class="input"
                        use:focusOnMount
                        value={person.designation_id ?? ''}
                        onchange={(e) =>
                            setDesignation(
                                person,
                                e.currentTarget.value
                                    ? Number(e.currentTarget.value)
                                    : null,
                            )}
                    >
                        <option value="">None</option>
                        {#each designations as designation (designation.id)}
                            <option value={designation.id}
                                >{designation.name}</option
                            >
                        {/each}
                    </select>
                    <label class="label mt-2" for={`reports-to-${person.id}`}
                        >Reports to</label
                    >
                    <select
                        id={`reports-to-${person.id}`}
                        class="input"
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

<section class="min-w-0" aria-label="Reporting lines">
    <div class="group-head justify-between border-t-0">
        <span>People <span class="section-count">{people.length}</span></span>
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
                    >Drag someone by the handle onto the person they report to,
                    or here for no one above. The menu at the end of each row
                    does the same.</span
                ><span class="sm:hidden"
                    >Use the menu at the end of a row to choose whom someone
                    reports to.</span
                >
            {/if}
        </div>
    {/if}
    {#if tree.length === 0}
        <p class="px-5 py-3 text-xs text-fg-muted lg:px-6">Nobody here yet.</p>
    {:else}
        <div role="tree" aria-label="Who reports to whom" class="mt-2">
            {#each tree as root (root.person.id)}
                {@render outlineRow(root, 0)}
            {/each}
        </div>
    {/if}
</section>

<style>
    .outline-indent {
        padding-left: calc(var(--depth, 0) * min(1.5rem, 5vw));
    }
</style>
