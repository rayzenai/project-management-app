<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Bell, Check } from '@lucide/svelte';
    import AppShell from '../../components/AppShell.svelte';
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

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Notifications</span>
        </div>
        {#if hasUnread}
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn" onclick={markAllRead}
                    >Mark all read</button
                >
            </div>
        {/if}
    {/snippet}

    {#if notifications.data.length === 0}
        <p class="px-4 py-5 text-fg-muted lg:px-8 lg:py-6">
            You're all caught up. No notifications yet.
        </p>
    {:else}
        <ul class="flex flex-col">
            {#snippet body(n: NotificationRow, unread: boolean)}
                <span class="relative shrink-0">
                    {#if unread}
                        <span
                            class="absolute top-1/2 -left-3 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-accent"
                            aria-hidden="true"
                        ></span>
                    {/if}
                    <Bell
                        class={`h-3.5 w-3.5 ${unread ? 'text-fg' : 'text-fg-faint'}`}
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <div
                        class={`truncate ${unread ? 'font-medium text-fg' : 'text-fg'}`}
                    >
                        {n.data.title ?? 'Notification'}
                    </div>
                    {#if n.data.body}
                        <div class="mt-0.5 truncate text-xs text-fg-muted">
                            {n.data.body}
                        </div>
                    {/if}
                </div>
            {/snippet}

            {#each notifications.data as n (n.id)}
                {@const unread = n.read_at === null}
                {@const navClass =
                    'flex min-w-0 flex-1 items-center gap-3 py-2 text-left'}
                <li class="row min-h-11 pl-6 lg:pl-8">
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

                    <div class="flex shrink-0 items-center gap-1.5">
                        <span
                            class="font-mono text-xs text-fg-faint tabular-nums"
                        >
                            {formatTimeAgo(n.created_at)}
                        </span>
                        {#if unread}
                            <button
                                type="button"
                                title="Mark as read"
                                aria-label="Mark as read"
                                class="btn-icon"
                                onclick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    markRead(n);
                                }}
                            >
                                <Check class="h-3.5 w-3.5" />
                            </button>
                        {/if}
                    </div>
                </li>
            {/each}
        </ul>

        {#if notifications.links.length > 3}
            <div class="flex flex-wrap items-center gap-1 px-4 py-4 lg:px-8">
                {#each notifications.links as link (link.label)}
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
    {/if}
</AppShell>
