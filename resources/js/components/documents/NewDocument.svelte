<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        FileSpreadsheet,
        FileText,
        Plus,
        Presentation,
        Upload,
    } from '@lucide/svelte';
    import type { DocumentFileType, ProjectChoice } from '../../lib/types';
    import Popover from '../Popover.svelte';
    import Spinner from '../Spinner.svelte';

    /**
     * New and Upload. On a project's tab the project is fixed (`project`);
     * on the Documents page the person picks Where: Just me (a personal
     * document, when they may make one) or one of `places`. A new document
     * opens in the editor; an upload stays here.
     */
    let {
        types,
        maxUploadMb,
        project = null,
        places = [],
        canCreatePersonal = false,
    }: {
        types: { value: DocumentFileType; label: string }[];
        maxUploadMb: number;
        /** The tab's project (its slug): no Where choice. */
        project?: string | null;
        places?: ProjectChoice[];
        canCreatePersonal?: boolean;
    } = $props();

    const uid = $props.id();
    const PERSONAL = '';

    const ICONS = {
        docx: FileText,
        xlsx: FileSpreadsheet,
        pptx: Presentation,
    } as const;

    let newOpen = $state(false);
    let uploadOpen = $state(false);
    let newTitle = $state('');
    let newType = $state<DocumentFileType>('docx');
    let newErrors = $state<Record<string, string>>({});
    let creating = $state(false);
    // svelte-ignore state_referenced_locally
    let where = $state(canCreatePersonal ? PERSONAL : (places[0]?.slug ?? ''));
    let fileInput = $state<HTMLInputElement | null>(null);
    let uploading = $state(false);
    let uploadError = $state<string | null>(null);

    const choosing = $derived(project === null);
    const canMake = $derived(
        !choosing || canCreatePersonal || places.length > 0,
    );
    const target = $derived(project ?? (where === PERSONAL ? null : where));
    const placeholder = $derived(
        `Untitled ${(types.find((t) => t.value === newType)?.label ?? 'document').toLowerCase()}`,
    );

    function create(event: SubmitEvent) {
        event.preventDefault();
        creating = true;
        newErrors = {};
        router.post(
            '/workspace/documents',
            {
                title: newTitle.trim() || placeholder,
                type: newType,
                ...(target ? { project: target } : {}),
            },
            {
                // The server opens the new document in the editor.
                onError: (errors) => (newErrors = errors),
                onFinish: () => (creating = false),
            },
        );
    }

    function chooseFile() {
        uploadError = null;
        fileInput?.click();
    }

    function upload() {
        const file = fileInput?.files?.[0];

        if (!file) {
            return;
        }

        if (file.size > maxUploadMb * 1024 * 1024) {
            uploadError = `Files can be at most ${maxUploadMb} MB.`;
            fileInput!.value = '';

            return;
        }

        uploading = true;
        router.post(
            '/workspace/documents/upload',
            { file, ...(target ? { project: target } : {}) },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (uploadOpen = false),
                onError: (errors) =>
                    (uploadError =
                        errors.file ??
                        errors.project ??
                        errors.__global ??
                        "The file couldn't be uploaded."),
                onFinish: () => {
                    uploading = false;

                    if (fileInput) {
                        fileInput.value = '';
                    }
                },
            },
        );
    }
</script>

