<script lang="ts">
    import { UserPlus } from '@lucide/svelte';
    import { send } from '../../../lib/forms/http';
    import type { PersonRef } from '../../../lib/forms/types';
    import Avatar from '../../Avatar.svelte';

    /** Find a person by name (from `url`, which takes `q`) and choose them. */
    let {
        url,
        label,
        placeholder = 'Type a name',
        onpick,
        disabled = false,
    }: {
        url: string;
        label: string;
        placeholder?: string;
        onpick: (person: PersonRef) => void;
        disabled?: boolean;
    } = $props();

    const uid = $props.id();
    let query = $state('');
    let results = $state<PersonRef[]>([]);
    let searched = $state(false);
    let failed = $state(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;

    function search() {
        clearTimeout(timer);
        const q = query.trim();

        if (q.length < 2) {
            results = [];
            searched = false;

            return;
        }

        timer = setTimeout(async () => {
            const mine = ++seq;

            try {
                const sep = url.includes('?') ? '&' : '?';
                const { data } = await send<PersonRef[]>(
                    'GET',
                    `${url}${sep}q=${encodeURIComponent(q)}`,
                );

                if (mine === seq) {
                    results = data;
                    searched = true;
                    failed = false;
                }
            } catch {
                if (mine === seq) {
                    failed = true;
                }
            }
        }, 200);
    }
</script>

<div>
    <label for="{uid}-q" class="label mb-1 block">{label}</label>
    <input
        id="{uid}-q"
        class="input"
        bind:value={query}
        oninput={search}
        {placeholder}
        autocomplete="off"
        {disabled}
    />
    {#if failed}
        <p class="mt-1 text-xs text-danger">
            The search didn't work. Try again.
        </p>
    {:else if searched && results.length === 0}
        <p class="mt-1 text-xs text-fg-muted">Nobody by that name.</p>
    {:else if results.length}
        <ul class="mt-1 border-y border-line-soft">
            {#each results as person (person.id)}
                <li>
                    <button
                        type="button"
                        class="row w-full gap-2.5 px-1 py-1.5 text-left"
                        onclick={() => {
                            onpick(person);
                            query = '';
                            results = [];
                            searched = false;
                        }}
                    >
                        <Avatar name={person.name} size="md" />
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block truncate font-medium text-fg"
                                >{person.name}</span
                            >
                            {#if person.designation}<span
                                    class="block truncate text-xs text-fg-muted"
                                    >{person.designation}</span
                                >{/if}
                        </span>
                        <UserPlus class="h-4 w-4 shrink-0 text-fg-faint" />
                    </button>
                </li>
            {/each}
        </ul>
    {/if}
</div>
