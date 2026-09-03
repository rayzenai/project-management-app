<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { formatDate } from '../lib/format';
    import { peek } from '../lib/peek.svelte';
    import type { Priority, TaskPreview } from '../lib/types';
    import AssigneeStack from './AssigneeStack.svelte';
    import CompleteCheckbox from './CompleteCheckbox.svelte';
    import DateChip from './DateChip.svelte';
    import PriorityFlag from './PriorityFlag.svelte';
    import StatusChip from './StatusChip.svelte';

    const NOTE_TYPES: { value: string; label: string }[] = [
        { value: 'general', label: 'General note' },
        { value: 'action_taken', label: 'Action taken' },
        { value: 'meeting', label: 'Meeting' },
        { value: 'blocker', label: 'Blocker' },
        { value: 'milestone', label: 'Milestone' },
        { value: 'decision', label: 'Decision' },
    ];

    const cache = new SvelteMap<number, TaskPreview>();

    let preview = $state<TaskPreview | null>(null);
    let loadFailed = $state(false);
    let panel = $state<HTMLElement | null>(null);
    let seq = 0;

    let editingTitle = $state(false);
    let titleDraft = $state('');
    let editingDescription = $state(false);
    let descriptionDraft = $state('');
    let progressDraft = $state(0);
    let subtaskDraft = $state('');
    let pendingSubtaskIds = new SvelteSet<number>();
    let editingSubtaskId = $state<number | null>(null);
    let subtaskEditDraft = $state('');
    let tempSubtaskSeq = 0;
    let noteDraft = $state('');
    let noteType = $state('general');
    let showContactForm = $state(false);
    let contactDraft = $state({
        name: '',
        role: '',
        organization: '',
        phone: '',
        email: '',
    });
    let showHistory = $state(false);

    let openPathname = '';

    const target = $derived(peek.target);
    const task = $derived(preview?.task ?? null);
    const projectSlug = $derived(task?.project?.slug ?? '');
    const doneSubtasks = $derived(
        preview?.subtasks.filter((s) => s.is_done).length ?? 0,
    );

    async function load(
        id: number,
        { background = false }: { background?: boolean } = {},
    ) {
        const mySeq = ++seq;

        if (!background) {
            loadFailed = false;
        }

        try {
            const response = await fetch(`/workspace/tasks/${id}/preview`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as { data: TaskPreview };

            if (mySeq !== seq || peek.target?.id !== id) {
                return;
            }

            cache.set(id, json.data);
            preview = json.data;
            progressDraft = json.data.task.progress;
        } catch {
            if (mySeq !== seq || peek.target?.id !== id) {
                return;
            }

            if (!preview) {
                loadFailed = true;
            }
        }
    }

    function revalidate() {
        if (target) {
            void load(target.id, { background: true });
        }
    }

    // Re-runs only when the open target changes — NOT when `cache` mutates.
    // Reading `cache.get()` here would subscribe the effect to the cache key
    // that `load()` writes to, creating an infinite refetch loop that also
    // wiped transient UI state (open drafts, expanded history) on every cycle.
    $effect(() => {
        const opened = target;

        if (!opened) {
            return;
        }

        untrack(() => {
            openPathname = window.location.pathname;
            const cached = cache.get(opened.id) ?? null;
            preview = cached;
            progressDraft = cached?.task.progress ?? 0;
            editingTitle =
                editingDescription =
                showContactForm =
                showHistory =
                    false;
            subtaskDraft = noteDraft = '';
            editingSubtaskId = null;
            pendingSubtaskIds.clear();
            void load(opened.id, { background: cached !== null });
        });

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        queueMicrotask(() => panel?.focus());

        return () => {
            document.body.style.overflow = previousOverflow;
        };
    });

    // Inertia rewrites the URL after every visit; re-assert ?task= while open,
    // and close the peek when navigation actually changed the page.
    $effect(() => {
        void page.url;

        if (!peek.target) {
            return;
        }

        if (
            new URL(page.url, window.location.origin).pathname !== openPathname
        ) {
            peek.close();
        } else {
            peek.syncUrl();
        }
    });

    function patchTask(
        payload: Record<string, string | number | boolean | null>,
        onSuccess?: () => void,
    ) {
        if (!task) {
            return;
        }

        router.patch(
            `/workspace/projects/${projectSlug}/tasks/${task.slug}`,
            payload,
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    onSuccess?.();
                    revalidate();
                },
            },
        );
    }

    function applyLocal(patch: Partial<TaskPreview['task']>) {
        if (preview) {
            preview.task = { ...preview.task, ...patch };
        }
    }

    function saveTitle() {
        if (!task || !editingTitle) {
            return;
        }

        editingTitle = false;
        const next = titleDraft.trim();

        if (next === '' || next === task.title) {
            return;
        }

        applyLocal({ title: next });
        patchTask({ title: next });
    }

    function saveDescription() {
        if (!task || !editingDescription) {
            return;
        }

        editingDescription = false;

        if (descriptionDraft === (task.description ?? '')) {
            return;
        }

        applyLocal({ description: descriptionDraft });
        patchTask({ description: descriptionDraft });
    }

    function saveProgress() {
        if (!task || progressDraft === task.progress) {
            return;
        }

        applyLocal({ progress: progressDraft });
        patchTask({ progress: progressDraft });
    }

    function toggleSubtask(id: number, isDone: boolean) {
        if (preview) {
            preview.subtasks = preview.subtasks.map((s) =>
                s.id === id ? { ...s, is_done: isDone } : s,
            );
        }

        router.patch(
            `/workspace/subtasks/${id}`,
            { is_done: isDone },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: revalidate,
            },
        );
    }

    function addSubtask() {
        const body = subtaskDraft.trim();

        if (!task || body === '' || !preview) {
            return;
        }

        subtaskDraft = '';

        // Optimistically render the subtask with a spinner. The temp row uses a
        // negative id; revalidate() reloads the real list and replaces it.
        const tempId = -++tempSubtaskSeq;
        pendingSubtaskIds.add(tempId);
        preview.subtasks = [
            ...preview.subtasks,
            {
                id: tempId,
                task_id: task.id,
                user_id: 0,
                body,
                is_done: false,
                position: preview.subtasks.length,
            },
        ];

        router.post(
            `/workspace/tasks/${task.id}/subtasks`,
            { body },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: revalidate,
                onError: () => {
                    if (preview) {
                        preview.subtasks = preview.subtasks.filter(
                            (s) => s.id !== tempId,
                        );
                    }

                    subtaskDraft = body;
                },
                onFinish: () => {
                    pendingSubtaskIds.delete(tempId);
                },
            },
        );
    }

    function startEditSubtask(id: number, body: string) {
        if (id < 0) {
            return;
        }

        editingSubtaskId = id;
        subtaskEditDraft = body;
    }

    function saveSubtaskEdit(id: number, original: string) {
        if (editingSubtaskId !== id) {
            return;
        }

        editingSubtaskId = null;
        const body = subtaskEditDraft.trim();

        if (body === '' || body === original) {
            return;
        }

        if (preview) {
            preview.subtasks = preview.subtasks.map((s) =>
                s.id === id ? { ...s, body } : s,
            );
        }

        router.patch(
            `/workspace/subtasks/${id}`,
            { body },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: revalidate,
            },
        );
    }

    function deleteSubtask(id: number) {
        if (preview) {
            preview.subtasks = preview.subtasks.filter((s) => s.id !== id);
        }

        router.delete(`/workspace/subtasks/${id}`, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: revalidate,
        });
    }

    function addNote() {
        if (!task || noteDraft.trim() === '') {
            return;
        }

        const body = noteDraft.trim();
        noteDraft = '';
        router.post(
            `/workspace/tasks/${task.id}/notes`,
            { body, type: noteType },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: revalidate,
            },
        );
    }

    function deleteNote(id: number) {
        if (preview) {
            preview.notes = preview.notes.filter((n) => n.id !== id);
        }

        router.delete(`/workspace/notes/${id}`, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: revalidate,
        });
    }

    function addContact() {
        if (!task || contactDraft.name.trim() === '') {
            return;
        }

        const payload = { ...contactDraft };
        contactDraft = {
            name: '',
            role: '',
            organization: '',
            phone: '',
            email: '',
        };
        showContactForm = false;
        router.post(`/workspace/tasks/${task.id}/contacts`, payload, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: revalidate,
        });
    }

    function onPanelKeydown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            peek.close();

            return;
        }

        if (event.key !== 'Tab' || !panel) {
            return;
        }

        const focusables = Array.from(
            panel.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), input, textarea, select, [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((el) => el.offsetParent !== null);

        if (focusables.length === 0) {
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
</script>

{#if target}
    <div
        aria-hidden="true"
        class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm"
    ></div>
    <div
        bind:this={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby="peek-title"
        aria-busy={!preview && !loadFailed}
        tabindex="-1"
        class="bg-surface fixed inset-y-0 right-0 z-50 flex w-full max-w-[540px] flex-col border-l border-line shadow-2xl outline-none"
        onkeydown={onPanelKeydown}
    >
        <header class="flex items-center gap-3 border-b border-line px-5 py-3">
            <div class="ws-eyebrow text-fg-muted min-w-0 flex-1 truncate">
                {#if task}
                    {#if task.item_number}#{task.item_number} ·
                    {/if}
                    <a
                        href={`/workspace/projects/${projectSlug}`}
                        class="hover:text-accent"
                    >
                        {task.project?.title ?? 'Project'}
                    </a>
                {:else}
                    Loading…
                {/if}
            </div>
            {#if task}
                <a
                    href={`/workspace/projects/${projectSlug}/tasks/${task.slug}`}
                    class="text-fg-muted hover:text-accent font-mono text-[11px] whitespace-nowrap"
                >
                    Open full page ↗
                </a>
            {/if}
            <button
                type="button"
                aria-label="Close"
                class="text-fg-muted hover:bg-surface-alt hover:text-fg rounded-md p-1"
                onclick={() => peek.close()}
            >
                ✕
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            {#if loadFailed}
                <div class="flex flex-col items-center gap-3 py-16 text-center">
                    <p class="text-fg-muted text-sm">
                        Couldn't load this task.
                    </p>
                    <button
                        type="button"
                        class="hover:border-accent rounded-md border border-line px-3 py-1.5 font-mono text-xs"
                        onclick={() => target && load(target.id)}
                    >
                        Retry
                    </button>
                </div>
            {:else if !task}
                <div class="animate-pulse space-y-4 py-2">
                    <div class="bg-surface-alt h-6 w-3/4 rounded"></div>
                    <div class="bg-surface-alt h-4 w-1/2 rounded"></div>
                    <div class="bg-surface-alt h-24 rounded"></div>
                    <div class="bg-surface-alt h-16 rounded"></div>
                </div>
            {:else}
                <div class="space-y-5">
                    <div class="flex items-start gap-3">
                        <div class="pt-1">
                            <CompleteCheckbox {task} {projectSlug} />
                        </div>
                        {#if editingTitle}
                            <!-- svelte-ignore a11y_autofocus -->
                            <input
                                type="text"
                                bind:value={titleDraft}
                                autofocus
                                class="border-accent min-w-0 flex-1 border-0 border-b bg-transparent p-0 font-display text-lg font-bold tracking-tight outline-none"
                                onblur={saveTitle}
                                onkeydown={(e) => {
                                    if (e.key === 'Enter') {
                                        saveTitle();
                                    }

                                    if (e.key === 'Escape') {
                                        e.stopPropagation();
                                        editingTitle = false;
                                    }
                                }}
                            />
                        {:else}
                            <button
                                type="button"
                                id="peek-title"
                                class="hover:text-accent min-w-0 flex-1 text-left font-display text-lg leading-snug font-bold tracking-tight"
                                title="Click to rename"
                                onclick={() => {
                                    titleDraft = task.title;
                                    editingTitle = true;
                                }}
                            >
                                {task.title}
                            </button>
                        {/if}
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <StatusChip
                            {task}
                            {projectSlug}
                            onUpdated={(status) => applyLocal({ status })}
                        />
                        <PriorityFlag
                            {task}
                            {projectSlug}
                            onUpdated={(priority: Priority) =>
                                applyLocal({ priority })}
                        />
                        <DateChip
                            {task}
                            {projectSlug}
                            onUpdated={(deadline_at) =>
                                applyLocal({ deadline_at })}
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="ws-eyebrow text-fg-muted">Progress</span>
                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            bind:value={progressDraft}
                            class="accent-accent flex-1"
                            onchange={saveProgress}
                        />
                        <span
                            class="text-fg-muted w-10 text-right font-mono text-xs"
                            >{progressDraft}%</span
                        >
                    </div>

                    <div>
                        <h3 class="ws-eyebrow text-fg-muted mb-2">Assignees</h3>
                        <AssigneeStack
                            task={{
                                id: task.id,
                                slug: task.slug,
                                assignments: preview?.assignments ?? [],
                            }}
                            team={preview?.team ?? []}
                            max={6}
                            align="left"
                            onUpdated={revalidate}
                        />
                    </div>

                    <div>
                        {#if editingDescription}
                            <!-- svelte-ignore a11y_autofocus -->
                            <textarea
                                bind:value={descriptionDraft}
                                rows="4"
                                autofocus
                                class="bg-surface w-full rounded-md border border-line px-2.5 py-2 text-sm"
                                onblur={saveDescription}
                                onkeydown={(e) => {
                                    if (e.key === 'Escape') {
                                        e.stopPropagation();
                                        editingDescription = false;
                                    }
                                }}
                            ></textarea>
                        {:else}
                            <button
                                type="button"
                                class="text-fg-muted hover:text-fg w-full text-left text-sm whitespace-pre-wrap"
                                onclick={() => {
                                    descriptionDraft = task.description ?? '';
                                    editingDescription = true;
                                }}
                            >
                                {#if task.description}
                                    {task.description}
                                {:else}
                                    <span class="text-fg-faint italic"
                                        >Click to add a description…</span
                                    >
                                {/if}
                            </button>
                        {/if}
                    </div>

                    <section class="border-t border-line-soft pt-4">
                        <h3 class="ws-eyebrow text-fg-muted mb-2">
                            Subtasks {#if preview && preview.subtasks.length > 0}({doneSubtasks}/{preview
                                    .subtasks.length}){/if}
                        </h3>
                        <ul class="space-y-1">
                            {#each preview?.subtasks ?? [] as subtask (subtask.id)}
                                {@const pending = pendingSubtaskIds.has(
                                    subtask.id,
                                )}
                                <li class="group flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={subtask.is_done}
                                        disabled={pending}
                                        class="h-4 w-4 rounded accent-success disabled:opacity-40"
                                        onchange={(e) =>
                                            toggleSubtask(
                                                subtask.id,
                                                (
                                                    e.currentTarget as HTMLInputElement
                                                ).checked,
                                            )}
                                    />
                                    {#if editingSubtaskId === subtask.id}
                                        <!-- svelte-ignore a11y_autofocus -->
                                        <input
                                            type="text"
                                            bind:value={subtaskEditDraft}
                                            autofocus
                                            class="border-accent text-fg-muted min-w-0 flex-1 border-0 border-b bg-transparent p-0 text-sm outline-none"
                                            onblur={() =>
                                                saveSubtaskEdit(
                                                    subtask.id,
                                                    subtask.body,
                                                )}
                                            onkeydown={(e) => {
                                                if (e.key === 'Enter') {
                                                    saveSubtaskEdit(
                                                        subtask.id,
                                                        subtask.body,
                                                    );
                                                }

                                                if (e.key === 'Escape') {
                                                    e.stopPropagation();
                                                    editingSubtaskId = null;
                                                }
                                            }}
                                        />
                                    {:else}
                                        <span
                                            class={`flex-1 cursor-text text-sm ${subtask.is_done ? 'text-fg-faint line-through' : 'text-fg-muted'}`}
                                            title="Double-click to edit"
                                            ondblclick={() =>
                                                startEditSubtask(
                                                    subtask.id,
                                                    subtask.body,
                                                )}
                                            role="button"
                                            tabindex="-1"
                                        >
                                            {subtask.body}
                                        </span>
                                    {/if}
                                    {#if pending}
                                        <span
                                            class="border-accent h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-[1.5px] border-t-transparent"
                                            aria-label="Saving"
                                        ></span>
                                    {:else}
                                        <button
                                            type="button"
                                            aria-label="Delete subtask"
                                            class="text-fg-faint opacity-0 group-hover:opacity-100 hover:text-danger"
                                            onclick={() =>
                                                deleteSubtask(subtask.id)}
                                        >
                                            ×
                                        </button>
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                        <input
                            type="text"
                            bind:value={subtaskDraft}
                            placeholder="+ Add a subtask…"
                            class="placeholder:text-fg-faint focus:border-accent mt-2 w-full rounded-md border border-dashed border-line bg-transparent px-2.5 py-1.5 text-sm focus:outline-none"
                            onblur={addSubtask}
                            onkeydown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    addSubtask();
                                }
                            }}
                        />
                    </section>

                    <section class="border-t border-line-soft pt-4">
                        <h3 class="ws-eyebrow text-fg-muted mb-2">
                            Notes {#if preview && preview.notes.length > 0}({preview
                                    .notes.length}){/if}
                        </h3>
                        <div class="space-y-2">
                            {#each preview?.notes ?? [] as note (note.id)}
                                <div
                                    class="group rounded-lg border border-line px-3 py-2"
                                >
                                    <div
                                        class="text-fg-muted flex items-center gap-2 font-mono text-[10px]"
                                    >
                                        <span>{note.user?.name}</span>
                                        <span>· {note.type_label}</span>
                                        {#if note.happened_at}<span
                                                >· {formatDate(
                                                    note.happened_at,
                                                )}</span
                                            >{/if}
                                        <button
                                            type="button"
                                            aria-label="Delete note"
                                            class="text-fg-faint ml-auto opacity-0 group-hover:opacity-100 hover:text-danger"
                                            onclick={() => deleteNote(note.id)}
                                        >
                                            ×
                                        </button>
                                    </div>
                                    <p
                                        class="text-fg-muted mt-1 text-sm whitespace-pre-wrap"
                                    >
                                        {note.body}
                                    </p>
                                </div>
                            {/each}
                        </div>
                        <div class="mt-2 flex items-start gap-2">
                            <textarea
                                bind:value={noteDraft}
                                rows="2"
                                placeholder="Add a note…"
                                class="bg-surface min-w-0 flex-1 rounded-md border border-line px-2.5 py-1.5 text-sm"
                            ></textarea>
                            <div class="flex flex-col gap-1.5">
                                <select
                                    bind:value={noteType}
                                    class="bg-surface rounded-md border border-line px-1.5 py-1 text-xs"
                                >
                                    {#each NOTE_TYPES as t (t.value)}
                                        <option value={t.value}
                                            >{t.label}</option
                                        >
                                    {/each}
                                </select>
                                <button
                                    type="button"
                                    class="bg-accent text-bg rounded-md px-2 py-1 text-xs font-semibold disabled:opacity-40"
                                    disabled={noteDraft.trim() === ''}
                                    onclick={addNote}
                                >
                                    Add
                                </button>
                            </div>
                        </div>
                    </section>

                    {#if preview}
                        <section class="border-t border-line-soft pt-4">
                            <a
                                href={`/workspace/projects/${projectSlug}/tasks/${task.slug}`}
                                class="ws-eyebrow text-fg-muted hover:text-accent flex items-center gap-1"
                            >
                                Comments ({preview.comments_count}) ↗
                            </a>
                        </section>
                    {/if}

                    <section class="border-t border-line-soft pt-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="ws-eyebrow text-fg-muted">
                                Contacts {#if preview && preview.contacts.length > 0}({preview
                                        .contacts.length}){/if}
                            </h3>
                            <button
                                type="button"
                                class="text-fg-muted hover:text-accent font-mono text-[11px]"
                                onclick={() =>
                                    (showContactForm = !showContactForm)}
                            >
                                {showContactForm ? 'cancel' : '+ add'}
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            {#each preview?.contacts ?? [] as contact (contact.id)}
                                <span
                                    class="bg-surface-alt text-fg-muted inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs"
                                    title={[
                                        contact.organization,
                                        contact.phone,
                                        contact.email,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                >
                                    <span class="font-medium"
                                        >{contact.name}</span
                                    >
                                    {#if contact.role}<span
                                            class="text-fg-muted"
                                            >· {contact.role}</span
                                        >{/if}
                                </span>
                            {/each}
                        </div>
                        {#if showContactForm}
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <input
                                    type="text"
                                    bind:value={contactDraft.name}
                                    placeholder="Name *"
                                    class="bg-surface col-span-2 rounded-md border border-line px-2.5 py-1.5 text-sm"
                                />
                                <input
                                    type="text"
                                    bind:value={contactDraft.role}
                                    placeholder="Role"
                                    class="bg-surface rounded-md border border-line px-2.5 py-1.5 text-sm"
                                />
                                <input
                                    type="text"
                                    bind:value={contactDraft.organization}
                                    placeholder="Organization"
                                    class="bg-surface rounded-md border border-line px-2.5 py-1.5 text-sm"
                                />
                                <input
                                    type="text"
                                    bind:value={contactDraft.phone}
                                    placeholder="Phone"
                                    class="bg-surface rounded-md border border-line px-2.5 py-1.5 text-sm"
                                />
                                <input
                                    type="email"
                                    bind:value={contactDraft.email}
                                    placeholder="Email"
                                    class="bg-surface rounded-md border border-line px-2.5 py-1.5 text-sm"
                                />
                                <button
                                    type="button"
                                    class="bg-accent text-bg col-span-2 rounded-md px-3 py-1.5 text-xs font-semibold disabled:opacity-40"
                                    disabled={contactDraft.name.trim() === ''}
                                    onclick={addContact}
                                >
                                    Add contact
                                </button>
                            </div>
                        {/if}
                    </section>

                    <section class="border-t border-line-soft pt-4 pb-2">
                        <button
                            type="button"
                            aria-expanded={showHistory}
                            class="ws-eyebrow text-fg-muted hover:text-fg flex items-center gap-1"
                            onclick={() => (showHistory = !showHistory)}
                        >
                            <span
                                class={`transition-transform ${showHistory ? 'rotate-90' : ''}`}
                                >▸</span
                            >
                            History {#if preview && preview.activity.length > 0}({preview
                                    .activity.length}){/if}
                        </button>
                        {#if showHistory}
                            <ul class="mt-2 space-y-1.5">
                                {#each preview?.activity ?? [] as entry (entry.id)}
                                    <li
                                        class="text-fg-muted flex items-baseline gap-2 text-xs"
                                    >
                                        <span class="min-w-0 flex-1">
                                            {#if entry.user}<span
                                                    class="text-fg-muted font-medium"
                                                    >{entry.user.name}</span
                                                >{/if}
                                            {entry.description}
                                        </span>
                                        <span
                                            class="text-fg-faint shrink-0 font-mono text-[10px]"
                                            >{formatDate(
                                                entry.created_at,
                                            )}</span
                                        >
                                    </li>
                                {:else}
                                    <li class="text-xs text-fg-faint">
                                        No recorded activity.
                                    </li>
                                {/each}
                            </ul>
                        {/if}
                    </section>
                </div>
            {/if}
        </div>
    </div>
    <button
        type="button"
        aria-label="Close panel"
        class="fixed inset-0 z-[45] cursor-default"
        onmousedown={(e) => {
            if (e.target === e.currentTarget) {
                peek.close();
            }
        }}
        tabindex="-1"
    ></button>
{/if}