{#snippet whereField(id: string)}
    <label for={id} class="label mb-1 block">Where</label>
    <select {id} bind:value={where} class="input">
        {#if canCreatePersonal}
            <option value={PERSONAL}>Just me</option>
        {/if}
        {#each places as place (place.slug)}
            <option value={place.slug}>{place.label}</option>
        {/each}
    </select>
    <p class="mt-1 text-xs text-fg-muted">
        {where === PERSONAL
            ? 'Yours until you share it.'
            : 'Its team can open and edit it.'}
    </p>
{/snippet}

{#if canMake}
    <div class="flex shrink-0 items-center gap-2">
        <input
            bind:this={fileInput}
            type="file"
            accept=".docx,.xlsx,.pptx"
            class="hidden"
            aria-label="Choose a file to upload"
            onchange={upload}
        />
        {#if choosing}
            <Popover
                bind:open={uploadOpen}
                role="dialog"
                align="right"
                label="Upload a file"
                triggerClass="btn"
                triggerLabel="Upload"
                panelClass="w-72"
            >
                {#snippet trigger()}
                    {#if uploading}
                        <Spinner size={14} label="Uploading" />Uploading
                    {:else}
                        <Upload class="h-3.5 w-3.5" /><span
                            class="hidden sm:inline">Upload</span
                        >
                    {/if}
                {/snippet}
                <div class="p-1.5">
                    {@render whereField(`${uid}-upload-where`)}
                    <p class="mt-3 text-xs text-fg-muted">
                        A .docx, .xlsx or .pptx file of up to {maxUploadMb} MB.
                    </p>
                    {#if uploadError}
                        <p role="alert" class="mt-1 text-xs text-danger">
                            {uploadError}
                        </p>
                    {/if}
                    <div class="mt-3 flex justify-end">
                        <button
                            type="button"
                            class="btn-primary"
                            disabled={uploading}
                            onclick={chooseFile}>Choose file</button
                        >
                    </div>
                </div>
            </Popover>
        {:else}
            <button
                type="button"
                class="btn"
                disabled={uploading}
                onclick={chooseFile}
            >
                {#if uploading}
                    <Spinner size={14} label="Uploading" />Uploading
                {:else}
                    <Upload class="h-3.5 w-3.5" />Upload
                {/if}
            </button>
        {/if}
        <Popover
            bind:open={newOpen}
            role="dialog"
            align="right"
            label="New document"
            triggerClass="btn-primary"
            panelClass="w-72"
        >
            {#snippet trigger()}
                <Plus class="h-3.5 w-3.5" />New
            {/snippet}

            <form class="p-1.5" onsubmit={create}>
                <fieldset>
                    <legend class="label mb-1.5">Kind</legend>
                    <div class="grid grid-cols-3 gap-1">
                        {#each types as type (type.value)}
                            {@const Icon = ICONS[type.value]}
                            <label
                                class={`flex cursor-pointer flex-col items-center gap-1 rounded-md border px-1 py-2 text-xs ${
                                    newType === type.value
                                        ? 'border-accent bg-accent-soft text-fg'
                                        : 'border-line text-fg-muted hover:bg-hover'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="{uid}-type"
                                    value={type.value}
                                    bind:group={newType}
                                    class="sr-only"
                                />
                                <Icon class="h-4 w-4" />
                                {type.label}
                            </label>
                        {/each}
                    </div>
                </fieldset>
                <label for="{uid}-title" class="label mt-3 mb-1 block"
                    >Title</label
                >
                <!-- svelte-ignore a11y_autofocus -->
                <input
                    id="{uid}-title"
                    bind:value={newTitle}
                    maxlength="200"
                    autocomplete="off"
                    autofocus
                    {placeholder}
                    aria-invalid={newErrors.title ? 'true' : undefined}
                    class="input"
                />
                {#if choosing}
                    <div class="mt-3">
                        {@render whereField(`${uid}-where`)}
                    </div>
                {/if}
                {#if newErrors.title || newErrors.type || newErrors.project || newErrors.__global}
                    <p role="alert" class="mt-1 text-xs text-danger">
                        {newErrors.title ??
                            newErrors.type ??
                            newErrors.project ??
                            newErrors.__global}
                    </p>
                {/if}
                <div class="mt-3 flex justify-end">
                    <button
                        type="submit"
                        class="btn-primary"
                        disabled={creating}>Create and open</button
                    >
                </div>
            </form>
        </Popover>
    </div>
    {#if uploadError && !choosing}
        <p role="alert" class="mt-2 w-full text-xs text-danger">
            {uploadError}
        </p>
    {/if}
{/if}
