<script lang="ts">
    import { Download } from '@lucide/svelte';
    import { say } from '../../lib/forms/answers';
    import type { Block, FileRef } from '../../lib/forms/types';
    import RichText from './RichText.svelte';

    /**
     * A block that isn't a question: a heading, words, a picture, a video
     * (YouTube's privacy-enhanced player or Vimeo's), a file to download or
     * a divider.
     */
    let {
        block,
        language,
        files,
    }: {
        block: Block;
        language: 'en' | 'ne';
        files: Record<number, FileRef>;
    } = $props();

    function embed(url: string): string | null {
        const youtube =
            /^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/.exec(
                url,
            );

        if (youtube) {
            return `https://www.youtube-nocookie.com/embed/${youtube[1]}`;
        }

        const vimeo =
            /^https?:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{6,12})/.exec(
                url,
            );

        return vimeo ? `https://player.vimeo.com/video/${vimeo[1]}` : null;
    }

    const file = $derived(block.file ? files[block.file] : undefined);
    const doc = $derived(
        block.doc
            ? (block.doc[language] ?? block.doc.en ?? block.doc.ne)
            : null,
    );
</script>

{#if block.type === 'heading'}
    <h3 class="text-[15px] font-semibold text-fg">
        {say(block.text, language)}
    </h3>
{:else if block.type === 'text'}
    <RichText {doc} />
{:else if block.type === 'image' && file}
    <img
        src={file.url}
        alt={say(block.alt, language)}
        class="max-h-[480px] max-w-full rounded-md border border-line object-contain"
        loading="lazy"
    />
{:else if block.type === 'video' && block.url && embed(block.url)}
    <div
        class="aspect-video w-full overflow-hidden rounded-md border border-line bg-black"
    >
        <iframe
            src={embed(block.url)}
            title="Video"
            class="h-full w-full"
            loading="lazy"
            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
            referrerpolicy="strict-origin-when-cross-origin"
            allowfullscreen
        ></iframe>
    </div>
{:else if block.type === 'download' && file}
    <a href={file.url} class="btn w-fit" target="_blank" rel="noopener">
        <Download class="h-3.5 w-3.5" />{say(block.label, language) ||
            file.name}
    </a>
{:else if block.type === 'divider'}
    <hr class="border-line" />
{/if}
