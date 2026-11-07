<script lang="ts">
    import { ClipboardList } from '@lucide/svelte';
    import { when } from '../../lib/forms/format';
    import type { FormItem } from '../../lib/forms/types';

    /** Open forms waiting for you (or your office), nearest close first. */
    let { forms }: { forms: FormItem[] } = $props();
</script>

{#if forms.length}
    <section class="mt-4 px-5 sm:px-0" aria-label="Forms to fill">
        <h2 class="section-title mb-2">
            Forms to fill <span class="section-count">{forms.length}</span>
        </h2>
        <ul class="border-t border-line-soft">
            {#each forms as form (form.id)}
                <li>
                    <a
                        href="/f/{form.code}"
                        class="row min-h-11 gap-3 border-b border-line-soft py-2"
                    >
                        <ClipboardList class="h-4 w-4 shrink-0 text-fg-faint" />
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block truncate text-[13px] text-fg"
                                >{form.title}</span
                            >
                            <span class="block truncate text-xs text-fg-muted"
                                >{form.organization}{form.answering ===
                                'organization'
                                    ? ' · for your office'
                                    : ''}{form.closes_at
                                    ? ` · by ${when(form.closes_at)}`
                                    : ''}</span
                            >
                        </span>
                        <span class="btn shrink-0">Fill in</span>
                    </a>
                </li>
            {/each}
        </ul>
    </section>
{/if}
