<script lang="ts">
    import { page, router, useForm } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import AssigneePicker from '../../components/AssigneePicker.svelte';
    import CommentThread from '../../components/CommentThread.svelte';
    import PillGroup from '../../components/PillGroup.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import StatusBadge from '../../components/StatusBadge.svelte';
    import { initials, formatDate } from '../../lib/format';
    import type {
        Assignment,
        Comment,
        Contact,
        Member,
        Note,
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
        statuses,
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

    type TabId = 'overview' | 'todos' | 'notes' | 'contacts';
    // Comments live in the right column now (not a tab); ?tab=comments falls back to overview.
    const tabIds: TabId[] = ['overview', 'todos', 'notes', 'contacts'];

    function tabFromUrl(url: string): TabId {
        const requested = new URL(url, 'http://localhost').searchParams.get(
            'tab',
        );

        return tabIds.includes(requested as TabId)
            ? (requested as TabId)
            : 'overview';
    }

    let activeTab: TabId = $state(tabFromUrl(page.url));

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

    const uid = $props.id();
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

    const noteForm = useForm({ body: '', type: 'general', happened_at: '' });
    const contactForm = useForm({
        name: '',
        role: '',
        email: '',
        phone: '',
        organization: '',
        notes: '',
    });
    const assignForm = useForm({
        member_id: 0,
        role: '',
    });

    let pickerSelected = $state<number[]>([]);
    let lastAttemptedAssignee = 0;

    // Assign immediately when a teammate is picked — no separate Assign button.
    // `lastAttemptedAssignee` stops a failed request from retrying in a loop.
    $effect(() => {
        const id = pickerSelected[0];

        if (id && id !== lastAttemptedAssignee && !assignForm.processing) {
            lastAttemptedAssignee = id;
            assign();
        }
    });

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

    function handleSaveShortcut(e: KeyboardEvent) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();

            if (activeTab === 'todos') {
                submitTodo();
            } else if (activeTab === 'notes') {
                submitNote();
            } else if (activeTab === 'contacts') {
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

    function assign() {
        if (pickerSelected.length === 0) {
            return;
        }

        assignForm.member_id = pickerSelected[0];
        assignForm.post(`/workspace/tasks/${task.id}/assignments`, {
            preserveScroll: true,
            onSuccess: () => {
                pickerSelected = [];
                lastAttemptedAssignee = 0;
                assignForm.reset();
            },
        });
    }

    function unassign(assignment: Assignment) {
        if (!confirm(`Remove ${assignment.member?.name} from this task?`)) {
            return;
        }

        router.delete(`/workspace/assignments/${assignment.id}`, {
            preserveScroll: true,
        });
    }

    function deleteNote(note: Note) {
        if (!confirm('Delete this note?')) {
            return;
        }

        router.delete(`/workspace/notes/${note.id}`, { preserveScroll: true });
    }
</script>

<svelte:window onkeydown={handleSaveShortcut} />

<svelte:head><title>{task.title} · Workspace</title></svelte:head>

<AppShell>
    {#if todoForm.processing || noteForm.processing}
        <div
            class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center"
            aria-hidden="true"
        >
            <div
                class="bg-surface/90 rounded-full border border-line p-3 shadow-lg backdrop-blur-sm"
            >
                <Spinner size={28} />
            </div>
        </div>
    {/if}

    <nav class="text-fg-muted mb-3 text-xs">
        <a href="/workspace/projects" class="hover:underline">Projects</a> /
        <a href={`/workspace/projects/${project.slug}`} class="hover:underline"
            >{project.title}</a
        >
        /
        <span>{task.short_title || task.title}</span>
    </nav>

    <header class="mb-6">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                {#if task.item_number}
                    <div
                        class="bg-surface-alt text-fg-muted mb-1 inline-flex items-center rounded px-2 py-0.5 font-mono text-xs"
                    >
                        #{task.item_number}
                    </div>
                {/if}
                <h1 class="text-2xl font-bold tracking-tight">{task.title}</h1>
                {#if task.title_np}
                    <div class="text-fg-muted mt-1 text-base">
                        {task.title_np}
                    </div>
                {/if}
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <StatusBadge
                        status={task.status}
                        label={task.status_label}
                    />
                    {#if task.deadline_at}
                        <span class="text-fg-muted text-xs">
                            Due {formatDate(task.deadline_at)} · {task.days_relative_label}
                        </span>
                    {/if}
                    {#if task.progress > 0}
                        <span class="text-fg-muted text-xs"
                            >{task.progress}% complete</span
                        >
                    {/if}
                    {#if task.responsible_ministry}
                        <span class="text-fg-muted text-xs"
                            >· {task.responsible_ministry}</span
                        >
                    {/if}
                </div>
            </div>
            <div class="flex shrink-0 gap-2">
                <button
                    type="button"
                    onclick={deleteTask}
                    class="bg-surface rounded-md border border-danger/30 px-3 py-1.5 text-sm text-danger hover:bg-danger/10"
                    >Delete</button
                >
            </div>
        </div>
    </header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="mb-4 flex gap-1 border-b border-line">
                {#each [['overview', 'Overview'], ['todos', `My todos (${subtasks.filter((s) => !s.is_done).length})`], ['notes', `Notes (${notes.length})`], ['contacts', `Contacts (${contacts.length})`]] as [key, label] (key)}
                    <button
                        type="button"
                        class="border-b-2 px-3 py-2 text-sm font-medium transition"
                        class:border-accent={activeTab === key}
                        class:text-accent={activeTab === key}
                        class:border-transparent={activeTab !== key}
                        class:text-fg-muted={activeTab !== key}
                        onclick={() => (activeTab = key as typeof activeTab)}
                        >{label}</button
                    >
                {/each}
            </div>

            <p class="text-fg-faint mb-3 text-xs">
                Press
                <kbd
                    class="bg-surface-alt rounded border border-line px-1 font-sans"
                    >Ctrl</kbd
                >/<kbd
                    class="bg-surface-alt rounded border border-line px-1 font-sans"
                    >⌘</kbd
                >
                +
                <kbd
                    class="bg-surface-alt rounded border border-line px-1 font-sans"
                    >S</kbd
                >
                to quick save
            </p>

            {#if activeTab === 'overview'}
                <form
                    onsubmit={saveEdit}
                    class="bg-surface rounded-xl border border-line p-4"
                >
                    <div class="space-y-3">
                        <div>
                            <label
                                for={`${uid}-title`}
                                class="text-fg-muted mb-1 block text-xs font-medium"
                                >Title</label
                            >
                            <input
                                id={`${uid}-title`}
                                type="text"
                                bind:value={editForm.title}
                                class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                            />
                        </div>
                        <div>
                            <label
                                for={`${uid}-description`}
                                class="text-fg-muted mb-1 block text-xs font-medium"
                                >Description</label
                            >
                            <textarea
                                id={`${uid}-description`}
                                bind:value={editForm.description}
                                rows="4"
                                placeholder="Add a description..."
                                class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                            ></textarea>
                            {#if task.description_np}
                                <p
                                    class="text-fg-muted mt-2 text-sm whitespace-pre-wrap"
                                >
                                    {task.description_np}
                                </p>
                            {/if}
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label
                                    for={`${uid}-status`}
                                    class="text-fg-muted mb-1 block text-xs font-medium"
                                    >Status</label
                                >
                                <select
                                    id={`${uid}-status`}
                                    bind:value={editForm.status}
                                    class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                                >
                                    {#each statuses as s (s.value)}
                                        <option value={s.value}
                                            >{s.label}</option
                                        >
                                    {/each}
                                </select>
                            </div>
                            <div>
                                <label
                                    for={`${uid}-progress`}
                                    class="text-fg-muted mb-1 flex items-center justify-between text-xs font-medium"
                                >
                                    <span>Progress</span>
                                    <span class="text-fg font-semibold"
                                        >{editForm.task_progress}%</span
                                    >
                                </label>
                                <input
                                    id={`${uid}-progress`}
                                    type="range"
                                    min="0"
                                    max="100"
                                    step="5"
                                    bind:value={editForm.task_progress}
                                    class="accent-accent mt-2.5 w-full"
                                />
                            </div>
                            <div>
                                <label
                                    for={`${uid}-deadline`}
                                    class="text-fg-muted mb-1 block text-xs font-medium"
                                    >Due date</label
                                >
                                <input
                                    id={`${uid}-deadline`}
                                    type="date"
                                    bind:value={editForm.deadline_at}
                                    class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                                />
                            </div>
                        </div>
                        <div>
                            <span
                                class="text-fg-muted mb-1 block text-xs font-medium"
                                >Priority</span
                            >
                            <PillGroup
                                dot
                                bind:value={editForm.priority}
                                options={[
                                    {
                                        value: 'low',
                                        label: 'Low',
                                        tone: 'neutral',
                                    },
                                    {
                                        value: 'medium',
                                        label: 'Medium',
                                        tone: 'amber',
                                    },
                                    {
                                        value: 'high',
                                        label: 'High',
                                        tone: 'orange',
                                    },
                                    {
                                        value: 'urgent',
                                        label: 'Urgent',
                                        tone: 'red',
                                    },
                                ]}
                            />
                        </div>
                        <div>
                            <label
                                for={`${uid}-status-note`}
                                class="text-fg-muted mb-1 block text-xs font-medium"
                                >Status note</label
                            >
                            <textarea
                                id={`${uid}-status-note`}
                                bind:value={editForm.status_note}
                                rows="3"
                                placeholder="What's the latest on this?"
                                class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                            ></textarea>
                        </div>
                        <div>
                            <label
                                for={`${uid}-source-url`}
                                class="text-fg-muted mb-1 block text-xs font-medium"
                                >Source URL</label
                            >
                            <input
                                id={`${uid}-source-url`}
                                type="url"
                                bind:value={editForm.source_url}
                                placeholder="https://..."
                                class="bg-surface text-fg w-full rounded-md border border-line px-3 py-1.5 text-sm"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-end gap-2">
                        {#if editForm.isDirty}
                            <span class="text-fg-muted mr-auto text-xs"
                                >Unsaved changes</span
                            >
                            <button
                                type="button"
                                onclick={() => editForm.reset()}
                                class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                                >Discard</button
                            >
                        {/if}
                        <button
                            type="submit"
                            disabled={editForm.processing || !editForm.isDirty}
                            title="Save (⌘S / Ctrl+S)"
                            class="bg-accent text-bg hover:bg-accent-dim rounded-md px-3 py-1.5 text-sm font-semibold disabled:opacity-50"
                            >Save</button
                        >
                    </div>
                </form>
            {/if}

            {#if activeTab === 'todos'}
                <form
                    onsubmit={addTodo}
                    class="bg-surface mb-4 rounded-xl border border-line p-3"
                >
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            bind:value={todoForm.body}
                            placeholder="Add a todo for yourself..."
                            class="bg-surface text-fg flex-1 rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                        <input
                            type="date"
                            bind:value={todoForm.due_at}
                            class="bg-surface text-fg rounded-md border border-line px-2 py-1.5 text-sm"
                        />
                        <button
                            type="submit"
                            disabled={todoForm.processing ||
                                !todoForm.body.trim()}
                            class="bg-accent text-bg hover:bg-accent-dim inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold disabled:opacity-50"
                        >
                            {#if todoForm.processing}
                                <Spinner size={14} class="text-bg" />Adding…
                            {:else}
                                Add
                            {/if}
                        </button>
                    </div>
                    <p class="text-fg-muted mt-1.5 text-xs">
                        Todos are private to you. They show up in My Workspace
                        too.
                    </p>
                </form>

                <ul class="space-y-2">
                    {#each subtasks as t (t.id)}
                        <li
                            class="group bg-surface flex items-start gap-3 rounded-xl border border-line p-3"
                        >
                            <button
                                type="button"
                                class="text-bg mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border-2 border-line text-xs transition hover:border-success"
                                class:bg-success={t.is_done}
                                class:border-success={t.is_done}
                                onclick={() => toggleTodo(t)}
                                >{t.is_done ? '✓' : ''}</button
                            >
                            <div class="min-w-0 flex-1 text-sm">
                                <p
                                    class:line-through={t.is_done}
                                    class:text-fg-faint={t.is_done}
                                >
                                    {t.body}
                                </p>
                                {#if t.due_at}
                                    <p class="text-fg-muted mt-0.5 text-xs">
                                        due {formatDate(t.due_at)}
                                    </p>
                                {/if}
                            </div>
                            <button
                                type="button"
                                class="text-fg-faint invisible group-hover:visible hover:text-danger"
                                onclick={() => deleteTodo(t)}
                                title="Delete">×</button
                            >
                        </li>
                    {:else}
                        <p class="text-sm text-fg-muted">No todos yet.</p>
                    {/each}
                </ul>
            {/if}

            {#if activeTab === 'notes'}
                <form
                    onsubmit={addNote}
                    class="bg-surface mb-4 rounded-xl border border-line p-3"
                >
                    <textarea
                        bind:value={noteForm.body}
                        rows="2"
                        placeholder="Add a note..."
                        class="bg-surface text-fg w-full resize-none rounded-md border border-line px-3 py-1.5 text-sm"
                    ></textarea>
                    <div class="mt-2 flex items-center gap-2">
                        <select
                            bind:value={noteForm.type}
                            class="bg-surface text-fg rounded-md border border-line px-2 py-1 text-xs"
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
                            class="bg-surface text-fg rounded-md border border-line px-2 py-1 text-xs"
                        />
                        <div class="flex-1"></div>
                        <button
                            type="submit"
                            disabled={noteForm.processing ||
                                !noteForm.body.trim()}
                            class="bg-accent text-bg hover:bg-accent-dim inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-semibold disabled:opacity-50"
                        >
                            {#if noteForm.processing}
                                <Spinner size={12} class="text-bg" />Adding…
                            {:else}
                                Add note
                            {/if}
                        </button>
                    </div>
                </form>

                <div class="space-y-2">
                    {#each notes as note (note.id)}
                        <div
                            class="bg-surface rounded-xl border border-line p-3"
                        >
                            <div
                                class="text-fg-muted mb-1 flex items-center gap-2 text-xs"
                            >
                                <span class="text-fg font-medium"
                                    >{note.user?.name ?? 'Someone'}</span
                                >
                                <span>· {note.type_label}</span>
                                {#if note.happened_at}<span
                                        >· {formatDate(note.happened_at)}</span
                                    >{/if}
                                <div class="flex-1"></div>
                                <button
                                    type="button"
                                    onclick={() => deleteNote(note)}
                                    class="text-fg-faint hover:text-danger"
                                    title="Delete note">×</button
                                >
                            </div>
                            <p class="text-sm whitespace-pre-wrap">
                                {note.body}
                            </p>
                        </div>
                    {:else}
                        <p class="text-sm text-fg-muted">No notes yet.</p>
                    {/each}
                </div>
            {/if}

            {#if activeTab === 'contacts'}
                <form
                    onsubmit={addContact}
                    class="bg-surface mb-4 rounded-xl border border-line p-3"
                >
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <input
                            type="text"
                            placeholder="Name *"
                            bind:value={contactForm.name}
                            required
                            class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                        <input
                            type="text"
                            placeholder="Role"
                            bind:value={contactForm.role}
                            class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                        <input
                            type="text"
                            placeholder="Organization"
                            bind:value={contactForm.organization}
                            class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                        <input
                            type="email"
                            placeholder="Email"
                            bind:value={contactForm.email}
                            class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                        <input
                            type="tel"
                            placeholder="Phone"
                            bind:value={contactForm.phone}
                            class="bg-surface text-fg rounded-md border border-line px-3 py-1.5 text-sm"
                        />
                    </div>
                    <div class="mt-2 flex justify-end">
                        <button
                            type="submit"
                            disabled={contactForm.processing ||
                                !contactForm.name.trim()}
                            class="bg-accent text-bg hover:bg-accent-dim rounded-md px-3 py-1 text-xs font-semibold disabled:opacity-50"
                            >Add contact</button
                        >
                    </div>
                </form>

                <div class="space-y-2">
                    {#each contacts as contact (contact.id)}
                        <div
                            class="bg-surface rounded-xl border border-line p-3"
                        >
                            <div class="text-sm font-medium">
                                {contact.name}
                            </div>
                            {#if contact.role || contact.organization}
                                <div class="text-fg-muted text-xs">
                                    {[contact.role, contact.organization]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            {/if}
                            <div
                                class="text-fg-muted mt-1 flex flex-wrap gap-3 text-xs"
                            >
                                {#if contact.email}<a
                                        href={`mailto:${contact.email}`}
                                        class="hover:underline"
                                        >✉ {contact.email}</a
                                    >{/if}
                                {#if contact.phone}<span>☎ {contact.phone}</span
                                    >{/if}
                            </div>
                        </div>
                    {:else}
                        <p class="text-sm text-fg-muted">No contacts yet.</p>
                    {/each}
                </div>
            {/if}
        </div>

        <aside class="space-y-4">
            <section class="bg-surface rounded-xl border border-line p-4">
                <h3
                    class="text-fg-muted mb-3 text-xs font-semibold tracking-wider uppercase"
                >
                    Assignees ({task.assignments?.length ?? 0})
                </h3>
                <div class="space-y-2">
                    {#each task.assignments ?? [] as a (a.id)}
                        <div class="flex items-center gap-2">
                            <span
                                class="bg-surface-alt text-fg-muted flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold"
                            >
                                {initials(a.member?.name)}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {a.member?.name}
                                </div>
                                <div class="text-fg-muted truncate text-xs">
                                    {a.personal_progress}%
                                </div>
                            </div>
                            <button
                                type="button"
                                onclick={() => unassign(a)}
                                class="text-fg-faint hover:text-danger"
                                title="Unassign">×</button
                            >
                        </div>
                    {:else}
                        <p class="text-sm italic text-fg-muted">
                            No one assigned yet.
                        </p>
                    {/each}
                </div>
                <div class="mt-3 border-t border-line pt-3">
                    <AssigneePicker
                        {team}
                        bind:selectedIds={pickerSelected}
                        placeholder="Add assignee..."
                    />
                </div>
            </section>

            <section class="bg-surface rounded-xl border border-line p-4">
                <h3
                    class="text-fg-muted mb-3 text-xs font-semibold tracking-wider uppercase"
                >
                    Comments ({comments.length})
                </h3>
                <CommentThread {comments} {task} members={team} embedded />
            </section>

            {#if task.category_label || task.deadline_label || task.responsible_ministry}
                <section class="bg-surface rounded-xl border border-line p-4">
                    <h3
                        class="text-fg-muted mb-3 text-xs font-semibold tracking-wider uppercase"
                    >
                        Plan metadata
                    </h3>
                    <dl class="space-y-2 text-sm">
                        {#if task.category_label}
                            <div class="flex items-center justify-between">
                                <dt class="text-fg-muted">Category</dt>
                                <dd>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        style="background-color: {task.category_color}15; color: {task.category_color}; --tw-ring-color: {task.category_color}40;"
                                        >{task.category_label}</span
                                    >
                                </dd>
                            </div>
                        {/if}
                        {#if task.deadline_label}
                            <div class="flex items-center justify-between">
                                <dt class="text-fg-muted">Deadline type</dt>
                                <dd class="text-fg">{task.deadline_label}</dd>
                            </div>
                        {/if}
                        {#if task.responsible_ministry}
                            <div class="flex items-center justify-between">
                                <dt class="text-fg-muted">Ministry</dt>
                                <dd class="text-fg">
                                    {task.responsible_ministry}
                                </dd>
                            </div>
                        {/if}
                    </dl>
                </section>
            {/if}
        </aside>
    </div>
</AppShell>
