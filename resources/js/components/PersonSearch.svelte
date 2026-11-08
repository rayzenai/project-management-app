<script lang="ts">
    import { Search, UserPlus } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import { t } from '../lib/i18n';
    import type { FoundPerson } from '../lib/types';
    import Avatar from './Avatar.svelte';

    /**
     * The one search for a person to add or choose: sharing a document, a
     * form's people, a project's team, a repository's access. It asks `url`
     * (`?q=` or `&q=` added; answers `{data: [...]}`) once two letters are
     * typed, a moment after the last key, and a late answer never replaces
     * a newer one. Each answer is one row (avatar, name, `detail`, the
     * designation unless given); choosing it calls `onpick` and clears the
     * search. `beside` sits next to the box (the access to give).
     */
    let {
        url,
        label,
        hideLabel = false,
        placeholder,
        empty,
        detail = (person: FoundPerson) => person.designation,
        disabled = false,
        input = $bindable(null),
        beside,
        onpick,
    }: {
        url: string;
        label: string;
        /** The label for a screen reader only. */
        hideLabel?: boolean;
        /** "Type a name" unless given. */
        placeholder?: string;
        /** When nobody matches; "Nobody by that name." unless given. */
        empty?: string;
        detail?: (person: FoundPerson) => string | null;
        disabled?: boolean;
        /** The search box, for a caller that puts the focus back in it. */
        input?: HTMLInputElement | null;
        beside?: Snippet;
        onpick: (person: FoundPerson) => void;
    } = $props();

    const uid = $props.id();
    let query = $state('');
    let results = $state<FoundPerson[]>([]);
    let searched = $state(false);
    let failed = $state(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;

    function reset(): void {
        clearTimeout(timer);
        seq++;
        query = '';
        results = [];
        searched = failed = false;
    }

    function search(): void {
        clearTimeout(timer);
        const q = query.trim();

        if (q.length < 2) {
            seq++;
            results = [];
            searched = failed = false;

            return;
        }

        timer = setTimeout(async () => {
            const mine = ++seq;

            try {
                const response = await fetch(
                    `${url}${url.includes('?') ? '&' : '?'}q=${encodeURIComponent(q)}`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const json = (await response.json()) as {
                    data?: FoundPerson[];
                };

                if (mine === seq) {
                    results = json.data ?? [];
                    searched = true;
                    failed = false;
                }
            } catch {
                if (mine === seq) {
                    results = [];
                    searched = false;
                    failed = true;
                }
            }
        }, 200);
    }

    function pick(person: FoundPerson): void {
        reset();
        onpick(person);
    }
</script>

<div>
    <label for="{uid}-q" class={hideLabel ? 'sr-only' : 'label mb-1 block'}
        >{label}</label
    >
    <div class="flex gap-2">
        <div class="relative min-w-0 flex-1">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
            />
            <input
                bind:this={input}
                id="{uid}-q"
                type="search"
                class="input pl-8"
                bind:value={query}
                oninput={search}
                placeholder={placeholder ?? t('Type a name')}
                autocomplete="off"
                {disabled}
            />
        </div>
        {@render beside?.()}
    </div>
    {#if failed}
        <p class="mt-1 text-xs text-danger">
            {t("The search didn't work. Try again.")}
        </p>
    {:else if searched && results.length === 0}
        <p class="mt-1 text-xs text-fg-muted">
            {empty ?? t('Nobody by that name.')}
        </p>
    {:else if results.length > 0}
        <ul class="mt-1 border-y border-line-soft">
            {#each results as person (person.id)}
                {@const line = detail(person)}
                <li>
                    <button
                        type="button"
                        class="row w-full gap-2.5 px-1 py-1.5 text-left"
                        {disabled}
                        onclick={() => pick(person)}
                    >
                        <Avatar name={person.name} size="md" />
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block truncate font-medium text-fg"
                                >{person.name}</span
                            >
                            {#if line}<span
                                    class="block truncate text-xs text-fg-muted"
                                    >{line}</span
                                >{/if}
                        </span>
                        <UserPlus class="h-4 w-4 shrink-0 text-fg-faint" />
                    </button>
                </li>
            {/each}
        </ul>
    {/if}
</div>
