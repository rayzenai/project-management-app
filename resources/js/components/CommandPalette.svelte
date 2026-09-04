<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        Folder,
        LayoutGrid,
        Plus,
        Search,
        StickyNote,
        UserRound,
    } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import type { Component } from 'svelte';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import { palette } from '../lib/palette.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import Spinner from './Spinner.svelte';
    import StatusGlyph from './StatusGlyph.svelte';

    type ProjectResult = {
        id: number;
        slug: string;
        title: string;
        tasks_count: number;
    };
    type TaskResult = {
        id: number;
        slug: string;
        item_number: number | null;
        title: string;
        short_title: string | null;
        status: string | null;
        status_label: string | null;
        project: { slug: string; title: string } | null;
    };
    type TaskRef = {
        id: number;
        slug: string;
        title: string;
        project: { slug: string; title: string } | null;
    } | null;
    type NoteResult = {
        kind: 'task' | 'sticky';
        id: number;
        title: string | null;
        body: string;
        task: TaskRef;
    };
    type ContactResult = {
        id: number;
        name: string;
        role: string | null;
        organization: string | null;
        task: TaskRef;
    };
    type SearchResults = {
        projects: ProjectResult[];
        tasks: TaskResult[];
        notes: NoteResult[];
        contacts: ContactResult[];
    };

    type Icon = Component<{ class?: string }>;

    type PaletteItem =
        | { kind: 'action'; id: 'new-task'; label: string }
        | { kind: 'nav'; href: string; label: string; icon: Icon }
        | {
              kind: 'project';
              id: number;
              slug: string;
              title: string;
              meta: string;
          }
        | {
              kind: 'task';
              id: number;
              slug: string;
              title: string;
              item_number: number | null;
              status: string | null;
              meta: string;
          }
        | {
              kind: 'note';
              noteKind: 'task' | 'sticky';
              id: number;
              body: string;
              task: { id: number; slug: string } | null;
              meta: string;
          }
        | {
              kind: 'contact';
              id: number;
              name: string;
              meta: string;
              task: { id: number; slug: string } | null;
          };

    const EMPTY: SearchResults = {
        projects: [],
        tasks: [],
        notes: [],
        contacts: [],
    };

    const NAV: { label: string; href: string; icon: Icon }[] = [
        { label: 'Overview', href: '/workspace', icon: LayoutGrid },
        { label: 'My Workspace', href: '/workspace/my', icon: UserRound },
        { label: 'Projects', href: '/workspace/projects', icon: Folder },
    ];

    let query = $state('');
    let results = $state<SearchResults>(EMPTY);
    let loading = $state(false);
    let activeIndex = $state(0);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;
    let inputEl = $state<HTMLInputElement | null>(null);
    let wasOpen = false;

    const hasQuery = $derived(query.trim().length >= 2);

    // Groups never reorder; ranking happens within the server groups (server
    // order is trusted) and via substring filtering for the static Navigate group.
    const grouped = $derived.by<
        { label: string; items: PaletteItem[]; offset: number }[]
    >(() => {
        const q = query.trim();
        const lq = q.toLowerCase();
        const groups: { label: string; items: PaletteItem[] }[] = [];

        groups.push({
            label: 'Actions',
            items: [
                {
                    kind: 'action',
                    id: 'new-task',
                    label: q ? `New task "${q}"` : 'New task',
                },
            ],
        });

        const nav = q
            ? NAV.filter((n) => n.label.toLowerCase().includes(lq))
            : NAV;

        if (nav.length) {
            groups.push({
                label: 'Navigate',
                items: nav.map((n) => ({
                    kind: 'nav' as const,
                    href: n.href,
                    label: n.label,
                    icon: n.icon,
                })),
            });
        }

        if (q.length >= 2) {
            if (results.projects.length) {
                groups.push({
                    label: 'Projects',
                    items: results.projects.slice(0, 5).map((p) => ({
                        kind: 'project' as const,
                        id: p.id,
                        slug: p.slug,
                        title: p.title,
                        meta: `${p.tasks_count} tasks`,
                    })),
                });
            }

            if (results.tasks.length) {
                groups.push({
                    label: 'Tasks',
                    items: results.tasks.slice(0, 8).map((t) => ({
                        kind: 'task' as const,
                        id: t.id,
                        slug: t.slug,
                        title: t.title,
                        item_number: t.item_number,
                        status: t.status,
                        meta: [t.project?.title, t.status_label]
                            .filter(Boolean)
                            .join(' · '),
                    })),
                });
            }

            if (results.notes.length) {
                groups.push({
                    label: 'Notes',
                    items: results.notes.slice(0, 4).map((n) => ({
                        kind: 'note' as const,
                        noteKind: n.kind,
                        id: n.id,
                        body: n.body,
                        task: n.task
                            ? { id: n.task.id, slug: n.task.slug }
                            : null,
                        meta:
                            n.task?.title ??
                            (n.kind === 'sticky' ? 'Sticky note' : ''),
                    })),
                });
            }

            if (results.contacts.length) {
                groups.push({
                    label: 'Contacts',
                    items: results.contacts.slice(0, 4).map((c) => ({
                        kind: 'contact' as const,
                        id: c.id,
                        name: c.name,
                        meta: [c.role, c.organization]
                            .filter(Boolean)
                            .join(' · '),
                        task: c.task
                            ? { id: c.task.id, slug: c.task.slug }
                            : null,
                    })),
                });
            }
        }

        let offset = 0;

        return groups.map((group) => {
            const entry = { ...group, offset };
            offset += group.items.length;

            return entry;
        });
    });

    const flat = $derived<PaletteItem[]>(
        grouped.flatMap((group) => group.items),
    );

    $effect(() => {
        const open = palette.isOpen;
        untrack(() => {
            if (open && !wasOpen) {
                query = palette.initialQuery;
                activeIndex = 0;
                onInput();
                queueMicrotask(() => inputEl?.focus());
            }

            wasOpen = open;
        });
    });

    // Keep the virtual cursor in range when filtering shrinks the list.
    $effect(() => {
        if (activeIndex >= flat.length) {
            activeIndex = 0;
        }
    });

    $effect(() => {
        const idx = activeIndex;

        if (!palette.isOpen || typeof document === 'undefined') {
            return;
        }

        document
            .getElementById(`pal-item-${idx}`)
            ?.scrollIntoView({ block: 'nearest' });
    });

    function close() {
        palette.close();
        query = '';
        results = EMPTY;
        loading = false;
        activeIndex = 0;
        clearTimeout(timer);
    }

    function onInput() {
        clearTimeout(timer);
        const q = query.trim();

        if (q.length < 2) {
            results = EMPTY;
            loading = false;

            return;
        }

        loading = true;
        timer = setTimeout(() => runSearch(q), 200);
    }

    async function runSearch(q: string) {
        const mySeq = ++seq;

        try {
            const res = await fetch(
                `/workspace/search?q=${encodeURIComponent(q)}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );
            const body = await res.json();

            if (mySeq !== seq) {
                return;
            }

            results = (body.data ?? EMPTY) as SearchResults;
            activeIndex = 0;
        } catch {
            if (mySeq === seq) {
                results = EMPTY;
            }
        } finally {
            if (mySeq === seq) {
                loading = false;
            }
        }
    }

    function execute(item: PaletteItem) {
        const q = query.trim();
        close();

        switch (item.kind) {
            case 'action': {
                // Inherit the project you're viewing so "New task" doesn't re-ask it.
                const proj = (page.props as Record<string, unknown>).project as
                    { id?: number } | undefined;
                quickAdd.open(
                    typeof proj?.id === 'number'
                        ? { prefill: q, projectId: proj.id, lockProject: true }
                        : { prefill: q },
                );
                break;
            }
            case 'nav':
                router.visit(item.href);
                break;
            case 'project':
                router.visit(`/workspace/projects/${item.slug}`);
                break;
            case 'task':
                peek.open({ id: item.id, slug: item.slug });
                break;
            case 'note':
                if (item.noteKind === 'sticky') {
                    notesBoard.show({ noteId: item.id });
                } else if (item.task) {
                    peek.open({ id: item.task.id, slug: item.task.slug });
                }

                break;
            case 'contact':
                if (item.task) {
                    peek.open({ id: item.task.id, slug: item.task.slug });
                }

                break;
        }
    }

    function onKeydown(e: KeyboardEvent) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();

            if (flat.length) {
                activeIndex = (activeIndex + 1) % flat.length;
            }

            return;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();

            if (flat.length) {
                activeIndex = (activeIndex - 1 + flat.length) % flat.length;
            }

            return;
        }

        if (e.key === 'Home') {
            e.preventDefault();
            activeIndex = 0;

            return;
        }

        if (e.key === 'End') {
            e.preventDefault();
            activeIndex = Math.max(flat.length - 1, 0);

            return;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            const item = flat[activeIndex];

            if (item) {
                execute(item);
            }

            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            close();
        }
    }

    function itemKey(item: PaletteItem): string {
        switch (item.kind) {
            case 'action':
                return 'action-new-task';
            case 'nav':
                return `nav-${item.href}`;
            case 'note':
                return `note-${item.noteKind}-${item.id}`;
            default:
                return `${item.kind}-${item.id}`;
        }
    }
</script>

{#if palette.isOpen}
    <div
        class="fixed inset-0 z-[60] overflow-y-auto bg-black/40"
        onclick={close}
        role="presentation"
    >
        <!-- svelte-ignore a11y_click_events_have_key_events -->
        <div
            class="popover mx-auto mt-[12vh] w-[min(640px,92vw)] overflow-hidden p-0"
            onclick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            tabindex="-1"
        >
            <div
                class="flex h-12 items-center gap-2.5 border-b border-line px-4"
            >
                <Search class="h-4 w-4 shrink-0 text-fg-faint" />
                <input
                    bind:this={inputEl}
                    type="text"
                    bind:value={query}
                    oninput={onInput}
                    onkeydown={onKeydown}
                    placeholder="Search or jump to"
                    class="w-full bg-transparent text-[15px] text-fg outline-none placeholder:text-fg-faint"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-listbox"
                    aria-activedescendant={`pal-item-${activeIndex}`}
                    aria-autocomplete="list"
                    autocomplete="off"
                    spellcheck="false"
                />
                <kbd class="kbd">esc</kbd>
            </div>

            <div
                id="palette-listbox"
                role="listbox"
                aria-label="Results"
                class="max-h-[60vh] overflow-y-auto px-1 pb-1"
            >
                {#each grouped as group (group.label)}
                    <div class="col-head px-3 py-1.5">
                        {group.label}
                    </div>
                    {#each group.items as item, j (itemKey(item))}
                        {@const i = group.offset + j}
                        <!-- svelte-ignore a11y_click_events_have_key_events -->
                        <div
                            id={`pal-item-${i}`}
                            role="option"
                            aria-selected={i === activeIndex}
                            tabindex="-1"
                            class={`menu-item h-9 cursor-pointer ${
                                i === activeIndex ? 'bg-hover text-fg' : ''
                            }`}
                            onclick={() => execute(item)}
                            onmousemove={() => (activeIndex = i)}
                        >
                            {#if item.kind === 'action'}
                                <Plus
                                    class="h-3.5 w-3.5 shrink-0 text-accent"
                                />
                                <span class="min-w-0 flex-1 truncate text-fg"
                                    >{item.label}</span
                                >
                                <kbd class="kbd">{hasQuery ? '↩' : 'n'}</kbd>
                            {:else if item.kind === 'nav'}
                                {@const NavIcon = item.icon}
                                <NavIcon
                                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                />
                                <span class="min-w-0 flex-1 truncate"
                                    >{item.label}</span
                                >
                            {:else if item.kind === 'project'}
                                <Folder
                                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                />
                                <span class="min-w-0 flex-1 truncate"
                                    >{item.title}</span
                                >
                                <span
                                    class="shrink-0 text-xs text-fg-faint tabular-nums"
                                    >{item.meta}</span
                                >
                            {:else if item.kind === 'task'}
                                <StatusGlyph status={item.status} size={13} />
                                {#if item.item_number}
                                    <span
                                        class="shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                                        >{item.item_number}</span
                                    >
                                {/if}
                                <span class="min-w-0 flex-1 truncate"
                                    >{item.title}</span
                                >
                                {#if item.meta}
                                    <span class="shrink-0 text-xs text-fg-faint"
                                        >{item.meta}</span
                                    >
                                {/if}
                            {:else if item.kind === 'note'}
                                <StickyNote
                                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                />
                                <span class="min-w-0 flex-1 truncate"
                                    >{item.body}</span
                                >
                                {#if item.meta}
                                    <span class="shrink-0 text-xs text-fg-faint"
                                        >{item.meta}</span
                                    >
                                {/if}
                            {:else}
                                <UserRound
                                    class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                />
                                <span class="min-w-0 flex-1 truncate"
                                    >{item.name}</span
                                >
                                {#if item.meta}
                                    <span class="shrink-0 text-xs text-fg-faint"
                                        >{item.meta}</span
                                    >
                                {/if}
                            {/if}
                        </div>
                    {/each}
                {/each}
            </div>

            <div
                class="flex h-9 items-center gap-4 border-t border-line px-4 text-xs text-fg-faint"
            >
                <span class="flex items-center gap-1.5">
                    <kbd class="kbd">↑↓</kbd> navigate
                </span>
                <span class="flex items-center gap-1.5">
                    <kbd class="kbd">↩</kbd> open
                </span>
                <span class="flex items-center gap-1.5">
                    <kbd class="kbd">esc</kbd> close
                </span>
                {#if loading}
                    <span class="ml-auto flex items-center gap-1.5">
                        <Spinner size={12} label="Searching" /> Searching
                    </span>
                {/if}
            </div>
        </div>
    </div>
{/if}
