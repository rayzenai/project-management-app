<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { Download, Users } from '@lucide/svelte';
    import { onMount, tick } from 'svelte';
    import AppShell from '../../components/AppShell.svelte';
    import ShareDialog from '../../components/documents/ShareDialog.svelte';
    import Spinner from '../../components/Spinner.svelte';
    import { t, tc } from '../../lib/i18n';
    import { QUIET } from '../../lib/toast.svelte';
    import type {
        DocumentEditor,
        DocumentItem,
        DocumentProject,
        DocumentVersionItem,
        SharedProps,
    } from '../../lib/types';

    /**
     * A document open in ONLYOFFICE, filling the page under the top bar.
     * The editor loads from its own server (editor.server_url) with the
     * config the tracker signed; it fetches the file and sends saves to the
     * tracker by itself. Everyone with the document open edits together;
     * Can view and frozen documents open read-only (the signed config says
     * so). `project` is null for a personal document. Whoever may edit it
     * renames it by clicking its title in the bar; a document just made
     * opens with that title selected, so typing names it.
     */
    let {
        project,
        document: doc,
        versions,
        editor,
    }: {
        project: DocumentProject | null;
        document: DocumentItem;
        versions: DocumentVersionItem[];
        editor: DocumentEditor;
    } = $props();

    interface DocsApi {
        DocEditor: new (
            id: string,
            config: Record<string, unknown>,
        ) => { destroyEditor?: () => void };
    }

    const MOBILE_WIDTH = 768;
    const frameId = 'onlyoffice-editor';

    let status = $state<'loading' | 'ready' | 'failed'>('loading');
    let shareOpen = $state(false);

    // Renaming in the bar: Enter or leaving the field saves, Escape keeps
    // the old title.
    let renaming = $state(false);
    let titleDraft = $state('');
    let titleInput = $state<HTMLInputElement | null>(null);
    let saving = $state(false);
    let renameError = $state<string | null>(null);
    const canRename = $derived(doc.access === 'edit' && !doc.frozen);

    async function startRename() {
        if (!canRename) {
            return;
        }

        titleDraft = doc.title;
        renameError = null;
        renaming = true;
        await tick();
        titleInput?.focus();
        titleInput?.select();
    }

    function saveTitle() {
        if (!renaming || saving) {
            return;
        }

        const title = titleDraft.trim();

        if (title === '' || title === doc.title) {
            renaming = false;

            return;
        }

        saving = true;
        router.patch(
            `/workspace/documents/${doc.id}`,
            { title },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['document'],
                headers: QUIET,
                onSuccess: () => (renaming = false),
                onError: (errors) =>
                    (renameError =
                        errors.title ??
                        errors.__global ??
                        t("The title didn't save. Try again.")),
                onFinish: () => (saving = false),
            },
        );
    }

    function titleKeys(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveTitle();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            renaming = false;
            renameError = null;
        }
    }

    // A document just made (the create's flash names it) opens ready to name.
    onMount(() => {
        const flash = (page.props as unknown as SharedProps).flash;

        if (flash?.created_document === doc.id) {
            void startRename();
        }
    });

    // Back to where the document lives, for whoever may open it there.
    const back = $derived(
        project?.can_open
            ? {
                  href: `/workspace/projects/${project.slug}?view=documents`,
                  label: project.label,
              }
            : { href: '/workspace/documents', label: t('Documents') },
    );
    const downloadUrl = $derived(
        `/workspace/documents/${doc.id}/versions/${doc.version}/download`,
    );

    /** The editor's script, loaded once per page load and reused after. */
    function loadApi(src: string): Promise<DocsApi> {
        const loaded = (window as unknown as { DocsAPI?: DocsApi }).DocsAPI;

        if (loaded) {
            return Promise.resolve(loaded);
        }

        return new Promise((resolve, reject) => {
            const script = window.document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => {
                const api = (window as unknown as { DocsAPI?: DocsApi })
                    .DocsAPI;

                if (api) {
                    resolve(api);
                } else {
                    reject(new Error('DocsAPI missing'));
                }
            };
            script.onerror = () => reject(new Error('editor unreachable'));
            window.document.head.appendChild(script);
        });
    }

    /**
     * Hands the element to ONLYOFFICE: it gets a placeholder of its own,
     * which the editor swaps for its frame, so Svelte never owns that part.
     */
    function mountEditor(node: HTMLDivElement) {
        let instance: { destroyEditor?: () => void } | null = null;
        let gone = false;

        const placeholder = window.document.createElement('div');
        placeholder.id = frameId;
        node.appendChild(placeholder);

        loadApi(`${editor.server_url}/web-apps/apps/api/documents/api.js`)
            .then((api) => {
                if (gone) {
                    return;
                }

                // Layout is the browser's to choose; the signed config
                // (document, editorConfig, token) is passed as given.
                instance = new api.DocEditor(frameId, {
                    ...editor.config,
                    type:
                        window.innerWidth < MOBILE_WIDTH ? 'mobile' : 'desktop',
                    width: '100%',
                    height: '100%',
                    events: {
                        onAppReady: () => {
                            status = 'ready';

                            // The editor takes focus when it opens; a title
                            // being typed keeps it.
                            if (renaming) {
                                titleInput?.focus();
                            }
                        },
                        // Once it runs, the editor shows its own errors;
                        // this page's only covers one that never started.
                        onError: () => {
                            if (status === 'loading') {
                                status = 'failed';
                            }
                        },
                        // The session it was opened for has been saved (a
                        // page brought back from history, a laptop waking
                        // up): open the saved file afresh.
                        onRequestRefreshFile: () => window.location.reload(),
                    },
                });
            })
            .catch(() => (status = 'failed'));

        return {
            destroy() {
                gone = true;
                instance?.destroyEditor?.();
                node.replaceChildren();
            },
        };
    }
