<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import {
        Check,
        ChevronRight,
        Clock,
        ExternalLink,
        LoaderCircle,
        MessageSquare,
        Plus,
        X,
    } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { formatDate } from '../lib/format';
    import { peek } from '../lib/peek.svelte';
    import type { Priority, TaskPreview } from '../lib/types';
    import AssigneeStack from './AssigneeStack.svelte';
    import Avatar from './Avatar.svelte';
    import CompleteCheckbox from './CompleteCheckbox.svelte';
    import DateChip from './DateChip.svelte';
    import PriorityFlag from './PriorityFlag.svelte';
    import ProgressRing from './ProgressRing.svelte';
    import StatusChip from './StatusChip.svelte';

    const NOTE_TYPES: { value: string; label: string }[] = [
        { value: 'general', label: 'General note' },
        { value: 'action_taken', label: 'Action taken' },
        { value: 'meeting', label: 'Meeting' },
        { value: 'blocker', label: 'Blocker' },
        { value: 'milestone', label: 'Milestone' },
        { value: 'decision', label: 'Decision' },
    ];

    const PRIORITY_LABELS: Record<Priority, string> = {
        urgent: 'Urgent',
        high: 'High',
        medium: 'Medium',
        low: 'Low',
    };

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
    const ownerNames = $derived(
        (preview?.assignments ?? [])
            .map((a) => a.member?.name)
            .filter(Boolean)
            .join(', '),
    );
    const hasPlan = $derived(
        Boolean(
            task &&
            (task.item_number ||
                task.category_label ||
                task.responsible_ministry),
        ),
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

    // Re-runs only when the open target changes, NOT when `cache` mutates.
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
    <div aria-hidden="true" class="fixed inset-0 z-40 bg-black/40"></div>
    <div
        bind:this={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby="peek-title"
        aria-busy={!preview && !loadFailed}
        tabindex="-1"
        class="fixed inset-y-0 right-0 z-50 flex w-[min(560px,92vw)] flex-col border-l border-line bg-surface outline-none"
        onkeydown={onPanelKeydown}
    >
        <header
            class="flex h-11 shrink-0 items-center gap-1.5 border-b border-line px-4"
        >
            <div class="flex min-w-0 flex-1 items-center gap-1.5 text-fg-muted">
                {#if task}
                    <a
                        href={`/workspace/projects/${projectSlug}`}
                        class="truncate hover:text-fg"
                    >
                        {task.project?.title ?? 'Project'}
                    </a>
                    <span class="text-fg-faint">/</span>
                    <span class="shrink-0 font-mono tabular-nums">
                        {#if task.item_number}#{task.item_number}{:else}{task.slug}{/if}
                    </span>
                {:else}
                    <span class="text-fg-faint">Loading</span>
                {/if}
            </div>
            {#if task}
                <a
                    href={`/workspace/projects/${projectSlug}/tasks/${task.slug}`}
                    class="btn-ghost"
                >
                    <ExternalLink class="h-3.5 w-3.5" />
                    Open full page
                </a>
            {/if}
            <button
                type="button"
                aria-label="Close"
                class="btn-icon"
                onclick={() => peek.close()}
            >
                <X class="h-4 w-4" />
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            {#if loadFailed}
                <div class="flex flex-col items-center gap-3 py-16 text-center">
                    <p class="text-[13px] text-fg-muted">
                        Could not load this task.
                    </p>
                    <button
                        type="button"
                        class="btn"
                        onclick={() => target && load(target.id)}
                    >
                        Retry
                    </button>
                </div>
            {:else if !task}
                <div class="animate-pulse space-y-4 py-2">
                    <div class="h-6 w-3/4 rounded-md bg-surface-alt"></div>
                    <div class="h-4 w-1/2 rounded-md bg-surface-alt"></div>
                    <div class="h-24 rounded-md bg-surface-alt"></div>
                    <div class="h-16 rounded-md bg-surface-alt"></div>
                </div>
            {:else}
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
                            class="min-w-0 flex-1 border-0 border-b border-accent bg-transparent p-0 text-[20px] leading-tight font-semibold tracking-[-0.02em] text-fg outline-none focus-visible:outline-none"
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
                            class="min-w-0 flex-1 rounded-sm text-left text-[20px] leading-tight font-semibold tracking-[-0.02em] text-fg hover:text-accent"
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
                {#if task.title_np}
                    <p class="mt-1.5 pl-8 font-np text-[15px] text-fg-muted">
                        {task.title_np}
                    </p>
                {/if}

                <div class="mt-4">
                    {#if editingDescription}
                        <!-- svelte-ignore a11y_autofocus -->
                        <textarea
                            bind:value={descriptionDraft}
                            rows="4"
                            autofocus
                            class="input min-h-[96px] resize-y text-[14px] leading-relaxed"
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
                            class="w-full rounded-sm text-left text-[14px] leading-relaxed whitespace-pre-wrap text-fg hover:text-fg"
                            onclick={() => {
                                descriptionDraft = task.description ?? '';
                                editingDescription = true;
                            }}
                        >
                            {#if task.description}
                                {task.description}
                            {:else}
                                <span class="text-fg-faint"
                                    >Add a description</span
                                >
                            {/if}
                        </button>
                    {/if}
                    {#if task.description_np}
                        <p
                            class="mt-3 font-np text-[14px] leading-relaxed whitespace-pre-wrap text-fg-muted"
                        >
                            {task.description_np}
                        </p>
                    {/if}
                </div>

                <dl
                    class="mt-5 grid grid-cols-[88px_1fr] items-center gap-x-2.5 border-t border-line pt-3 text-[12.5px]"
                >
                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Status
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center">
                        <StatusChip
                            {task}
                            {projectSlug}
                            onUpdated={(status) => applyLocal({ status })}
                        />
                    </dd>

                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Priority
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center gap-1.5">
                        <PriorityFlag
                            {task}
                            {projectSlug}
                            onUpdated={(priority: Priority) =>
                                applyLocal({ priority })}
                        />
                        <span class="text-fg"
                            >{PRIORITY_LABELS[task.priority ?? 'medium']}</span
                        >
                    </dd>

                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Owners
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center gap-2">
                        <AssigneeStack
                            task={{
                                id: task.id,
                                slug: task.slug,
                                assignments: preview?.assignments ?? [],
                            }}
                            team={preview?.team ?? []}
                            max={4}
                            align="left"
                            onUpdated={revalidate}
                        />
                        {#if ownerNames}
                            <span class="truncate text-xs text-fg-muted"
                                >{ownerNames}</span
                            >
                        {/if}
                    </dd>

                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Progress
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center gap-2">
                        <ProgressRing percent={progressDraft} />
                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            bind:value={progressDraft}
                            aria-label="Progress"
                            class="w-24 accent-accent"
                            onchange={saveProgress}
                        />
                        <span
                            class="font-mono text-xs text-fg-muted tabular-nums"
                            >{progressDraft}%</span
                        >
                    </dd>

                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Deadline
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center gap-2">
                        <DateChip
                            {task}
                            {projectSlug}
                            onUpdated={(deadline_at) =>
                                applyLocal({ deadline_at })}
                        />
                        {#if task.deadline_at}
                            <span
                                class="font-mono text-xs text-fg-faint tabular-nums"
                                >{formatDate(task.deadline_at)}</span
                            >
                        {/if}
                    </dd>
                    {#if task.deadline_label}
                        <dt
                            class="flex min-h-[30px] items-center text-fg-muted"
                        >
                            Type
                        </dt>
                        <dd
                            class="flex min-h-[30px] min-w-0 items-center text-fg"
                        >
                            {task.deadline_label}
                        </dd>
                    {/if}

                    {#if hasPlan}
                        <dt
                            class="section-title col-span-2 mt-3 mb-1 min-h-0 text-[13px]"
                        >
                            Plan
                        </dt>
                        {#if task.item_number}
                            <dt
                                class="flex min-h-[30px] items-center text-fg-muted"
                            >
                                Item
                            </dt>
                            <dd
                                class="flex min-h-[30px] items-center font-mono text-fg tabular-nums"
                            >
                                #{task.item_number}
                            </dd>
                        {/if}
                        {#if task.category_label}
                            <dt
                                class="flex min-h-[30px] items-center text-fg-muted"
                            >
                                Category
                            </dt>
                            <dd
                                class="flex min-h-[30px] min-w-0 items-center gap-1.5"
                            >
                                {#if task.category_color}
                                    <span
                                        class="h-2 w-2 shrink-0 rounded-[2px]"
                                        style={`background:${task.category_color}`}
                                    ></span>
                                {/if}
                                <span class="truncate text-fg"
                                    >{task.category_label}</span
                                >
                            </dd>
                        {/if}
                        {#if task.responsible_ministry}
                            <dt
                                class="flex min-h-[30px] items-center text-fg-muted"
                            >
                                Ministry
                            </dt>
                            <dd
                                class="flex min-h-[30px] min-w-0 items-center text-fg"
                            >
                                <span class="truncate"
                                    >{task.responsible_ministry}</span
                                >
                            </dd>
                        {/if}
                    {/if}
                </dl>

                <section class="mt-8">
                    <h3 class="section-title text-[15px]">
                        Subtasks
                        {#if preview && preview.subtasks.length > 0}
                            <span class="section-count"
                                >{doneSubtasks}/{preview.subtasks.length}</span
                            >
                        {/if}
                    </h3>
                    <ul class="mt-1.5">
                        {#each preview?.subtasks ?? [] as subtask (subtask.id)}
                            {@const pending = pendingSubtaskIds.has(subtask.id)}
                            <li
                                class="group flex h-8 items-center gap-2.5 border-b border-line-soft"
                            >
                                <span
                                    class="relative inline-flex h-3.5 w-3.5 shrink-0"
                                >
                                    <input
                                        type="checkbox"
                                        checked={subtask.is_done}
                                        disabled={pending}
                                        aria-label={subtask.body}
                                        class="peer h-3.5 w-3.5 cursor-pointer appearance-none rounded-sm border-[1.5px] border-line transition checked:border-accent checked:bg-accent hover:border-accent disabled:opacity-40"
                                        onchange={(e) =>
                                            toggleSubtask(
                                                subtask.id,
                                                (
                                                    e.currentTarget as HTMLInputElement
                                                ).checked,
                                            )}
                                    />
                                    <Check
                                        class="pointer-events-none absolute inset-0 m-auto h-2.5 w-2.5 text-white opacity-0 peer-checked:opacity-100"
                                    />
                                </span>
                                {#if editingSubtaskId === subtask.id}
                                    <!-- svelte-ignore a11y_autofocus -->
                                    <input
                                        type="text"
                                        bind:value={subtaskEditDraft}
                                        autofocus
                                        class="min-w-0 flex-1 border-0 border-b border-accent bg-transparent p-0 text-[13.5px] text-fg outline-none focus-visible:outline-none"
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
                                        class={`min-w-0 flex-1 cursor-text truncate text-[13.5px] ${subtask.is_done ? 'text-fg-faint line-through' : 'text-fg'}`}
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
                                    <LoaderCircle
                                        class="h-3.5 w-3.5 shrink-0 animate-spin text-accent"
                                        aria-label="Saving"
                                    />
                                {:else}
                                    <button
                                        type="button"
                                        aria-label="Delete subtask"
                                        class="btn-icon h-6 w-6 opacity-0 group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                        onclick={() =>
                                            deleteSubtask(subtask.id)}
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                {/if}
                            </li>
                        {/each}
                    </ul>
                    <input
                        type="text"
                        bind:value={subtaskDraft}
                        placeholder="Add a subtask"
                        aria-label="Add a subtask"
                        class="input mt-2"
                        onblur={addSubtask}
                        onkeydown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                addSubtask();
                            }
                        }}
                    />
                </section>

                <section class="mt-8">
                    <h3 class="section-title text-[15px]">
                        Notes
                        {#if preview && preview.notes.length > 0}
                            <span class="section-count"
                                >{preview.notes.length}</span
                            >
                        {/if}
                    </h3>
                    <div class="mt-1">
                        {#each preview?.notes ?? [] as note (note.id)}
                            <div class="group border-b border-line-soft py-2.5">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="font-medium text-fg"
                                        >{note.user?.name}</span
                                    >
                                    <span class="chip">{note.type_label}</span>
                                    {#if note.happened_at}
                                        <span
                                            class="font-mono text-fg-faint tabular-nums"
                                            >{formatDate(
                                                note.happened_at,
                                            )}</span
                                        >
                                    {/if}
                                    <button
                                        type="button"
                                        aria-label="Delete note"
                                        class="btn-icon ml-auto h-6 w-6 opacity-0 group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                        onclick={() => deleteNote(note.id)}
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p
                                    class="mt-1 text-[13.5px] leading-relaxed whitespace-pre-wrap text-fg"
                                >
                                    {note.body}
                                </p>
                            </div>
                        {/each}
                    </div>
                    <div
                        class="mt-3 rounded-lg border border-line bg-surface-alt px-3.5 py-3"
                    >
                        <textarea
                            bind:value={noteDraft}
                            rows="2"
                            placeholder="Add a note"
                            aria-label="Add a note"
                            class="input min-h-[56px] resize-y border-0 bg-transparent p-0 focus:border-0"
                        ></textarea>
                        <div class="mt-2 flex items-center gap-2">
                            <select
                                bind:value={noteType}
                                aria-label="Note type"
                                class="input w-auto py-1"
                            >
                                {#each NOTE_TYPES as t (t.value)}
                                    <option value={t.value}>{t.label}</option>
                                {/each}
                            </select>
                            <button
                                type="button"
                                class="btn-primary ml-auto"
                                disabled={noteDraft.trim() === ''}
                                onclick={addNote}
                            >
                                Add
                            </button>
                        </div>
                    </div>
                </section>

                <section class="mt-8">
                    <div class="flex items-center justify-between">
                        <h3 class="section-title text-[15px]">
                            Contacts
                            {#if preview && preview.contacts.length > 0}
                                <span class="section-count"
                                    >{preview.contacts.length}</span
                                >
                            {/if}
                        </h3>
                        <button
                            type="button"
                            class="btn-ghost"
                            onclick={() => (showContactForm = !showContactForm)}
                        >
                            {#if showContactForm}
                                <X class="h-3.5 w-3.5" />
                                Cancel
                            {:else}
                                <Plus class="h-3.5 w-3.5" />
                                Add
                            {/if}
                        </button>
                    </div>
                    {#if preview && preview.contacts.length > 0}
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            {#each preview.contacts as contact (contact.id)}
                                <span
                                    class="chip h-6 gap-1 px-2"
                                    title={[
                                        contact.organization,
                                        contact.phone,
                                        contact.email,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                >
                                    <span class="font-medium text-fg"
                                        >{contact.name}</span
                                    >
                                    {#if contact.role}
                                        <span class="font-normal"
                                            >{contact.role}</span
                                        >
                                    {/if}
                                </span>
                            {/each}
                        </div>
                    {:else if !showContactForm}
                        <p class="mt-1 text-[13px] text-fg-muted">
                            No contacts yet.
                        </p>
                    {/if}
                    {#if showContactForm}
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <label class="label" for="peek-contact-name"
                                    >Name</label
                                >
                                <input
                                    id="peek-contact-name"
                                    type="text"
                                    bind:value={contactDraft.name}
                                    class="input mt-1"
                                />
                            </div>
                            <div>
                                <label class="label" for="peek-contact-role"
                                    >Role</label
                                >
                                <input
                                    id="peek-contact-role"
                                    type="text"
                                    bind:value={contactDraft.role}
                                    class="input mt-1"
                                />
                            </div>
                            <div>
                                <label class="label" for="peek-contact-org"
                                    >Organization</label
                                >
                                <input
                                    id="peek-contact-org"
                                    type="text"
                                    bind:value={contactDraft.organization}
                                    class="input mt-1"
                                />
                            </div>
                            <div>
                                <label class="label" for="peek-contact-phone"
                                    >Phone</label
                                >
                                <input
                                    id="peek-contact-phone"
                                    type="text"
                                    bind:value={contactDraft.phone}
                                    class="input mt-1"
                                />
                            </div>
                            <div>
                                <label class="label" for="peek-contact-email"
                                    >Email</label
                                >
                                <input
                                    id="peek-contact-email"
                                    type="email"
                                    bind:value={contactDraft.email}
                                    class="input mt-1"
                                />
                            </div>
                            <div class="col-span-2 flex justify-end">
                                <button
                                    type="button"
                                    class="btn-primary"
                                    disabled={contactDraft.name.trim() === ''}
                                    onclick={addContact}
                                >
                                    Add contact
                                </button>
                            </div>
                        </div>
                    {/if}
                </section>

                {#if preview}
                    <section class="mt-8">
                        <a
                            href={`/workspace/projects/${projectSlug}/tasks/${task.slug}`}
                            class="btn-ghost -ml-2"
                        >
                            <MessageSquare class="h-3.5 w-3.5" />
                            Comments
                            <span class="section-count"
                                >{preview.comments_count}</span
                            >
                            <ExternalLink class="h-3 w-3 text-fg-faint" />
                        </a>
                    </section>
                {/if}

                <section class="mt-6 pb-2">
                    <button
                        type="button"
                        aria-expanded={showHistory}
                        class="section-title -ml-1 rounded-md px-1 text-[15px] hover:bg-hover"
                        onclick={() => (showHistory = !showHistory)}
                    >
                        <ChevronRight
                            class={`h-3.5 w-3.5 self-center text-fg-faint transition-transform ${showHistory ? 'rotate-90' : ''}`}
                        />
                        Activity
                        {#if preview && preview.activity.length > 0}
                            <span class="section-count"
                                >{preview.activity.length}</span
                            >
                        {/if}
                    </button>
                    {#if showHistory}
                        <ul class="mt-2">
                            {#each preview?.activity ?? [] as entry (entry.id)}
                                <li
                                    class="grid grid-cols-[20px_1fr] gap-3 py-1.5 text-[13px] text-fg-muted"
                                >
                                    {#if entry.user}
                                        <Avatar
                                            name={entry.user.name}
                                            size="sm"
                                        />
                                    {:else}
                                        <span
                                            class="grid h-5 w-5 place-items-center"
                                        >
                                            <Clock
                                                class="h-3.5 w-3.5 text-fg-faint"
                                            />
                                        </span>
                                    {/if}
                                    <span class="min-w-0 leading-5">
                                        {#if entry.user}<span
                                                class="font-medium text-fg"
                                                >{entry.user.name}</span
                                            >{/if}
                                        {entry.description}
                                        <span class="ml-1 text-xs text-fg-faint"
                                            >{formatDate(
                                                entry.created_at,
                                            )}</span
                                        >
                                    </span>
                                </li>
                            {:else}
                                <li class="py-1.5 text-xs text-fg-faint">
                                    No recorded activity.
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </section>
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
