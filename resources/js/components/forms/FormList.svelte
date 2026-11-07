<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { ClipboardList, Copy } from '@lucide/svelte';
    import { day, PHASES, when } from '../../lib/forms/format';
    import type { FormItem } from '../../lib/forms/types';
    import { toast } from '../../lib/toast.svelte';

    /**
     * Rows of forms. `mode` says what a row leads to: fill (To fill),
     * filled (Filled: your response), manage (the builder or the
     * responses, as the viewer's level allows).
     */
    let {
        forms,
        mode,
        showProject = true,
    }: {
        forms: FormItem[];
        mode: 'fill' | 'filled' | 'manage';
        showProject?: boolean;
    } = $props();

    function href(form: FormItem): string {
        if (mode !== 'manage') {
            return `/f/${form.code}`;
        }

        return form.access === 'edit'
            ? `/workspace/forms/${form.id}/edit`
            : `/workspace/forms/${form.id}/responses`;
    }

    async function copy(form: FormItem) {
        try {
            await navigator.clipboard.writeText(form.link);
            toast.show('Copied the link.');
        } catch {
            toast.error("Couldn't copy. The link is " + form.link);
        }
    }

    const tone: Record<string, string> = {
        draft: '',
        scheduled: 'chip-warn',
        open: 'chip-success',
        closed: '',
    };
</script>

<ul class="border-t border-line-soft">
    {#each forms as form (form.id + mode)}
        <li
            class="flex min-h-12 items-center gap-3 border-b border-line-soft py-2"
        >
            <ClipboardList class="h-4 w-4 shrink-0 text-fg-faint" />
            <a
                href={href(form)}
                use:inertia={mode === 'manage' ? {} : undefined}
                class="min-w-0 flex-1 leading-tight"
            >
                <span
                    class="block truncate text-[13px] font-medium text-fg hover:underline"
                    >{form.title}</span
                >
                <span class="block truncate text-xs text-fg-muted">
                    {#if showProject}{[form.organization, form.project?.label]
                            .filter(Boolean)
                            .join(' · ')} ·
                    {/if}
                    {#if mode === 'filled' && form.mine}
                        Sent {when(form.mine.submitted_at)}, reference {form
                            .mine.reference}
                    {:else if form.closes_at && form.phase !== 'closed'}
                        Closes {when(form.closes_at)}
                    {:else if form.phase === 'closed' && form.closes_at}
                        Closed {day(form.closes_at)}
                    {:else}
                        {form.answering === 'organization'
                            ? 'One response per office'
                            : 'One response per person'}
                    {/if}
                </span>
            </a>
            {#if mode === 'manage'}
                {#if form.access !== null && form.responses_count !== null}
                    <a
                        href="/workspace/forms/{form.id}/responses"
                        use:inertia
                        class="shrink-0 text-xs text-fg-muted hover:text-fg"
                        >{form.responses_count}
                        {form.responses_count === 1
                            ? 'response'
                            : 'responses'}</a
                    >
                {/if}
                <span class="chip shrink-0 {tone[form.phase]}"
                    >{PHASES[form.phase]}</span
                >
                {#if form.phase !== 'draft'}
                    <button
                        type="button"
                        class="btn-icon shrink-0"
                        title="Copy the link"
                        aria-label="Copy the link to {form.title}"
                        onclick={() => copy(form)}
                        ><Copy class="h-3.5 w-3.5" /></button
                    >
                {/if}
            {:else if mode === 'fill'}
                <a href={href(form)} class="btn shrink-0">Fill in</a>
            {/if}
        </li>
    {/each}
</ul>