</script>

<svelte:head
    ><title>{doc.title} · {project?.title ?? t('Documents')}</title
    ></svelte:head
>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5 text-[13px]">
            <a
                href={back.href}
                use:inertia
                class="hidden shrink-0 text-fg-muted hover:text-fg sm:inline"
                >{back.label}</a
            >
            <span class="hidden text-fg-faint sm:inline">/</span>
            {#if renaming}
                <label for="document-title" class="sr-only">{t('Title')}</label>
                <input
                    id="document-title"
                    bind:this={titleInput}
                    bind:value={titleDraft}
                    maxlength="200"
                    autocomplete="off"
                    disabled={saving}
                    aria-invalid={renameError ? 'true' : undefined}
                    aria-describedby={renameError
                        ? 'document-title-error'
                        : undefined}
                    class="input h-8 max-w-md min-w-0 flex-1 py-0 font-medium lg:min-h-8"
                    onkeydown={titleKeys}
                    onblur={saveTitle}
                />
                {#if renameError}
                    <span
                        id="document-title-error"
                        role="alert"
                        class="truncate text-xs text-danger">{renameError}</span
                    >
                {/if}
            {:else if canRename}
                <button
                    type="button"
                    class="-mx-1 min-w-0 truncate rounded-sm px-1 py-0.5 font-medium hover:bg-hover"
                    title={t('Rename')}
                    aria-label={t('Rename :title', { title: doc.title })}
                    onclick={startRename}>{doc.title}</button
                >
            {:else}
                <span class="truncate font-medium">{doc.title}</span>
            {/if}
            <span class="shrink-0 text-xs text-fg-faint"
                >{t('Version :version', { version: doc.version })}</span
            >
            {#if doc.frozen}
                <span class="chip shrink-0"
                    >{t('Read-only: its owner has left')}</span
                >
            {:else if doc.access === 'view'}
                <span class="chip shrink-0">{t('Can view')}</span>
            {/if}
        </div>
        <div class="flex shrink-0 items-center gap-1">
            {#if doc.can_share}
                <button
                    type="button"
                    class="btn-ghost"
                    onclick={() => (shareOpen = true)}
                    title={t('Share')}
                    ><Users class="h-4 w-4" /><span class="hidden sm:inline"
                        >{t('Share')}</span
                    ></button
                >
            {/if}
            <a
                class="btn-ghost shrink-0"
                href={downloadUrl}
                download
                title={t('Download version :version', { version: doc.version })}
                ><Download class="h-4 w-4" /><span class="hidden sm:inline"
                    >{t('Download')}</span
                ></a
            >
        </div>
    {/snippet}

    <ShareDialog document={doc} bind:open={shareOpen} />

    <div
        class="relative h-[calc(100dvh-48px-57px-env(safe-area-inset-bottom))] min-h-80 md:h-[calc(100dvh-48px)]"
    >
        <div use:mountEditor class="h-full w-full"></div>
        {#if status === 'loading'}
            <div
                class="absolute inset-0 flex items-center justify-center gap-2 bg-surface text-fg-muted"
            >
                <Spinner size={16} />{t('Opening :title', { title: doc.title })}
            </div>
        {:else if status === 'failed'}
            <div
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-surface px-6 text-center"
            >
                <p class="font-medium">
                    {t("The editor isn't reachable right now.")}
                </p>
                <p class="max-w-md text-[13px] text-fg-muted">
                    {t(
                        'Your document is safe: every saved version is kept. You can download it, or try again in a moment.',
                    )}
                </p>
                <div class="flex gap-2">
                    <a class="btn" href={downloadUrl} download
                        ><Download class="h-3.5 w-3.5" />{t(
                            'Download version :version',
                            { version: doc.version },
                        )}</a
                    >
                    <button
                        type="button"
                        class="btn-primary"
                        onclick={() => window.location.reload()}
                        >{t('Try again')}</button
                    >
                </div>
                {#if versions.length > 1}
                    <p class="text-xs text-fg-faint">
                        {tc(
                            ':count version is kept.|:count versions are kept.',
                            versions.length,
                        )}
                    </p>
                {/if}
            </div>
        {/if}
    </div>
</AppShell>
