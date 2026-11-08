<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Upload } from '@lucide/svelte';
    import { onDestroy } from 'svelte';
    import { msg, t, tc } from '../../lib/i18n';
    import { projectLabel } from '../../lib/projectLabel';
    import { toast } from '../../lib/toast.svelte';
    import type {
        DocumentFileType,
        ProjectChoices,
        ProjectPick,
        SharedProps,
    } from '../../lib/types';
    import ProjectPicker from '../ProjectPicker.svelte';
    import Spinner from '../Spinner.svelte';
    import DocumentKindIcon from './DocumentKindIcon.svelte';

    /**
     * How documents start: a tile per kind makes "Untitled document" (or
     * spreadsheet, presentation) at once and opens it in the editor, whose
     * title is ready to type over; Upload takes one or more files, and so
     * does dropping them anywhere on the page. On a project's tab the
     * project is fixed (`project`); on the Documents page they go where
     * "New ones go to" says (the project picker: Just for me or one of
     * `places`), remembered per person in this browser.
     */
    let {
        types,
        maxUploadMb,
        project = null,
        places = { projects: [], organizations: [] },
        canCreatePersonal = false,
    }: {
        types: { value: DocumentFileType; label: string }[];
        maxUploadMb: number;
        /** The tab's project (its slug): no choice of place. */
        project?: string | null;
        places?: ProjectChoices;
        canCreatePersonal?: boolean;
    } = $props();

    // Remembered as a project's slug, or this for Just for me.
    const PERSONAL = '';
    const EXTENSIONS = ['docx', 'xlsx', 'pptx'];
    const APPS: Record<DocumentFileType, string> = {
        docx: 'Word',
        xlsx: 'Excel',
        pptx: 'PowerPoint',
    };
    const UNTITLED: Record<DocumentFileType, string> = {
        docx: msg('Untitled document'),
        xlsx: msg('Untitled spreadsheet'),
        pptx: msg('Untitled presentation'),
    };

    const shared = $derived(page.props as unknown as SharedProps);
    const storageKey = $derived(
        `documents:new-place:${shared.auth.user?.id ?? 'guest'}`,
    );
    const choosing = $derived(project === null);
    const choices = $derived([
        ...(canCreatePersonal ? [PERSONAL] : []),
        ...places.projects.map((choice) => choice.slug),
    ]);
    const canMake = $derived(!choosing || choices.length > 0);

    function remembered(): string | null {
        try {
            return window.localStorage.getItem(storageKey);
        } catch {
            return null;
        }
    }

    function remember(value: string): void {
        try {
            window.localStorage.setItem(storageKey, value);
        } catch {
            // A private window or blocked storage: the choice lasts this visit.
        }
    }

    let chosen = $state<string | null>(
        typeof window === 'undefined' ? null : remembered(),
    );
    // The remembered place while it is still one of the choices.
    const place = $derived(
        chosen !== null && choices.includes(chosen)
            ? chosen
            : (choices[0] ?? PERSONAL),
    );
    const placeProject = $derived(
        places.projects.find((choice) => choice.slug === place) ?? null,
    );
    const pick = $derived<ProjectPick>(
        placeProject?.id ?? (place === PERSONAL ? 'personal' : null),
    );
    const target = $derived(project ?? (place === PERSONAL ? null : place));
    const dropHint = $derived(
        choosing && placeProject === null
            ? t(
                  'To your personal documents. Word, Excel or PowerPoint files of up to :max MB each.',
                  { max: maxUploadMb },
              )
            : choosing && placeProject !== null
              ? t(
                    'To :place. Word, Excel or PowerPoint files of up to :max MB each.',
                    { place: projectLabel(placeProject), max: maxUploadMb },
                )
              : t(
                    'To this project. Word, Excel or PowerPoint files of up to :max MB each.',
                    { max: maxUploadMb },
                ),
    );

    let creating = $state<DocumentFileType | null>(null);
    let error = $state<string | null>(null);
    let fileInput = $state<HTMLInputElement | null>(null);
    let progress = $state<{
        index: number;
        total: number;
        name: string;
    } | null>(null);
    let problems = $state<string[]>([]);
    const busy = $derived(creating !== null || progress !== null);

    function choose(next: ProjectPick): void {
        const value =
            next === 'personal'
                ? PERSONAL
                : places.projects.find((choice) => choice.id === next)?.slug;

        if (value !== undefined) {
            chosen = value;
            remember(value);
        }
    }

    function create(type: DocumentFileType): void {
        if (busy) {
            return;
        }

        creating = type;
        error = null;
        problems = [];
        router.post(
            '/workspace/documents',
            {
                title: t(UNTITLED[type]),
                type,
                ...(target ? { project: target } : {}),
            },
            {
                // The server opens the new document in the editor.
                onError: (errors) =>
                    (error =
                        errors.title ??
                        errors.type ??
                        errors.project ??
                        errors.__global ??
                        t("It couldn't be made. Try again.")),
                onFinish: () => (creating = null),
            },
        );
    }

    function problemWith(file: File): string | null {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!EXTENSIONS.includes(extension)) {
            return t(
                ":name isn't a Word, Excel or PowerPoint file (.docx, .xlsx or .pptx).",
                { name: file.name },
            );
        }

        if (file.size > maxUploadMb * 1024 * 1024) {
            return t(':name is larger than :max MB.', {
                name: file.name,
                max: maxUploadMb,
            });
        }

        return null;
    }

    // Left the page while files were still going: the rest stay unsent.
    let gone = false;

    onDestroy(() => (gone = true));

    /** Laravel's XSRF cookie, which stays current after sign-in. */
    function csrfHeaders(): Record<string, string> {
        const cookie = document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='));

        return cookie
            ? {
                  'X-XSRF-TOKEN': decodeURIComponent(
                      cookie.slice('XSRF-TOKEN='.length),
                  ),
              }
            : {};
    }

    /**
     * One upload, by fetch rather than an Inertia visit, so it never
     * interrupts a click elsewhere on the page or is interrupted by one.
     * Resolves with the server's complaint, or null.
     */
    async function send(file: File): Promise<string | null> {
        const body = new FormData();
        body.append('file', file);

        if (target) {
            body.append('project', target);
        }

        try {
            const response = await fetch('/workspace/documents/upload', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body,
            });

            if (response.ok) {
                return null;
            }

            if (response.status === 419) {
                return t('the page has expired. Reload it and try again.');
            }

            if (response.status === 429) {
                return t(
                    'too many uploads at once. Wait a minute and try again.',
                );
            }

            const json = (await response.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[]>;
            } | null;

            return (
                json?.errors?.file?.[0] ??
                json?.errors?.project?.[0] ??
                json?.message ??
                t("it couldn't be uploaded.")
            );
        } catch {
            return t("it couldn't be uploaded.");
        }
    }

    /** Files one after another, so each lands before the next starts. */
    async function upload(files: File[]): Promise<void> {
        if (files.length === 0 || busy) {
            return;
        }

        const found: string[] = [];
        const ready: File[] = [];
        error = null;

        for (const file of files) {
            const problem = problemWith(file);

            if (problem) {
                found.push(problem);
            } else {
                ready.push(file);
            }
        }

        let uploaded = 0;

        for (const [index, file] of ready.entries()) {
            if (gone) {
                return;
            }

            progress = {
                index: index + 1,
                total: ready.length,
                name: file.name,
            };
            const failed = await send(file);

            if (failed) {
                found.push(
                    t(':name: :problem', { name: file.name, problem: failed }),
                );
            } else {
                uploaded++;
            }
        }

        progress = null;
        problems = found;

        if (uploaded > 0 && !gone) {
            // One fresh list for the lot (on a project's tab, its documents).
            router.reload({
                only: [
                    'documents',
                    'next_cursor',
                    'total',
                    'trashed_count',
                    'project_cards',
                ],
            });
            toast.show(
                uploaded === 1 && ready.length === 1
                    ? t('Uploaded :name.', { name: ready[0].name })
                    : tc(
                          'Uploaded :count file.|Uploaded :count files.',
                          uploaded,
                      ),
            );
        }
    }

    function picked(): void {
        const files = Array.from(fileInput?.files ?? []);

        if (fileInput) {
            fileInput.value = '';
        }

        void upload(files);
    }

    // Dropping files anywhere on the page. Enter and leave fire for every
    // element crossed, so the overlay shows while the depth is above 0.
    let dragDepth = 0;
    let dragging = $state(false);

    function carriesFiles(event: DragEvent): boolean {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    function dragEnter(event: DragEvent): void {
        if (!canMake || !carriesFiles(event)) {
            return;
        }

        event.preventDefault();
        dragDepth++;
        dragging = true;
    }

    function dragOver(event: DragEvent): void {
        if (!canMake || !carriesFiles(event)) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = busy ? 'none' : 'copy';
        }
    }

    function dragLeave(): void {
        if (!dragging) {
            return;
        }

        dragDepth = Math.max(0, dragDepth - 1);
        dragging = dragDepth > 0;
    }

    function drop(event: DragEvent): void {
        if (!canMake || !carriesFiles(event)) {
            return;
        }

        event.preventDefault();
        dragDepth = 0;
        dragging = false;
        void upload(Array.from(event.dataTransfer?.files ?? []));
    }
