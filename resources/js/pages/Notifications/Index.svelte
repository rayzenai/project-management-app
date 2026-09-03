<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Bell, Check } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import { formatTimeAgo } from '../../lib/format';

    // Laravel's paginator labels carry HTML entities ("&laquo; Previous");
    // decode the two it uses instead of rendering the label as HTML.
    function paginationLabel(label: string): string {
        return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
    }

    type NotificationRow = {
        id: string;
        read_at: string | null;
        created_at: string | null;
        data: {
            title?: string;
            body?: string;
            url?: string;
            [key: string]: unknown;
        };
    };

    type Paginated = {
        data: NotificationRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };

    let { notifications }: { notifications: Paginated } = $props();

    const hasUnread = $derived(
        notifications.data.some((n) => n.read_at === null),
    );

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

<AppShell>
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Notifications</h1>
            <p class="mt-1 text-sm text-fg-muted">
                Activity that involves you — assignments, mentions, and updates.
            </p>
        </div>
        {#if hasUnread}
            <button
                type="button"
                class="rounded-md border border-line px-3 py-1.5 font-mono text-xs font-medium tracking-wide text-fg-muted hover:bg-surface-alt"
                onclick={markAllRead}>Mark all read</button
            >
        {/if}
    </div>

    {#if notifications.data.length === 0}
        <div
            class="rounded-xl border border-dashed border-line px-6 py-16 text-center text-sm text-fg-muted"
        >
            <Bell class="mx-auto mb-2 h-7 w-7 text-fg-faint" />

            You're all caught up — no notifications yet.
        </div>
    {:else}
        <ul class="flex flex-col gap-2">
            {#snippet body(n: NotificationRow, unread: boolean)}
                <span
                    class={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${unread ? 'bg-accent' : 'bg-transparent'}`}
                    aria-hidden="true"
                ></span>
                <div class="min-w-0 flex-1">
                    <div
                        class={`text-sm ${unread ? 'font-semibold' : 'font-medium'}`}
                    >
                        {n.data.title ?? 'Notification'}
                    </div>
                    {#if n.data.body}
                        <div class="mt-0.5 truncate text-sm text-fg-muted">
                            {n.data.body}
                        </div>
                    {/if}
                </div>
            {/snippet}

            {#each notifications.data as n (n.id)}
                {@const unread = n.read_at === null}
                {@const rowClass = `flex items-start gap-3 rounded-lg border px-4 py-3 transition ${
                    unread
                        ? 'border-accent/40 bg-accent/10'
                        : 'border-line bg-surface'
                } hover:border-line`}
                {@const navClass =
                    'flex min-w-0 flex-1 items-start gap-3 text-left'}
                <li class={rowClass}>
                    {#if n.data.url}
                        <a
                            href={n.data.url}
                            onclick={(e) => {
                                e.preventDefault();
                                open(n);
                            }}
                            class={navClass}
                        >
                            {@render body(n, unread)}
                        </a>
                    {:else}
                        <button
                            type="button"
                            onclick={() => open(n)}
                            class={navClass}
                        >
                            {@render body(n, unread)}
                        </button>
                    {/if}

                    <div class="flex shrink-0 items-center gap-2">
                        <span class="ws-eyebrow text-fg-faint">
                            {formatTimeAgo(n.created_at)}
                        </span>
                        {#if unread}
                            <button
                                type="button"
                                title="Mark as read"
                                aria-label="Mark as read"
                                class="rounded-md p-1 text-fg-muted transition hover:bg-surface-alt hover:text-fg"
                                onclick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    markRead(n);
                                }}
                            >
                                <Check class="h-4 w-4" />
                            </button>
                        {/if}
                    </div>
                </li>
            {/each}
        </ul>

        {#if notifications.links.length > 3}
            <div class="mt-6 flex flex-wrap items-center gap-1">
                {#each notifications.links as link (link.label)}
                    {#if link.url}
                        <button
                            type="button"
                            onclick={() =>
                                router.visit(link.url as string, {
                                    preserveScroll: true,
                                })}
                            class={`rounded-md px-3 py-1.5 font-mono text-xs transition ${
                                link.active
                                    ? 'bg-accent text-bg'
                                    : 'text-fg-muted hover:bg-surface-alt'
                            }`}>{paginationLabel(link.label)}</button
                        >
                    {:else}
                        <span
                            class="px-3 py-1.5 font-mono text-xs text-fg-faint"
                            >{paginationLabel(link.label)}</span
                        >
                    {/if}
                {/each}
            </div>
        {/if}
    {/if}
</AppShell>
