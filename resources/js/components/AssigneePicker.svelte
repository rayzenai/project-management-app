<script lang="ts">
    import { Check, X } from '@lucide/svelte';
    import type { Member } from '../lib/types';
    import Avatar from './Avatar.svelte';

    let {
        team,
        selectedIds = $bindable([]),
        placeholder = 'Assign...',
        max = 1,
        flow = false,
    }: {
        team: Member[];
        selectedIds: number[];
        placeholder?: string;
        max?: number;
        /** Render the dropdown in-flow (expands the container) instead of as a clipped absolute popover. Use inside modals. */
        flow?: boolean;
    } = $props();

    let open = $state(false);
    let query = $state('');
    let rootEl = $state<HTMLDivElement | null>(null);
    let triggerEl = $state<HTMLDivElement | null>(null);

    /**
     * `restoreFocus` matters: the search field lives inside the list, so closing
     * it leaves focus on <body> and the surrounding dialog stops receiving key
     * events (a second Escape would do nothing). Hand focus back to the trigger
     * on keyboard closes; leave it alone when the user clicked elsewhere.
     */
    function close(restoreFocus = false): void {
        open = false;
        query = '';

        if (restoreFocus) {
            triggerEl?.focus();
        }
    }

    // Close on a click anywhere outside the picker. pointerdown (not click) so it
    // lands before the toggle's own click; the toggle is inside `rootEl`, so it
    // is excluded and keeps toggling normally.
    $effect(() => {
        if (!open || typeof document === 'undefined') {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!rootEl?.contains(event.target as Node)) {
                close();
            }
        };

        document.addEventListener('pointerdown', onPointerDown, true);

        return () =>
            document.removeEventListener('pointerdown', onPointerDown, true);
    });

    const filtered = $derived(
        query.trim() === ''
            ? team
            : team.filter(
                  (u) =>
                      u.name.toLowerCase().includes(query.toLowerCase()) ||
                      (u.email ?? '')
                          .toLowerCase()
                          .includes(query.toLowerCase()),
              ),
    );
    const selected = $derived(team.filter((u) => selectedIds.includes(u.id)));

    function toggle(memberId: number) {
        if (selectedIds.includes(memberId)) {
            selectedIds = selectedIds.filter((id) => id !== memberId);
        } else if (max === 1) {
            selectedIds = [memberId];
            close(true);
        } else if (selectedIds.length < max) {
            selectedIds = [...selectedIds, memberId];
        }
    }

    function remove(memberId: number) {
        selectedIds = selectedIds.filter((id) => id !== memberId);
    }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
    bind:this={rootEl}
    class="relative"
    onkeydown={(e) => {
        // Escape closes the list, not the dialog the picker sits in.
        if (e.key === 'Escape' && open) {
            e.preventDefault();
            e.stopPropagation();
            close(true);
        }
    }}
>
    <div
        bind:this={triggerEl}
        role="button"
        tabindex="0"
        aria-haspopup="listbox"
        aria-expanded={open}
        class="flex min-h-[30px] w-full flex-wrap items-center gap-1.5 rounded-md border border-line bg-surface px-2 py-1 text-left text-[13px] transition hover:bg-hover"
        onclick={() => (open = !open)}
        onkeydown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open = !open;
            }
        }}
    >
        {#if selected.length === 0}
            <span class="text-fg-faint">{placeholder}</span>
        {/if}
        {#each selected as user (user.id)}
            <span class="chip chip-accent gap-1 pr-1">
                {user.name}
                <button
                    type="button"
                    aria-label="Remove"
                    class="inline-flex h-4 w-4 items-center justify-center rounded-sm text-accent hover:bg-accent-soft"
                    onclick={(e) => {
                        e.stopPropagation();
                        remove(user.id);
                    }}
                >
                    <X class="h-3 w-3" />
                </button>
            </span>
        {/each}
    </div>

    {#if open}
        <div
            class={flow
                ? 'popover mt-1 max-h-72 overflow-auto px-1'
                : 'popover absolute right-0 left-0 z-30 mt-1 max-h-72 overflow-auto px-1'}
        >
            <div class="px-1 pt-1 pb-1.5">
                <input
                    type="text"
                    bind:value={query}
                    placeholder="Search people"
                    class="input"
                />
            </div>
            <ul class="max-h-56 overflow-auto">
                {#each filtered as user (user.id)}
                    {@const sel = selectedIds.includes(user.id)}
                    <li>
                        <button
                            type="button"
                            class={`menu-item ${sel ? 'bg-accent-soft text-fg' : ''}`}
                            onclick={() => toggle(user.id)}
                        >
                            <Avatar name={user.name} size="md" />
                            <span class="min-w-0 flex-1 leading-tight">
                                <span class="block truncate font-medium text-fg"
                                    >{user.name}</span
                                >
                                <span
                                    class="block truncate text-xs text-fg-muted"
                                    >{user.email}</span
                                >
                            </span>
                            {#if sel}
                                <Check
                                    class="h-3.5 w-3.5 shrink-0 text-accent"
                                />
                            {/if}
                        </button>
                    </li>
                {:else}
                    <li class="px-2 py-2 text-xs text-fg-faint">No matches.</li>
                {/each}
            </ul>
        </div>
    {/if}
</div>
