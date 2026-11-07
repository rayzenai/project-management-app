<script lang="ts">
    import { Upload } from '@lucide/svelte';
    import { FormsHttpError, send } from '../../../lib/forms/http';
    import type { FileRef } from '../../../lib/forms/types';
    import Spinner from '../../Spinner.svelte';

    /** A block's picture or file to download, uploaded as soon as it is chosen. */
    let {
        formId,
        block,
        kind,
        file = $bindable(),
        files = $bindable(),
    }: {
        formId: number;
        block: string;
        kind: 'image' | 'download';
        file: number | null | undefined;
        files: Record<number, FileRef>;
    } = $props();

    const uid = $props.id();
    let uploading = $state(false);
    let error = $state<string | null>(null);

    async function upload(list: FileList | null) {
        const chosen = list?.[0];

        if (!chosen) {
            return;
        }

        uploading = true;
        error = null;

        try {
            const body = new FormData();
            body.append('purpose', 'content');
            body.append('block', block);
            body.append('kind', kind === 'image' ? 'image' : 'download');
            body.append('file', chosen);
            const { data } = await send<FileRef>(
                'POST',
                `/workspace/forms/${formId}/files`,
                body,
            );
            files = { ...files, [data.id]: data };
            file = data.id;
        } catch (e) {
            error =
                e instanceof FormsHttpError
                    ? (e.errors.file ?? e.message)
                    : "It didn't upload. Try again.";
        } finally {
            uploading = false;
        }
    }

    const current = $derived(file ? files[file] : undefined);
</script>

<div class="flex flex-col gap-1.5">
    {#if current && kind === 'image'}
        <img
            src={current.url}
            alt=""
            class="max-h-48 w-fit rounded border border-line object-contain"
        />
    {:else if current}
        <a
            href={current.url}
            class="text-[13px] text-fg underline"
            target="_blank"
            rel="noopener">{current.name}</a
        >
    {/if}
    <div class="flex items-center gap-2">
        <input
            id="{uid}-file"
            type="file"
            class="sr-only"
            accept={kind === 'image'
                ? '.jpg,.jpeg,.png,.webp'
                : '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.odt,.xls,.xlsx,.ods,.csv'}
            onchange={(e) => upload(e.currentTarget.files)}
        />
        <label for="{uid}-file" class="btn cursor-pointer">
            {#if uploading}<Spinner size={14} />Uploading{:else}<Upload
                    class="h-3.5 w-3.5"
                />{current
                    ? 'Replace'
                    : kind === 'image'
                      ? 'Upload a picture'
                      : 'Upload a file'}{/if}
        </label>
    </div>
    {#if error}<p class="text-xs text-danger" role="alert">{error}</p>{/if}
</div>
