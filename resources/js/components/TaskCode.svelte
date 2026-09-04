<script lang="ts">
    import { Check, Link2 } from '@lucide/svelte';
    import { toast } from '../lib/toast.svelte';
    import type { Task } from '../lib/types';

    let {
        task,
        projectCode = null,
        projectSlug = null,
        size = 'sm',
    }: {
        task: Pick<Task, 'slug' | 'item_number' | 'project'>;
        /** Falls back to the task's nested project when the parent knows it. */
        projectCode?: string | null;
        projectSlug?: string | null;
        size?: 'sm' | 'md';
    } = $props();

    const code = $derived(projectCode ?? task.project?.code ?? null);
    const slug = $derived(projectSlug ?? task.project?.slug ?? null);

    // CODE-123 when we know the project, a bare #123 when we do not.
    const label = $derived(
        code && task.item_number != null
            ? `${code}-${task.item_number}`
            : `#${task.item_number ?? ''}`,
    );

    const href = $derived(
        slug ? `/workspace/projects/${slug}/tasks/${task.slug}` : null,
    );

    let copied = $state(false);
    let resetTimer: ReturnType<typeof setTimeout> | undefined;

    /**
     * Copy without awaiting the async clipboard API. In some contexts (missing
     * permission, non-secure origin, headless) `writeText` never settles, which
     * would leave the button with no feedback at all — so we kick it off, fall
     * back to the legacy path if it rejects, and report straight away.
     */
    function writeToClipboard(text: string): void {
        if (navigator.clipboard?.writeText) {
            void navigator.clipboard.writeText(text).catch(() => legacy(text));

            return;
        }

        legacy(text);
    }

    function legacy(text: string): void {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        try {
            document.execCommand('copy');
        } catch {
            /* nothing more to try; the toast still names the link */
        }

        field.remove();
    }

    function copy(event: MouseEvent) {
        // The card underneath opens the Peek; copying should not also navigate.
        event.stopPropagation();
        event.preventDefault();

        if (!href) {
            return;
        }

        writeToClipboard(`${window.location.origin}${href}`);

        copied = true;
        toast.show(`Copied link to ${label}`, { duration: 2000 });
        clearTimeout(resetTimer);
        resetTimer = setTimeout(() => (copied = false), 1500);
    }
</script>

<button
    type="button"
    onclick={copy}
    disabled={!href}
    title={href ? `Copy link to ${label}` : label}
    aria-label={href ? `Copy link to ${label}` : label}
    class={`group/code inline-flex shrink-0 items-center gap-1 rounded-sm font-mono tabular-nums transition disabled:pointer-events-none ${
        size === 'md' ? 'text-xs' : 'text-[11px]'
    } ${copied ? 'text-accent' : 'text-fg-faint hover:text-accent'}`}
>
    {label}
    {#if copied}
        <Check class="h-3 w-3" />
    {:else}
        <Link2
            class="h-3 w-3 opacity-0 transition group-hover/code:opacity-100 group-hover:opacity-60"
        />
    {/if}
</button>
