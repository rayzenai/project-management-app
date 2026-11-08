<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { UserPlus, X } from '@lucide/svelte';
    import { msg, t } from '../../lib/i18n';
    import type {
        DocumentAccess,
        DocumentItem,
        DocumentShareItem,
        DocumentSharing,
        PersonRef,
    } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * Who has a document beyond its home: everyone in its organization (Off,
     * Can view, Can edit) and chosen people of that organization, each Can
     * edit or Can view. Only whoever may share it opens this
     * (document.can_share). One change at a time; the list is asked for
     * again after each, as the repository access panel does.
     */
    let {
        document: doc,
        open = $bindable(false),
        onchanged,
    }: {
        document: DocumentItem;
        open?: boolean;
        /** After a change saved: the page refreshes what it shows. */
        onchanged?: () => void;
    } = $props();

    const uid = $props.id();
    const url = $derived(`/workspace/documents/${doc.id}`);
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const LEVELS: { value: DocumentAccess; label: string }[] = [
        { value: 'edit', label: msg('Can edit') },
        { value: 'view', label: msg('Can view') },
    ];

    let sharing = $state<DocumentSharing | null>(null);
    let failed = $state(false);
    let saving = $state(false);
    let error = $state<string | null>(null);
    let query = $state('');
    let permission = $state<DocumentAccess>('edit');
    let results = $state<PersonRef[]>([]);
    let searched = $state(false);
    let searchFailed = $state(false);
    let searchInput = $state<HTMLInputElement | null>(null);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;
    let searchSeq = 0;
    let pressedBackdrop = false;

    async function load(): Promise<void> {
        const mine = ++seq;
        failed = false;

        try {
            const response = await fetch(`${url}/sharing`, {
                headers,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = (await response.json()) as { data: DocumentSharing };

            if (mine === seq) {
                sharing = json.data;
            }
        } catch {
            if (mine === seq) {
                failed = true;
            }
        }
    }

    $effect(() => {
        if (!open) {
            return;
        }

        sharing = null;
        error = null;
        query = '';
        results = [];
        void load();
        queueMicrotask(() => searchInput?.focus());
    });

    $effect(() => {
        if (!open) {
            return;
        }

        window.document.body.style.overflow = 'hidden';

        return () => {
            window.document.body.style.overflow = '';
        };
    });

    function close() {
        open = false;
    }

    function search() {
        clearTimeout(timer);
        const q = query.trim();

        if (q.length < 2) {
            searchSeq++;
            results = [];
            searched = searchFailed = false;

            return;
        }

        timer = setTimeout(async () => {
            const mine = ++searchSeq;

            try {
                const response = await fetch(
                    `${url}/sharing/candidates?q=${encodeURIComponent(q)}`,
                    { headers, credentials: 'same-origin' },
                );

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const json = (await response.json()) as { data: PersonRef[] };

                if (mine === searchSeq) {
                    results = json.data;
                    searched = true;
                    searchFailed = false;
                }
            } catch {
                if (mine === searchSeq) {
                    results = [];
                    searched = false;
                    searchFailed = true;
                }
            }
        }, 200);
    }

    /**
     * Nothing on the page changes but the flash, so only that is reloaded;
     * the dialog asks for its list again either way, and its controls stay
     * locked until then. `undo` puts a control back when the change didn't
     * save.
     */
    function visit(after?: () => void, undo?: () => void) {
        saving = true;
        error = null;
        let saved = false;

        return {
            only: ['flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved = true;
                after?.();
                onchanged?.();
            },
            onError: (errors: Record<string, string>) => {
                error =
                    errors.member_id ??
                    errors.permission ??
                    errors.access ??
                    errors.__global ??
                    t("That change didn't save.");
            },
            onFinish: () => {
                if (!saved) {
                    undo?.();
                }

                void load().finally(() => (saving = false));
            },
        };
    }

    function setOrganization(select: HTMLSelectElement) {
        const before = sharing?.organization_access ?? '';

        router.put(
            `${url}/sharing/organization`,
            { access: select.value === '' ? null : select.value },
            visit(undefined, () => (select.value = before)),
        );
    }

    function add(person: PersonRef) {
        router.post(
            `${url}/shares`,
            { member_id: person.id, permission },
            visit(() => {
                query = '';
                results = [];
                searched = false;
                searchInput?.focus();
            }),
        );
    }

    function change(share: DocumentShareItem, select: HTMLSelectElement) {
        router.patch(
            `${url}/shares/${share.id}`,
            { permission: select.value },
            visit(undefined, () => (select.value = share.permission)),
        );
    }

    function remove(share: DocumentShareItem) {
        router.delete(`${url}/shares/${share.id}`, visit());
    }

    function onKeydown(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();
        }
    }
</script>

{#if open}
    <div
        class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
        onpointerdown={(e) => (pressedBackdrop = e.target === e.currentTarget)}
        onclick={(e) => {
            if (pressedBackdrop && e.target === e.currentTarget) {
                close();
            }
        }}
        role="presentation"
    >
        <div
            class="popover mx-auto mt-[10vh] mb-12 flex w-[min(520px,calc(100vw-32px))] flex-col overflow-hidden p-0"
            onkeydown={onKeydown}
            role="dialog"
            aria-modal="true"
            aria-label={t('Share :title', { title: doc.title })}
            tabindex="-1"
        >
            <div
                class="flex h-12 shrink-0 items-center justify-between gap-3 border-b border-line px-4"
            >
                <h2 class="truncate text-[15px] font-semibold text-fg">
                    {t('Share :title', { title: doc.title })}
                </h2>
                <button
                    type="button"
                    class="btn-icon shrink-0"
                    title={t('Close (esc)')}
                    aria-label={t('Close')}
                    onclick={close}
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="flex flex-col gap-4 p-4">
                {#if failed}
                    <p class="text-[13px] text-danger">
                        {t("Who has it didn't load.")}
                        <button type="button" class="btn-ghost" onclick={load}
                            >{t('Try again')}</button
                        >
                    </p>
                {:else if sharing === null}
                    <p
                        class="flex items-center gap-2 text-[13px] text-fg-muted"
                    >
                        <Spinner size={14} />{t('Loading')}
                    </p>
                {:else}
                    <div>
                        <label for="{uid}-search" class="label mb-1 block"
                            >{t('Add people')}</label
                        >
                        <div class="flex gap-2">
                            <input
                                id="{uid}-search"
                                bind:this={searchInput}
                                bind:value={query}
                                oninput={search}
                                autocomplete="off"
                                placeholder={sharing.organization
                                    ? t('Someone in :organization', {
                                          organization:
                                              sharing.organization.name,
                                      })
                                    : t('Nobody to share with')}
                                disabled={!sharing.organization || saving}
                                class="input min-w-0 flex-1"
                            />
                            <label for="{uid}-level" class="sr-only"
                                >{t('Their access')}</label
                            >
                            <select
                                id="{uid}-level"
                                bind:value={permission}
                                class="input w-auto min-w-28 shrink-0"
                                disabled={!sharing.organization || saving}
                            >
                                {#each LEVELS as level (level.value)}
                                    <option value={level.value}
                                        >{t(level.label)}</option
                                    >
                                {/each}
                            </select>
                        </div>
                        {#if searchFailed}
                            <p class="mt-1 text-xs text-danger">
                                {t("The search didn't work. Try again.")}
                            </p>
                        {:else if searched && results.length === 0}
                            <p class="mt-1 text-xs text-fg-muted">
                                {t('Nobody else by that name.')}
                            </p>
                        {:else if results.length > 0}
                            <ul class="mt-1 border-y border-line-soft">
                                {#each results as person (person.id)}
                                    <li>
                                        <button
                                            type="button"
                                            class="row w-full gap-2.5 px-1 py-1.5 text-left"
                                            disabled={saving}
                                            onclick={() => add(person)}
                                        >
                                            <Avatar
                                                name={person.name}
                                                size="md"
                                            />
                                            <span
                                                class="min-w-0 flex-1 leading-tight"
                                            >
                                                <span
                                                    class="block truncate font-medium text-fg"
                                                    >{person.name}</span
                                                >
                                                {#if person.designation}
                                                    <span
                                                        class="block truncate text-xs text-fg-muted"
                                                        >{person.designation}</span
                                                    >
                                                {/if}
                                            </span>
                                            <UserPlus
                                                class="h-4 w-4 shrink-0 text-fg-faint"
                                            />
                                        </button>
                                    </li>
                                {/each}
                            </ul>
                        {/if}
                    </div>

                    {#if error}
                        <p role="alert" class="text-xs text-danger">{error}</p>
                    {/if}

                    <div>
                        <h3 class="label mb-1">{t('Who has it')}</h3>
                        <ul class="border-t border-line-soft">
                            <li
                                class="flex min-h-10 items-center gap-2.5 border-b border-line-soft py-1.5 text-[13px]"
                            >
                                <span class="min-w-0 flex-1 truncate text-fg">
                                    {sharing.home.project
                                        ? t('Everyone on :project', {
                                              project:
                                                  sharing.home.project.title,
                                          })
                                        : t('You')}
                                </span>
                                <span class="shrink-0 text-xs text-fg-muted"
                                    >{sharing.home.project
                                        ? t('Can edit')
                                        : t('Owner')}</span
                                >
                            </li>
                            {#if sharing.organization}
                                <li
                                    class="flex min-h-10 items-center gap-2.5 border-b border-line-soft py-1.5 text-[13px]"
                                >
                                    <label
                                        for="{uid}-organization"
                                        class="min-w-0 flex-1 truncate text-fg"
                                        >{t('Everyone in :organization', {
                                            organization:
                                                sharing.organization.name,
                                        })}</label
                                    >
                                    <select
                                        id="{uid}-organization"
                                        class="input h-7 w-auto min-w-28 shrink-0"
                                        value={sharing.organization_access ??
                                            ''}
                                        disabled={saving}
                                        onchange={(e) =>
                                            setOrganization(e.currentTarget)}
                                    >
                                        <option value="">{t('Off')}</option>
                                        <option value="view"
                                            >{t('Can view')}</option
                                        >
                                        <option value="edit"
                                            >{t('Can edit')}</option
                                        >
                                    </select>
                                </li>
                            {/if}
                            {#each sharing.shares as share (share.id)}
                                <li
                                    class="flex min-h-11 items-center gap-2.5 border-b border-line-soft py-1.5"
                                >
                                    <Avatar
                                        name={share.member.name}
                                        size="md"
                                    />
                                    <div class="min-w-0 flex-1 leading-tight">
                                        <div
                                            class="truncate text-[13px] font-medium text-fg"
                                        >
                                            {share.member.name}
                                        </div>
                                        <div
                                            class="truncate text-xs text-fg-muted"
                                        >
                                            {share.effective
                                                ? (share.member.designation ??
                                                  '')
                                                : t(
                                                      "Can't open it now (inactive or in another office)",
                                                  )}
                                        </div>
                                    </div>
                                    <label
                                        for="{uid}-share-{share.id}"
                                        class="sr-only"
                                        >{t(":name's access", {
                                            name: share.member.name,
                                        })}</label
                                    >
                                    <select
                                        id="{uid}-share-{share.id}"
                                        class="input h-7 w-auto min-w-28 shrink-0"
                                        value={share.permission}
                                        disabled={saving}
                                        onchange={(e) =>
                                            change(share, e.currentTarget)}
                                    >
                                        {#each LEVELS as level (level.value)}
                                            <option value={level.value}
                                                >{t(level.label)}</option
                                            >
                                        {/each}
                                    </select>
                                    <button
                                        type="button"
                                        class="btn-icon shrink-0"
                                        aria-label={t('Remove :name', {
                                            name: share.member.name,
                                        })}
                                        title={t('Remove')}
                                        disabled={saving}
                                        onclick={() => remove(share)}
                                    >
                                        <X class="h-3.5 w-3.5" />
                                    </button>
                                </li>
                            {/each}
                        </ul>
                    </div>
                {/if}
            </div>
        </div>
    </div>
{/if}
