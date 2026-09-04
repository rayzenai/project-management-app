<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import {
        Activity,
        AtSign,
        Bell,
        CalendarClock,
        Check,
        CircleDot,
        UserPlus,
    } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import { formatTimeAgo } from '../../lib/format';

    // Laravel's paginator labels carry HTML entities ("&laquo; Previous");
    // drop the two arrow entities it uses instead of rendering the label as
    // HTML, leaving plain "Previous" / "Next".
    function paginationLabel(label: string): string {
        return label.replace(/&laquo;|&raquo;/g, '').trim();
    }

    type NotificationRow = {
        id: string;
        read_at: string | null;
        created_at: string | null;
        data: {
            kind?: string;
            title?: string;
            body?: string;
            action?: string;
            url?: string;
            task?: { slug: string; title: string; project_slug: string } | null;
            actor?: { name: string } | null;
            [key: string]: unknown;
        };
    };

    type PaginatorLink = { url: string | null; label: string; active: boolean };

    type Paginated<T> = {
        data: T[];
        links: PaginatorLink[];
    };

    type ActivityRow = {
        id: number;
        description: string | null;
        user_name: string | null;
        task_title: string | null;
        task_slug: string | null;
        project_slug: string | null;
        happened_at: string | null;
    };

    /**
     * Two tabs over one screen: the personal inbox and the workspace activity
     * feed. The server sends only the tab it rendered, so each list defaults
     * to an empty page.
     */
    let {
        tab = 'notifications',
        notifications = { data: [], links: [] },
        activity = { data: [], links: [] },
        filters,
        counts,
    }: {
        tab?: 'notifications' | 'activity';
        notifications?: Paginated<NotificationRow>;
        activity?: Paginated<ActivityRow>;
        filters: { scope: string; type: string | null };
        counts: Record<string, number>;
    } = $props();

    const TABS = [
        { key: 'notifications', label: 'Notifications' },
        { key: 'activity', label: 'Activity' },
    ] as const;

    function goTab(key: string) {
        router.get(
            '/workspace/notifications',
            key === 'activity' ? { tab: 'activity' } : {},
            { preserveState: false, preserveScroll: true },
        );
    }

    /** Day buckets for the activity feed, reusing the inbox's labels. */
    const activityGroups = $derived.by(() => {
        const out: { label: string; rows: ActivityRow[] }[] = [];

        for (const entry of activity.data) {
            const label = dayLabel(entry.happened_at);
            const last = out[out.length - 1];

            if (last && last.label === label) {
                last.rows.push(entry);
            } else {
                out.push({ label, rows: [entry] });
            }
        }

        return out;
    });

    const hasUnread = $derived(counts.unread > 0);

    // One icon per kind, so a glance down the column separates an assignment
    // from a mention from a deadline without reading a word.
    const KIND_ICON: Record<string, typeof Bell> = {
        task_assigned: UserPlus,
        task_status_changed: CircleDot,
        mentioned_in_comment: AtSign,
        task_deadline_due: CalendarClock,
    };

    const TYPE_CHIPS = [
        { key: null, label: 'All' },
        { key: 'assigned', label: 'Assigned' },
        { key: 'status', label: 'Status' },
        { key: 'mention', label: 'Mentions' },
        { key: 'deadline', label: 'Deadlines' },
    ];

    /**
     * The task title is the headline; the sentence beneath says what happened.
     * `action` omits the title (added later), so fall back to `body` — which
     * embeds it — for notifications written before that field existed.
     */
    function headline(n: NotificationRow): string {
        return n.data.task?.title ?? n.data.title ?? 'Notification';
    }

    function detail(n: NotificationRow): string {
        return n.data.action ?? n.data.body ?? '';
    }

    /** Day buckets, so a long inbox reads as a timeline rather than a wall. */
    function dayLabel(iso: string | null): string {
        if (!iso) {
            return 'Earlier';
        }

        const d = new Date(iso);
        const today = new Date();
        const startOf = (x: Date) =>
            new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
        const days = Math.round((startOf(today) - startOf(d)) / 86_400_000);

        if (days <= 0) {
            return 'Today';
        }

        if (days === 1) {
            return 'Yesterday';
        }

        if (days < 7) {
            return 'Earlier this week';
        }

        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'long',
            year:
                d.getFullYear() === today.getFullYear() ? undefined : 'numeric',
        });
    }

    const groups = $derived.by(() => {
        const out: { label: string; rows: NotificationRow[] }[] = [];

        for (const n of notifications.data) {
            const label = dayLabel(n.created_at);
            const last = out[out.length - 1];

            if (last && last.label === label) {
                last.rows.push(n);
            } else {
                out.push({ label, rows: [n] });
            }
        }

        return out;
    });

    function go(next: { scope?: string; type?: string | null }) {
        const scope = next.scope ?? filters.scope;
        const type = next.type === undefined ? filters.type : next.type;
        const params: Record<string, string> = {};

        if (scope === 'unread') {
            params.scope = 'unread';
        }

        if (type) {
            params.type = type;
        }

        router.get('/workspace/notifications', params, {
            preserveState: false,
            preserveScroll: true,
        });
    }

    function csrfToken(): string {
        return (
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? ''
        );
    }

    function markRead(n: NotificationRow) {
        if (n.read_at) {
            return;
        }

        n.read_at = new Date().toISOString();
        counts.unread = Math.max(0, counts.unread - 1);
        void fetch(`/workspace/notifications/${n.id}/read`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    }

    function open(n: NotificationRow) {
        markRead(n);
        const url = n.data.url;

        if (url) {
            router.visit(url);
        }
    }

    function markAllRead() {
        router.post(
            '/workspace/notifications/read-all',
            {},
            { preserveScroll: true },
        );
    }
</script>

<svelte:head><title>Notifications · Workspace</title></svelte:head>

{#snippet pager(links: PaginatorLink[])}
    {#if links.length > 3}
        <div class="flex flex-wrap items-center gap-1 px-5 py-5 lg:px-6">
            {#each links as link (link.label)}
                {#if link.url}
                    <button
                        type="button"
                        onclick={() =>
                            router.visit(link.url as string, {
                                preserveScroll: true,
                            })}
                        class={`inline-flex h-7 items-center rounded-md px-2.5 font-mono text-xs tabular-nums transition ${
                            link.active
                                ? 'bg-accent-soft text-accent'
                                : 'text-fg-muted hover:bg-hover hover:text-fg'
                        }`}>{paginationLabel(link.label)}</button
                    >
                {:else}
                    <span
                        class="inline-flex h-7 items-center px-2.5 font-mono text-xs text-fg-faint tabular-nums"
                        >{paginationLabel(link.label)}</span
                    >
                {/if}
            {/each}
        </div>
    {/if}
{/snippet}

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">
                {tab === 'activity' ? 'Activity' : 'Notifications'}
            </span>
            {#if tab === 'notifications' && counts.unread > 0}
                <span class="chip chip-accent">{counts.unread} unread</span>
            {/if}
        </div>
        {#if tab === 'notifications' && hasUnread}
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn" onclick={markAllRead}
                    >Mark all read</button
                >
            </div>
        {/if}
    {/snippet}

    <div class="flex h-12 items-center gap-1 border-b border-line px-5 lg:px-6">
        {#each TABS as t (t.key)}
            {@const active = tab === t.key}
            <button
                type="button"
                aria-pressed={active}
                onclick={() => goTab(t.key)}
                class={`inline-flex h-12 shrink-0 items-center gap-2 border-b-2 px-3 text-[13px] font-medium transition ${
                    active
                        ? 'border-accent text-fg'
                        : 'border-transparent text-fg-muted hover:text-fg'
                }`}
            >
                {t.label}
                {#if t.key === 'notifications' && counts.unread > 0}
                    <span class="section-count">{counts.unread}</span>
                {/if}
            </button>
        {/each}
    </div>

    {#if tab === 'notifications'}
        <div
            class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3.5 lg:px-6"
        >
            <div
                class="flex items-center rounded-md border border-line bg-surface-alt p-[2px]"
                role="group"
                aria-label="Read state"
            >
                {#each [{ key: 'all', label: 'All', n: counts.all }, { key: 'unread', label: 'Unread', n: counts.unread }] as opt (opt.key)}
                    <button
                        type="button"
                        aria-pressed={filters.scope === opt.key}
                        onclick={() => go({ scope: opt.key })}
                        class={`flex h-[22px] items-center gap-1.5 rounded-[5px] px-2 text-xs font-medium transition ${
                            filters.scope === opt.key
                                ? 'bg-surface text-fg'
                                : 'text-fg-muted hover:text-fg'
                        }`}
                    >
                        {opt.label}
                        <span class="font-mono text-fg-faint tabular-nums"
                            >{opt.n}</span
                        >
                    </button>
                {/each}
            </div>

            <span class="mx-1 h-4 w-px bg-line" aria-hidden="true"></span>

            {#each TYPE_CHIPS as chip (chip.label)}
                {@const active = filters.type === chip.key}
                {@const n = chip.key === null ? counts.all : counts[chip.key]}
                <button
                    type="button"
                    aria-pressed={active}
                    disabled={n === 0 && chip.key !== null}
                    onclick={() => go({ type: chip.key })}
                    class={`inline-flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-[13px] transition disabled:pointer-events-none disabled:opacity-40 ${
                        active
                            ? 'border-accent bg-accent-soft text-accent'
                            : 'border-line bg-surface-alt text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                >
                    {chip.label}
                    <span class="font-mono tabular-nums">{n}</span>
                </button>
            {/each}
        </div>

        {#if notifications.data.length === 0}
            <div class="flex flex-col items-center gap-2 px-4 py-16">
                <Bell class="h-5 w-5 text-fg-faint" />
                <p class="text-[13px] text-fg-muted">
                    {filters.scope === 'unread' || filters.type
                        ? 'Nothing here.'
                        : "You're all caught up."}
                </p>
                {#if filters.scope === 'unread' || filters.type}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => go({ scope: 'all', type: null })}
                        >Show everything</button
                    >
                {/if}
            </div>
        {:else}
            {#each groups as group (group.label)}
                <section>
                    <div class="group-head">
                        <span>{group.label}</span>
                        <span
                            class="text-xs font-normal text-fg-faint tabular-nums"
                            >{group.rows.length}</span
                        >
                    </div>

                    {#each group.rows as n (n.id)}
                        {@const unread = n.read_at === null}
                        {@const Icon = KIND_ICON[n.data.kind ?? ''] ?? Bell}
                        <div
                            class={`row min-h-[52px] py-2 ${unread ? 'bg-accent-soft/30' : ''}`}
                        >
                            <span
                                class={`flex w-4 shrink-0 justify-center ${unread ? 'text-accent' : 'text-fg-faint'}`}
                            >
                                <Icon class="h-3.5 w-3.5" />
                            </span>

                            <a
                                href={n.data.url ?? '#'}
                                onclick={(e) => {
                                    e.preventDefault();
                                    open(n);
                                }}
                                class="flex min-w-0 flex-1 items-center gap-3 text-left"
                            >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class={`block truncate text-[13px] ${unread ? 'font-medium text-fg' : 'text-fg'}`}
                                    >
                                        {headline(n)}
                                    </span>
                                    <span
                                        class="mt-0.5 block truncate text-xs text-fg-muted"
                                    >
                                        {detail(n)}
                                    </span>
                                </span>
                            </a>

                            {#if n.data.actor?.name}
                                <span class="hidden shrink-0 sm:block">
                                    <Avatar name={n.data.actor.name} />
                                </span>
                            {/if}

                            <span
                                class="w-16 shrink-0 text-right font-mono text-xs text-fg-faint tabular-nums"
                            >
                                {formatTimeAgo(n.created_at)}
                            </span>

                            <span class="flex w-7 shrink-0 justify-end">
                                {#if unread}
                                    <button
                                        type="button"
                                        title="Mark as read"
                                        aria-label="Mark as read"
                                        class="btn-icon"
                                        onclick={() => markRead(n)}
                                    >
                                        <Check class="h-3.5 w-3.5" />
                                    </button>
                                {/if}
                            </span>
                        </div>
                    {/each}
                </section>
            {/each}

            {@render pager(notifications.links)}
        {/if}
    {:else if activity.data.length === 0}
        <div class="flex flex-col items-center gap-2 px-4 py-16">
            <Activity class="h-5 w-5 text-fg-faint" />
            <p class="text-[13px] text-fg-muted">Nothing has happened yet.</p>
        </div>
    {:else}
        {#each activityGroups as group (group.label)}
            <section>
                <div class="group-head">
                    <span>{group.label}</span>
                    <span class="text-xs font-normal text-fg-faint tabular-nums"
                        >{group.rows.length}</span
                    >
                </div>

                {#each group.rows as entry (entry.id)}
                    <div class="row h-12">
                        <span class="shrink-0">
                            <Avatar name={entry.user_name ?? '?'} />
                        </span>

                        <div class="flex min-w-0 flex-1 items-baseline gap-2">
                            <span
                                class="shrink-0 text-[13px] font-medium text-fg"
                            >
                                {entry.user_name ?? 'Someone'}
                            </span>
                            <span class="truncate text-[13px] text-fg-muted">
                                {entry.description}
                            </span>
                        </div>

                        {#if entry.task_slug && entry.project_slug}
                            <a
                                href={`/workspace/projects/${entry.project_slug}/tasks/${entry.task_slug}`}
                                use:inertia
                                class="hidden w-56 shrink-0 truncate text-right text-[13px] text-fg-muted underline decoration-line underline-offset-2 hover:text-fg lg:block"
                                >{entry.task_title}</a
                            >
                        {:else}
                            <span class="hidden w-56 shrink-0 lg:block"></span>
                        {/if}

                        <span
                            class="w-16 shrink-0 text-right font-mono text-xs text-fg-faint tabular-nums"
                        >
                            {formatTimeAgo(entry.happened_at)}
                        </span>
                    </div>
                {/each}
            </section>
        {/each}

        {@render pager(activity.links)}
    {/if}
</AppShell>
