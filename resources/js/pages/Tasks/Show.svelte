<script lang="ts">
    import { inertia, page, router, useForm } from '@inertiajs/svelte';
    import {
        Check,
        LoaderCircle,
        Mail,
        Phone,
        Trash2,
        X,
    } from '@lucide/svelte';
    import { onMount, untrack } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AssigneeStack from '../../components/AssigneeStack.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import CommentThread from '../../components/CommentThread.svelte';
    import DateChip from '../../components/DateChip.svelte';
    import PriorityFlag from '../../components/PriorityFlag.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import StatusChip from '../../components/StatusChip.svelte';
    import { formatDate } from '../../lib/format';
    import type {
        Comment,
        Contact,
        Member,
        Note,
        Priority,
        Project,
        Subtask,
        Task,
    } from '../../lib/types';

    let {
        project,
        task,
        notes,
        contacts,
        subtasks,
        comments,
        team,
    }: {
        project: Project;
        task: Task;
        notes: Note[];
        contacts: Contact[];
        subtasks: Subtask[];
        comments: Comment[];
        team: Member[];
        statuses: { value: string; label: string }[];
    } = $props();

    const PRIORITY_LABELS: Record<Priority, string> = {
        urgent: 'Urgent',
        high: 'High',
        medium: 'Medium',
        low: 'Low',
    };

    const uid = $props.id();

    // `?tab=todos|notes|contacts` deep links (from note stickies etc.) scroll
    // to that section now that everything is on one page.
    const sectionIds = ['todos', 'notes', 'contacts'];

    onMount(() => {
        const requested = new URL(
            page.url,
            'http://localhost',
        ).searchParams.get('tab');

        if (requested && sectionIds.includes(requested)) {
            document
                .getElementById(`${uid}-${requested}`)
                ?.scrollIntoView({ block: 'start' });
        }
    });

    const todoForm = useForm({ body: '', due_at: '' });

    function submitTodo() {
        if (!todoForm.body.trim() || todoForm.processing) {
            return;
        }

        todoForm.post(`/workspace/tasks/${task.id}/subtasks`, {
            preserveScroll: true,
            onSuccess: () => todoForm.reset(),
        });
    }

    function addTodo(e: SubmitEvent) {
        e.preventDefault();
        submitTodo();
    }

    function toggleTodo(t: Subtask) {
        router.patch(
            `/workspace/subtasks/${t.id}`,
            { is_done: !t.is_done },
            { preserveScroll: true },
        );
    }

    function deleteTodo(t: Subtask) {
        router.delete(`/workspace/subtasks/${t.id}`, { preserveScroll: true });
    }

    const editForm = useForm(
        untrack(() => ({
            title: task.title,
            description: task.description ?? '',
            status: task.status,
            priority: task.priority ?? 'medium',
            task_progress: task.progress,
            deadline_at: task.deadline_at ?? '',
            status_note: task.status_note ?? '',
            source_url: task.source_url ?? '',
        })),
    );

    // `progress` is a reserved field name on useForm, so the form tracks it
    // as `task_progress` and maps it back to what the backend validates.
    editForm.transform(({ task_progress, ...data }) => ({
        ...data,
        progress: task_progress,
    }));

    // The property chips (status, priority, deadline) save on their own. Mirror
    // their value into the edit form and its defaults so a later Save doesn't
    // resend a stale value and the form doesn't read as dirty.
    function syncStatus(status: string) {
        editForm.status = status;
        editForm.defaults('status', status);
    }

    function syncPriority(priority: Priority) {
        editForm.priority = priority;
        editForm.defaults('priority', priority);
    }

    function syncDeadline(deadline_at: string | null) {
        editForm.deadline_at = deadline_at ?? '';
        editForm.defaults('deadline_at', deadline_at ?? '');
    }

    const noteForm = useForm({ body: '', type: 'general', happened_at: '' });
    const contactForm = useForm({
        name: '',
        role: '',
        email: '',
        phone: '',
        organization: '',
        notes: '',
    });

    let todoFormEl = $state<HTMLFormElement | null>(null);
    let noteFormEl = $state<HTMLFormElement | null>(null);
    let contactFormEl = $state<HTMLFormElement | null>(null);

    function submitEdit() {
        if (!editForm.isDirty || editForm.processing) {
            return;
        }

        editForm.patch(
            `/workspace/projects/${project.slug}/tasks/${task.slug}`,
            {
                preserveScroll: true,
                onSuccess: () => editForm.defaults(),
            },
        );
    }

    function saveEdit(e: SubmitEvent) {
        e.preventDefault();
        submitEdit();
    }

    // Cmd/Ctrl+S saves whichever form has focus; the task itself otherwise.
    function handleSaveShortcut(e: KeyboardEvent) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();

            const active = document.activeElement;

            if (todoFormEl?.contains(active)) {
                submitTodo();
            } else if (noteFormEl?.contains(active)) {
                submitNote();
            } else if (contactFormEl?.contains(active)) {
                submitContact();
            } else {
                submitEdit();
            }
        }
    }

    function deleteTask() {
        if (!confirm('Delete this task? This cannot be undone.')) {
            return;
        }

        router.delete(`/workspace/projects/${project.slug}/tasks/${task.slug}`);
    }

    function submitNote() {
        if (!noteForm.body.trim() || noteForm.processing) {
            return;
        }

        noteForm.post(`/workspace/tasks/${task.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        });
    }

    function addNote(e: SubmitEvent) {
        e.preventDefault();
        submitNote();
    }

    function submitContact() {
        if (!contactForm.name.trim() || contactForm.processing) {
            return;
        }

        contactForm.post(`/workspace/tasks/${task.id}/contacts`, {
            preserveScroll: true,
            onSuccess: () => contactForm.reset(),
        });
    }

    function addContact(e: SubmitEvent) {
        e.preventDefault();
        submitContact();
    }

    function deleteNote(note: Note) {
        if (!confirm('Delete this note?')) {
            return;
        }

        router.delete(`/workspace/notes/${note.id}`, { preserveScroll: true });
    }

    const openTodos = $derived(subtasks.filter((s) => !s.is_done).length);
    const ownerNames = $derived(
        (task.assignments ?? [])
            .map((a) => a.member?.name)
            .filter(Boolean)
            .join(', '),
    );
    const hasPlan = $derived(
        Boolean(
            task.item_number ||
            task.category_label ||
            task.responsible_ministry,
        ),
    );
</script>

<svelte:window onkeydown={handleSaveShortcut} />

<svelte:head><title>{task.title} · Workspace</title></svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/projects"
                use:inertia
                class="shrink-0 text-fg-muted hover:text-fg">Projects</a
            >
            <span class="text-fg-faint">/</span>
            <a
                href={`/workspace/projects/${project.slug}`}
                use:inertia
                class="truncate text-fg-muted hover:text-fg">{project.title}</a
            >
            <span class="text-fg-faint">/</span>
            <span class="shrink-0 font-mono text-fg-muted tabular-nums">
                {#if task.item_number}#{task.item_number}{:else}{task.slug}{/if}
            </span>
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            {#if editForm.isDirty}
                <span class="text-xs text-fg-faint">Unsaved changes</span>
                <button
                    type="button"
                    onclick={() => editForm.reset()}
                    class="btn-ghost">Discard</button
                >
            {/if}
            <button
                type="submit"
                form={`${uid}-edit`}
                disabled={editForm.processing || !editForm.isDirty}
                title="Save (⌘S / Ctrl+S)"
                class="btn-primary">Save <kbd class="kbd">⌘S</kbd></button
            >
            <button
                type="button"
                onclick={deleteTask}
                class="btn-ghost hover:text-danger"
            >
                <Trash2 class="h-3.5 w-3.5" />
                Delete
            </button>
        </div>
    {/snippet}

    <div class="grid min-h-[70vh] lg:grid-cols-[minmax(0,1fr)_300px]">
        <div class="min-w-0 max-w-[760px] px-5 py-6 lg:px-10 lg:py-8">
            <form id={`${uid}-edit`} onsubmit={saveEdit}>
                <label class="sr-only" for={`${uid}-title`}>Title</label>
                <input
                    id={`${uid}-title`}
                    type="text"
                    bind:value={editForm.title}
                    placeholder="Task title"
                    class="w-full rounded-sm border-0 bg-transparent p-0 text-[24px] leading-tight font-semibold tracking-[-0.02em] text-fg outline-none placeholder:text-fg-faint focus-visible:outline-none"
                />
                {#if task.title_np}
                    <p class="mt-1.5 font-np text-[16px] text-fg-muted">
                        {task.title_np}
                    </p>
                {/if}

                <label class="sr-only" for={`${uid}-description`}
                    >Description</label
                >
                <textarea
                    id={`${uid}-description`}
                    bind:value={editForm.description}
                    rows="4"
                    placeholder="Add a description"
                    class="mt-4 w-full max-w-[66ch] resize-y rounded-sm border-0 bg-transparent p-0 text-[14px] leading-relaxed text-fg outline-none placeholder:text-fg-faint focus-visible:outline-none"
                ></textarea>
                {#if task.description_np}
                    <p
                        class="mt-3 max-w-[66ch] font-np text-[14px] leading-relaxed whitespace-pre-wrap text-fg-muted"
                    >
                        {task.description_np}
                    </p>
                {/if}

                <div class="mt-6 grid gap-4 border-t border-line pt-5">
                    <div>
                        <label class="label" for={`${uid}-status-note`}
                            >Status note</label
                        >
                        <textarea
                            id={`${uid}-status-note`}
                            bind:value={editForm.status_note}
                            rows="2"
                            placeholder="What is the latest on this?"
                            class="input mt-1 resize-y"
                        ></textarea>
                    </div>
                    <div>
                        <label class="label" for={`${uid}-source-url`}
                            >Source URL</label
                        >
                        <input
                            id={`${uid}-source-url`}
                            type="url"
                            bind:value={editForm.source_url}
                            placeholder="https://"
                            class="input mt-1"
                        />
                    </div>
                </div>
            </form>

            <section id={`${uid}-todos`} class="mt-8 scroll-mt-14">
                <h2 class="section-title text-[15px]">
                    Subtasks
                    {#if subtasks.length > 0}
                        <span class="section-count"
                            >{subtasks.length -
                                openTodos}/{subtasks.length}</span
                        >
                    {/if}
                    <span class="ml-auto text-xs font-normal text-fg-faint"
                        >Private to you</span
                    >
                </h2>
                <ul class="mt-1.5">
                    {#each subtasks as t (t.id)}
                        <li
                            class="group flex h-8 items-center gap-2.5 border-b border-line-soft"
                        >
                            <button
                                type="button"
                                class={`inline-grid h-3.5 w-3.5 shrink-0 place-items-center rounded-sm border-[1.5px] transition ${
                                    t.is_done
                                        ? 'border-accent bg-accent'
                                        : 'border-line hover:border-accent'
                                }`}
                                aria-pressed={t.is_done}
                                aria-label={t.is_done
                                    ? 'Mark not done'
                                    : 'Mark done'}
                                onclick={() => toggleTodo(t)}
                            >
                                {#if t.is_done}
                                    <Check class="h-2.5 w-2.5 text-white" />
                                {/if}
                            </button>
                            <span
                                class={`min-w-0 flex-1 truncate text-[13.5px] ${
                                    t.is_done
                                        ? 'text-fg-faint line-through'
                                        : 'text-fg'
                                }`}
                            >
                                {t.body}
                            </span>
                            {#if t.due_at}
                                <span
                                    class="shrink-0 font-mono text-xs text-fg-faint tabular-nums"
                                    >{formatDate(t.due_at)}</span
                                >
                            {/if}
                            <button
                                type="button"
                                class="btn-icon h-6 w-6 opacity-0 group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                onclick={() => deleteTodo(t)}
                                title="Delete"
                                aria-label="Delete subtask"
                            >
                                <X class="h-3.5 w-3.5" />
                            </button>
                        </li>
                    {:else}
                        <li class="py-2 text-[13px] text-fg-muted">
                            No subtasks yet.
                        </li>
                    {/each}
                </ul>
                <form
                    bind:this={todoFormEl}
                    onsubmit={addTodo}
                    class="mt-2 flex items-center gap-2"
                >
                    <input
                        type="text"
                        bind:value={todoForm.body}
                        placeholder="Add a subtask"
                        aria-label="Add a subtask"
                        class="input flex-1"
                    />
                    <input
                        type="date"
                        bind:value={todoForm.due_at}
                        aria-label="Due date"
                        class="input w-auto"
                    />
                    <button
                        type="submit"
                        disabled={todoForm.processing || !todoForm.body.trim()}
                        class="btn"
                    >
                        {#if todoForm.processing}
                            <LoaderCircle class="h-3.5 w-3.5 animate-spin" />
                        {/if}
                        Add
                    </button>
                </form>
            </section>

            <section id={`${uid}-notes`} class="mt-8 scroll-mt-14">
                <h2 class="section-title text-[15px]">
                    Notes
                    {#if notes.length > 0}
                        <span class="section-count">{notes.length}</span>
                    {/if}
                </h2>
                <div class="mt-1">
                    {#each notes as note (note.id)}
                        <div class="group border-b border-line-soft py-2.5">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="font-medium text-fg"
                                    >{note.user?.name ?? 'Someone'}</span
                                >
                                <span class="chip">{note.type_label}</span>
                                {#if note.happened_at}
                                    <span
                                        class="font-mono text-fg-faint tabular-nums"
                                        >{formatDate(note.happened_at)}</span
                                    >
                                {/if}
                                <button
                                    type="button"
                                    onclick={() => deleteNote(note)}
                                    class="btn-icon ml-auto h-6 w-6 opacity-0 group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                    title="Delete note"
                                    aria-label="Delete note"
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
                    {:else}
                        <p class="py-2 text-[13px] text-fg-muted">
                            No notes yet.
                        </p>
                    {/each}
                </div>
                <form
                    bind:this={noteFormEl}
                    onsubmit={addNote}
                    class="mt-3 rounded-lg border border-line bg-surface-alt px-3.5 py-3"
                >
                    <textarea
                        bind:value={noteForm.body}
                        rows="2"
                        placeholder="Add a note"
                        aria-label="Add a note"
                        class="input min-h-[56px] resize-y border-0 bg-transparent p-0 focus:border-0"
                    ></textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <select
                            bind:value={noteForm.type}
                            aria-label="Note type"
                            class="input w-auto py-1"
                        >
                            <option value="general">General</option>
                            <option value="action_taken">Action taken</option>
                            <option value="meeting">Meeting</option>
                            <option value="blocker">Blocker</option>
                            <option value="milestone">Milestone</option>
                            <option value="decision">Decision</option>
                        </select>
                        <input
                            type="date"
                            bind:value={noteForm.happened_at}
                            aria-label="Happened on"
                            class="input w-auto py-1"
                        />
                        <button
                            type="submit"
                            disabled={noteForm.processing ||
                                !noteForm.body.trim()}
                            class="btn-primary ml-auto"
                        >
                            {#if noteForm.processing}
                                <LoaderCircle
                                    class="h-3.5 w-3.5 animate-spin"
                                />
                            {/if}
                            Add note
                        </button>
                    </div>
                </form>
            </section>

            <section id={`${uid}-contacts`} class="mt-8 scroll-mt-14">
                <h2 class="section-title text-[15px]">
                    Contacts
                    {#if contacts.length > 0}
                        <span class="section-count">{contacts.length}</span>
                    {/if}
                </h2>
                <div class="mt-1">
                    {#each contacts as contact (contact.id)}
                        <div
                            class="flex items-center gap-3 border-b border-line-soft py-2"
                        >
                            <Avatar name={contact.name} size="md" />
                            <div class="min-w-0 flex-1 leading-tight">
                                <div
                                    class="truncate text-[13.5px] font-medium text-fg"
                                >
                                    {contact.name}
                                </div>
                                {#if contact.role || contact.organization}
                                    <div class="truncate text-xs text-fg-muted">
                                        {[contact.role, contact.organization]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                {/if}
                            </div>
                            <div
                                class="flex shrink-0 items-center gap-3 text-xs text-fg-muted"
                            >
                                {#if contact.email}
                                    <a
                                        href={`mailto:${contact.email}`}
                                        class="flex items-center gap-1.5 hover:text-fg"
                                    >
                                        <Mail
                                            class="h-3.5 w-3.5 text-fg-faint"
                                        />
                                        <span class="max-w-[14rem] truncate"
                                            >{contact.email}</span
                                        >
                                    </a>
                                {/if}
                                {#if contact.phone}
                                    <a
                                        href={`tel:${contact.phone}`}
                                        class="flex items-center gap-1.5 hover:text-fg"
                                    >
                                        <Phone
                                            class="h-3.5 w-3.5 text-fg-faint"
                                        />
                                        <span class="font-mono tabular-nums"
                                            >{contact.phone}</span
                                        >
                                    </a>
                                {/if}
                            </div>
                        </div>
                    {:else}
                        <p class="py-2 text-[13px] text-fg-muted">
                            No contacts yet.
                        </p>
                    {/each}
                </div>
                <form
                    bind:this={contactFormEl}
                    onsubmit={addContact}
                    class="mt-3 grid gap-3 sm:grid-cols-2"
                >
                    <div>
                        <label class="label" for={`${uid}-contact-name`}
                            >Name</label
                        >
                        <input
                            id={`${uid}-contact-name`}
                            type="text"
                            bind:value={contactForm.name}
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="label" for={`${uid}-contact-role`}
                            >Role</label
                        >
                        <input
                            id={`${uid}-contact-role`}
                            type="text"
                            bind:value={contactForm.role}
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="label" for={`${uid}-contact-org`}
                            >Organization</label
                        >
                        <input
                            id={`${uid}-contact-org`}
                            type="text"
                            bind:value={contactForm.organization}
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="label" for={`${uid}-contact-email`}
                            >Email</label
                        >
                        <input
                            id={`${uid}-contact-email`}
                            type="email"
                            bind:value={contactForm.email}
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="label" for={`${uid}-contact-phone`}
                            >Phone</label
                        >
                        <input
                            id={`${uid}-contact-phone`}
                            type="tel"
                            bind:value={contactForm.phone}
                            class="input mt-1"
                        />
                    </div>
                    <div class="flex items-end justify-end">
                        <button
                            type="submit"
                            disabled={contactForm.processing ||
                                !contactForm.name.trim()}
                            class="btn-primary">Add contact</button
                        >
                    </div>
                </form>
            </section>

            <section class="mt-8">
                <h2 class="section-title mb-3 text-[15px]">
                    Comments
                    {#if comments.length > 0}
                        <span class="section-count">{comments.length}</span>
                    {/if}
                </h2>
                <CommentThread {comments} {task} members={team} />
            </section>
        </div>

        <aside class="border-t border-line px-5 py-5 lg:border-t-0 lg:border-l">
            <h2 class="section-title mb-2">Properties</h2>
            <dl
                class="grid grid-cols-[88px_1fr] items-center gap-x-2.5 text-[12.5px]"
            >
                <dt class="flex min-h-[30px] items-center text-fg-muted">
                    Status
                </dt>
                <dd class="flex min-h-[30px] min-w-0 items-center">
                    <StatusChip
                        {task}
                        projectSlug={project.slug}
                        onUpdated={syncStatus}
                    />
                </dd>

                <dt class="flex min-h-[30px] items-center text-fg-muted">
                    Priority
                </dt>
                <dd class="flex min-h-[30px] min-w-0 items-center gap-1.5">
                    <PriorityFlag
                        {task}
                        projectSlug={project.slug}
                        onUpdated={syncPriority}
                    />
                    <span class="text-fg"
                        >{PRIORITY_LABELS[task.priority ?? 'medium']}</span
                    >
                </dd>

                <dt class="flex min-h-[30px] items-center text-fg-muted">
                    Owners
                </dt>
                <dd class="flex min-h-[30px] min-w-0 items-center gap-2">
                    <AssigneeStack {task} {team} max={4} align="left" />
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
                    <ProgressRing percent={editForm.task_progress} />
                    <input
                        id={`${uid}-progress`}
                        type="range"
                        min="0"
                        max="100"
                        step="5"
                        bind:value={editForm.task_progress}
                        aria-label="Progress"
                        class="w-24 accent-accent"
                    />
                    <span class="font-mono text-xs text-fg-muted tabular-nums"
                        >{editForm.task_progress}%</span
                    >
                </dd>
            </dl>

            <h3 class="section-title mt-5 mb-1">Deadline</h3>
            <dl
                class="grid grid-cols-[88px_1fr] items-center gap-x-2.5 text-[12.5px]"
            >
                {#if task.deadline_label}
                    <dt class="flex min-h-[30px] items-center text-fg-muted">
                        Type
                    </dt>
                    <dd class="flex min-h-[30px] min-w-0 items-center text-fg">
                        {task.deadline_label}
                    </dd>
                {/if}
                <dt class="flex min-h-[30px] items-center text-fg-muted">
                    Date
                </dt>
                <dd
                    class="flex min-h-[30px] min-w-0 items-center font-mono text-fg tabular-nums"
                >
                    {#if task.deadline_at}
                        {formatDate(task.deadline_at)}
                    {:else}
                        <span class="font-sans text-fg-faint">None</span>
                    {/if}
                </dd>
                <dt class="flex min-h-[30px] items-center text-fg-muted">
                    Due
                </dt>
                <dd class="flex min-h-[30px] min-w-0 items-center">
                    <DateChip
                        {task}
                        projectSlug={project.slug}
                        onUpdated={syncDeadline}
                    />
                </dd>
            </dl>

            {#if hasPlan}
                <h3 class="section-title mt-5 mb-1">Plan</h3>
                <dl
                    class="grid grid-cols-[88px_1fr] items-center gap-x-2.5 text-[12.5px]"
                >
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
                </dl>
            {/if}
        </aside>
    </div>
</AppShell>
