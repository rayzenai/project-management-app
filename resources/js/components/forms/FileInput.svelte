<script lang="ts">
    import { FileText, Paperclip, X } from '@lucide/svelte';
    import { send, FormsHttpError } from '../../lib/forms/http';
    import type { FileRef } from '../../lib/forms/types';
    import Spinner from '../Spinner.svelte';

    /**
     * Files for a question, uploaded as soon as they are chosen (so a
     * draft holds them), each removable until Submit. The answer is the
     * list of the files' ids.
     */
    let {
        id,
        formId,
        block,
        test = false,
        value = $bindable(),
        files = $bindable(),
        kinds = ['pdf', 'image'],
        maxFiles = 1,
        maxMb = 10,
        disabled = false,
        invalid = false,
    }: {
        id: string;
        formId: number;
        block: string;
        test?: boolean;
        value: number[] | null | undefined;
        files: Record<number, FileRef>;
        kinds?: string[];
        maxFiles?: number;
        maxMb?: number;
        disabled?: boolean;
        invalid?: boolean;
    } = $props();

    const EXTENSIONS: Record<string, string[]> = {
        pdf: ['.pdf'],
        image: ['.jpg', '.jpeg', '.png', '.webp'],
        word: ['.doc', '.docx', '.odt'],
        excel: ['.xls', '.xlsx', '.ods', '.csv'],
    };

    let uploading = $state(false);
    let error = $state<string | null>(null);
    let picker = $state<HTMLInputElement | null>(null);

    const ids = $derived(value ?? []);
    const accept = $derived(
        kinds.flatMap((k) => EXTENSIONS[k] ?? []).join(','),
    );
    const room = $derived(maxFiles - ids.length);

    async function upload(list: FileList | null) {
        if (!list || list.length === 0) {
            return;
        }

        error = null;
        uploading = true;

        try {
            for (const file of Array.from(list).slice(0, Math.max(room, 0))) {
                if (file.size > maxMb * 1024 * 1024) {
                    error = `Add a file of ${maxMb} MB or less.`;
                    continue;
                }

                const body = new FormData();
                body.append('purpose', 'answer');
                body.append('block', block);
                body.append('file', file);

                if (test) {
                    body.append('test', '1');
                }

                const { data } = await send<FileRef>(
                    'POST',
                    `/workspace/forms/${formId}/files`,
                    body,
                );
                files = { ...files, [data.id]: data };
                value = [...(value ?? []), data.id];
            }
        } catch (e) {
            error =
                e instanceof FormsHttpError
                    ? (e.errors.file ?? e.message)
                    : "The file didn't upload. Try again.";
        } finally {
            uploading = false;

            if (picker) {
                picker.value = '';
            }
        }
    }

    function remove(fileId: number) {
        value = ids.filter((i) => i !== fileId);
    }

    const size = (n: number) =>
        n > 1024 * 1024
            ? `${(n / 1024 / 1024).toFixed(1)} MB`
            : `${Math.max(1, Math.round(n / 1024))} KB`;
</script>

<div class="flex flex-col gap-1.5">
    {#each ids as fileId (fileId)}
        {@const file = files[fileId]}
        <div
            class="flex items-center gap-2 rounded-md border border-line px-2 py-1.5 text-[13px]"
        >
            <FileText class="h-4 w-4 shrink-0 text-fg-faint" />
            {#if file}
                <a
                    href={file.url}
                    target="_blank"
                    rel="noopener"
                    class="min-w-0 flex-1 truncate text-fg hover:underline"
                    >{file.name}</a
                >
                <span class="shrink-0 text-xs text-fg-muted"
                    >{size(file.size)}</span
                >
            {:else}
                <span class="min-w-0 flex-1 truncate text-fg-muted">A file</span
                >
            {/if}
            {#if !disabled}
                <button
                    type="button"
                    class="btn-icon h-6 w-6"
                    aria-label="Remove {file?.name ?? 'the file'}"
                    onclick={() => remove(fileId)}
                >
                    <X class="h-3 w-3" />
                </button>
            {/if}
        </div>
    {/each}
    {#if !disabled && room > 0}
        <div class="flex flex-wrap items-center gap-2">
            <input
                {id}
                bind:this={picker}
                type="file"
                class="sr-only"
                {accept}
                multiple={room > 1}
                aria-invalid={invalid}
                onchange={(e) => upload(e.currentTarget.files)}
            />
            <label
                for={id}
                class="btn cursor-pointer"
                class:opacity-60={uploading}
            >
                {#if uploading}<Spinner size={14} />Uploading{:else}<Paperclip
                        class="h-3.5 w-3.5"
                    />{ids.length ? 'Add another file' : 'Add a file'}{/if}
            </label>
            <span class="text-xs text-fg-muted">
                {kinds.map((k) => k.toUpperCase()).join(', ')}, up to {maxMb} MB{maxFiles >
                1
                    ? `, at most ${maxFiles} files`
                    : ''}
            </span>
        </div>
    {/if}
    {#if error}<p class="text-xs text-danger" role="alert">{error}</p>{/if}
</div>
