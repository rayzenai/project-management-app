<script lang="ts" module>
    /** The new rows already shown: quick add's flash is read once. */
    const shown: string[] = [];
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { ChevronDown, ChevronRight } from '@lucide/svelte';
    import { onMount, tick } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AppShell from '../components/AppShell.svelte';
    import FormsToFill from '../components/mywork/FormsToFill.svelte';
    import JoinedProjects from '../components/mywork/JoinedProjects.svelte';
    import MyWorkRow from '../components/mywork/MyWorkRow.svelte';
    import TodoRow from '../components/mywork/TodoRow.svelte';
    import QuickAddBar from '../components/QuickAddBar.svelte';
    import type { FormItem } from '../lib/forms/types';
    import { formatDay, isNepali, number, t } from '../lib/i18n';
    import { peek } from '../lib/peek.svelte';
    import type {
        MyWorkGroup,
        MyWorkGroupKey,
        MyWorkPayload,
        SharedProps,
    } from '../lib/types';

    /**
     * My work: everyone's landing page, one list of their own work grouped
     * by when it is due (MyWorkQuery). Later folds away when it is long, No
     * date and Snoozed start folded; each fold is remembered on this device.
     * Projects you were just added to sit above them, under the add field.
     * Your private to-dos ("Just for me") sit among the tasks by their date.
     * Nothing you add disappears: the row quick add just made (its flash's
     * `created_key`) opens its folded group, is scrolled to and glows once.
     */
    let {
        today,
        groups,
        joined,
        forms_to_fill: formsToFill = [],
    }: MyWorkPayload & { forms_to_fill?: FormItem[] } = $props();

    let quickAdd = $state<{ addTo: (projectId: number) => void } | null>(null);

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const context = $derived(shared.quickAddContext ?? null);

    const showingDone = $derived(
        new URLSearchParams(page.url.split('?')[1] ?? '').get('done') === '1',
    );
    const waiting = $derived(groups.some((g) => g.key !== 'done'));

    /** Kept in a constant: a bare ' · ' at the start of a block loses its spaces. */
    const DOT = ' · ';

    /** The groups' names in the reader's language (the server's are English). */
    const GROUP_LABELS = $derived<Record<MyWorkGroupKey, string>>({
        today: t('Today'),
        tomorrow: t('Tomorrow'),
        coming_up: t('Coming up'),
        later: t('Later'),
        asked_others: t('You asked others'),
        no_date: t('No date'),
        snoozed: t('Snoozed'),
        done: t('Done'),
    });

    /** Today's Gregorian date: the server's English, or written in Nepali. */
    const gregorian = $derived(
        isNepali()
            ? formatDay(today.date, {
                  weekday: 'long',
                  day: 'numeric',
                  month: 'long',
              })
            : today.label,
    );

    const FOLDABLE: MyWorkGroupKey[] = ['later', 'no_date', 'snoozed'];
    const STORE = 'workspace.mywork.open.';

    function remembered(): [MyWorkGroupKey, boolean][] {
        if (typeof window === 'undefined') {
            return [];
        }

        try {
            return FOLDABLE.flatMap((key): [MyWorkGroupKey, boolean][] => {
                const value = window.localStorage.getItem(STORE + key);

                return value === null ? [] : [[key, value === '1']];
            });
        } catch {
            return []; // private mode / blocked storage
        }
    }

    const opened = new SvelteMap<MyWorkGroupKey, boolean>(remembered());

    function isOpen(group: MyWorkGroup): boolean {
        if (!FOLDABLE.includes(group.key)) {
            return true;
        }

        return (
            opened.get(group.key) ??
            (group.key === 'later' && group.rows.length <= 5)
        );
    }

    function fold(group: MyWorkGroup): void {
        const next = !isOpen(group);
        opened.set(group.key, next);

        try {
            window.localStorage.setItem(STORE + group.key, next ? '1' : '0');
        } catch {
            /* nothing to remember it in; this visit still works */
        }
    }

    /** The row just added, while it glows. */
    let fresh = $state<string | null>(null);

    $effect(() => {
        const key = shared.flash?.created_key;

        if (!key || shown.includes(key)) {
            return;
        }

        shown.push(key);
        const group = groups.find((g) => g.rows.some((r) => r.key === key));

        if (!group) {
            return; // on others only, or on nobody: not on this list
        }

        if (!isOpen(group)) {
            // Opened for this visit; the fold the person chose stays saved.
            opened.set(group.key, true);
        }

        fresh = key;
        setTimeout(() => {
            if (fresh === key) {
                fresh = null;
            }
        }, 1600);

        void tick().then(() => {
            const row = document.querySelector(
                `[data-row-key="${CSS.escape(key)}"]`,
            );
            const box = row?.getBoundingClientRect();

            // Clear of the sticky top bar and a phone's tab bar.
            if (row && box && (box.top < 56 || box.bottom > innerHeight - 72)) {
                row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    });

    function toggleDone(): void {
        router.get('/workspace/my', showingDone ? {} : { done: 1 }, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    onMount(() => {
        peek.openFromUrl(
            groups.flatMap((g) =>
                g.rows.flatMap((r) =>
                    r.task ? [{ id: r.task.id, slug: r.task.slug }] : [],
                ),
            ),
        );
    });
</script>

<svelte:head><title>{t('My work')}</title></svelte:head>

{#snippet head(group: MyWorkGroup)}
    {GROUP_LABELS[group.key] ?? group.label}
    <span
        class="text-[13.5px] font-normal text-fg-muted tabular-nums sm:text-[13px]"
        >{number(group.rows.length)}</span
    >
{/snippet}

<AppShell flush>
    <div class="mx-auto w-full max-w-[720px] pb-16 sm:px-8 sm:pt-10">
        <header class="flex items-end gap-3 px-5 pt-6 sm:px-0 sm:pt-0">
            <div class="min-w-0 flex-1">
                <h1
                    class="text-[30px] leading-[36px] font-semibold tracking-[-0.02em] sm:text-[28px] sm:leading-[34px]"
                >
                    {t('My work')}
                </h1>
                <!-- In Nepali the Bikram Sambat date leads. -->
                <p
                    class="mt-1 text-[14.5px] text-fg-muted sm:mt-1.5 sm:text-[14px]"
                >
                    {#if today.bs && isNepali()}<span class="font-np"
                            >{today.bs}</span
                        >{DOT}{/if}{gregorian}{#if today.bs && !isNepali()}{DOT}<span
                            class="font-np">{today.bs}</span
                        >{/if}
                </p>
            </div>
            <button
                type="button"
                class={`btn h-[36px] shrink-0 px-3 sm:h-[32px] ${showingDone ? 'border-accent bg-accent-soft text-accent' : ''}`}
                aria-pressed={showingDone}
                onclick={toggleDone}
            >
                {t('Show done')}
            </button>
        </header>

        <div class="mt-4 px-5 sm:mt-[22px] sm:px-0">
            <QuickAddBar
                bind:this={quickAdd}
                projects={context?.projects ?? []}
                team={context?.team ?? []}
                currentMemberId={context?.currentMemberId ?? null}
                today={today.date}
            />
        </div>

        <FormsToFill forms={formsToFill} />

        <JoinedProjects
            {joined}
            onAdd={(projectId) => quickAdd?.addTo(projectId)}
        />

        {#if !waiting}
            <p class="mt-8 px-5 text-[15px] text-fg-muted sm:px-0">
                {joined.length > 0
                    ? t('Nothing assigned to you yet.')
                    : t('Nothing waiting for you.')}
            </p>
        {/if}

        {#each groups as group (group.key)}
            {@const foldable = FOLDABLE.includes(group.key)}
            {@const open = isOpen(group)}
            <section
                class="mt-2 sm:mt-[22px]"
                aria-labelledby={`group-${group.key}`}
            >
                <h2
                    id={`group-${group.key}`}
                    class="px-5 pt-4 pb-1.5 text-[15px] leading-[22px] font-semibold sm:px-0 sm:pt-0 sm:pb-1"
                >
                    {#if foldable}
                        <!-- 44px to tap, drawn at the height of the other heads. -->
                        <button
                            type="button"
                            class="-mx-[10px] -my-[11px] flex min-h-[44px] w-[calc(100%+20px)] items-center gap-2 rounded-lg px-[10px] text-left hover:bg-hover"
                            aria-expanded={open}
                            aria-controls={`rows-${group.key}`}
                            onclick={() => fold(group)}
                        >
                            {@render head(group)}
                            {#if open}
                                <ChevronDown
                                    class="size-4 shrink-0 text-fg-faint"
                                />
                            {:else}
                                <ChevronRight
                                    class="size-4 shrink-0 text-fg-faint"
                                />
                            {/if}
                        </button>
                    {:else}
                        <span class="flex items-baseline gap-2">
                            {@render head(group)}
                        </span>
                    {/if}
                </h2>

                {#if open}
                    <div id={`rows-${group.key}`}>
                        {#each group.rows as row (row.key)}
                            <div
                                data-row-key={row.key}
                                class={fresh === row.key ? 'fresh' : ''}
                            >
                                {#if row.kind === 'todo'}
                                    <TodoRow {row} {group} today={today.date} />
                                {:else}
                                    <MyWorkRow
                                        {row}
                                        {group}
                                        today={today.date}
                                        tomorrow={today.tomorrow}
                                    />
                                {/if}
                            </div>
                        {/each}
                    </div>
                {/if}
            </section>
        {/each}
    </div>
</AppShell>

<style>
    /* The row just added: the accent's soft ground, fading out. */
    .fresh > :global(*) {
        animation: fresh-row 1.5s ease-in;
    }

    @keyframes fresh-row {
        0%,
        30% {
            background-color: var(--ws-accent-soft);
        }

        100% {
            background-color: transparent;
        }
    }
</style>