</script>

<svelte:window
    ondragenter={dragEnter}
    ondragover={dragOver}
    ondragleave={dragLeave}
    ondrop={drop}
/>

{#snippet tileBody(title: string, detail: string, short = detail)}
    <span class="min-w-0">
        <span class="block truncate text-[14px] font-medium text-fg"
            >{title}</span
        >
        <span class="hidden truncate text-xs text-fg-muted sm:block"
            >{detail}</span
        >
        <span class="block truncate text-xs text-fg-muted sm:hidden"
            >{short}</span
        >
    </span>
{/snippet}

{#if canMake}
    <section aria-label={t('Start a document')}>
        <input
            bind:this={fileInput}
            type="file"
            multiple
            accept=".docx,.xlsx,.pptx"
            class="hidden"
            aria-label={t('Choose files to upload')}
            onchange={picked}
        />
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {#each types as kind (kind.value)}
                <button
                    type="button"
                    class="start-tile"
                    disabled={busy}
                    aria-busy={creating === kind.value}
                    onclick={() => create(kind.value)}
                >
                    {#if creating === kind.value}
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center"
                            ><Spinner size={16} label={t('Opening')} /></span
                        >
                    {:else}
                        <DocumentKindIcon type={kind.value} size="lg" />
                    {/if}
                    {@render tileBody(
                        kind.label,
                        `${APPS[kind.value]}, .${kind.value}`,
                        APPS[kind.value],
                    )}
                </button>
            {/each}
            <button
                type="button"
                class="start-tile"
                disabled={busy}
                aria-busy={progress !== null}
                onclick={() => fileInput?.click()}
            >
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-surface-alt text-fg-muted"
                    aria-hidden="true"
                >
                    {#if progress}
                        <Spinner size={16} />
                    {:else}
                        <Upload class="h-5 w-5" />
                    {/if}
                </span>
                {#if progress}
                    {@render tileBody(
                        progress.total > 1
                            ? t('Uploading :index of :total', {
                                  index: progress.index,
                                  total: progress.total,
                              })
                            : t('Uploading'),
                        progress.name,
                    )}
                {:else}
                    <span class="min-w-0">
                        <span
                            class="block truncate text-[14px] font-medium text-fg"
                            >{t('Upload a file')}</span
                        >
                        <span
                            class="hidden truncate text-xs text-fg-muted md:block"
                            >{t('or drop it on this page')}</span
                        >
                        <span
                            class="block truncate text-xs text-fg-muted md:hidden"
                            >{t('Up to :max MB', { max: maxUploadMb })}</span
                        >
                    </span>
                {/if}
            </button>
        </div>

        {#if choosing}
            <div
                class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-muted"
            >
                <span>{t('New ones go to')}</span>
                <ProjectPicker
                    projects={places.projects}
                    organizations={places.organizations}
                    personal={canCreatePersonal}
                    personalDetail={msg(
                        'Only you see them until you share them.',
                    )}
                    bind:value={() => pick, choose}
                    triggerClass="input flex w-auto max-w-full min-w-0 items-center gap-1.5 py-1 text-fg lg:min-h-8 lg:text-[13px]"
                />
                <span
                    >{place === PERSONAL
                        ? t('Only you see them until you share them.')
                        : t('The project team can open and edit them.')}</span
                >
            </div>
        {/if}

        {#if error}
            <p role="alert" class="mt-2 text-xs text-danger">{error}</p>
        {/if}
        {#if problems.length > 0}
            <ul role="alert" class="mt-2 space-y-0.5 text-xs text-danger">
                {#each problems as problem, index (index)}
                    <li>{problem}</li>
                {/each}
            </ul>
        {/if}
    </section>

    {#if dragging}
        <div
            class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-bg/80 p-6"
        >
            <div
                class="flex max-w-sm flex-col items-center gap-2 rounded-lg border-2 border-dashed border-accent bg-surface px-10 py-8 text-center"
            >
                <Upload class="h-6 w-6 text-accent" />
                <p class="font-medium text-fg">{t('Drop to upload')}</p>
                <p class="text-xs text-fg-muted">{dropHint}</p>
            </div>
        </div>
    {/if}
{/if}
