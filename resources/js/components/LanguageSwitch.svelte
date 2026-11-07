<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Languages } from '@lucide/svelte';
    import { locale, t } from '../lib/i18n';
    import { QUIET } from '../lib/toast.svelte';

    /**
     * English or Nepali, in one click: the button names the other language
     * in its own script ("नेपाली" while in English, "English" while in
     * Nepali), so someone who reads only that one finds it. Saved as the
     * person's preference (and a cookie, for the sign-in page), then the
     * same page comes back in that language, state kept. `compact` shows
     * the short form on a phone's top bar.
     */
    let { compact = false }: { compact?: boolean } = $props();

    const choices = $derived(
        ((page.props as { locales?: { value: string; label: string }[] })
            .locales ?? []) as { value: string; label: string }[],
    );
    const other = $derived(
        choices.find((choice) => choice.value !== locale()) ?? null,
    );
    let busy = $state(false);

    function change() {
        if (!other || busy) {
            return;
        }

        busy = true;
        router.post(
            '/locale',
            { locale: other.value },
            {
                preserveState: true,
                preserveScroll: true,
                headers: QUIET,
                onFinish: () => (busy = false),
            },
        );
    }
</script>

{#if other}
    <button
        type="button"
        class="btn-ghost shrink-0"
        lang={other.value}
        disabled={busy}
        title={t('Read in :language', { language: other.label })}
        aria-label={t('Read in :language', { language: other.label })}
        onclick={change}
    >
        <Languages class="h-4 w-4" aria-hidden="true" />
        <span class={compact ? 'hidden sm:inline' : ''}>{other.label}</span>
    </button>
{/if}
