<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Monitor, Moon, Sun } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import type { AppearanceProps, ThemeTokens } from '../lib/appearance';
    import { applyAppearance } from '../lib/applyTheme';
    import type { SharedProps } from '../lib/types';

    /**
     * Settings: theme (system / light / dark) and email notifications.
     * Selecting a theme previews it instantly; Save persists it.
     */
    let {
        appearance,
        onsaved,
    }: {
        appearance: AppearanceProps;
        onsaved?: () => void;
    } = $props();

    const catalogue = $derived(
        ((page.props ?? {}) as unknown as SharedProps).themeCatalogue?.themes ??
            {},
    );

    let selectedKey = $state(untrack(() => appearance.theme));
    let emailNotifications = $state(
        untrack(() => appearance.email_notifications),
    );
    let saving = $state(false);

    const options = [
        { key: 'system', label: 'System', icon: Monitor },
        { key: 'light', label: 'Light', icon: Sun },
        { key: 'dark', label: 'Dark', icon: Moon },
    ];

    function tokensFor(key: string): ThemeTokens | undefined {
        return catalogue[key]?.tokens;
    }

    function selectTheme(key: string): void {
        selectedKey = key;

        if (key === 'system') {
            const light = tokensFor('light');
            const dark = tokensFor('dark');

            if (light && dark) {
                applyAppearance({
                    theme: 'system',
                    mode: null,
                    tokens: { light, dark },
                });
            }

            return;
        }

        const tokens = tokensFor(key);

        if (tokens) {
            applyAppearance({
                theme: key,
                mode: catalogue[key]?.mode ?? 'light',
                tokens,
            });
        }
    }

    function save(): void {
        saving = true;
        router.patch(
            '/workspace/preferences',
            {
                theme: selectedKey,
                font_override: { display: null, body: null, mono: null },
                email_notifications: emailNotifications,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    saving = false;
                },
                onSuccess: () => {
                    onsaved?.();
                },
            },
        );
    }
</script>

<div class="flex flex-col gap-5">
    <section class="flex flex-col gap-2">
        <div class="label">Theme</div>
        <div
            class="inline-flex overflow-hidden rounded-md border border-line bg-surface"
            role="radiogroup"
            aria-label="Theme"
        >
            {#each options as option, i (option.key)}
                {@const active = selectedKey === option.key}
                {@const Icon = option.icon}
                <button
                    type="button"
                    role="radio"
                    aria-checked={active}
                    class={`inline-flex h-8 flex-1 items-center justify-center gap-1.5 text-[13px] font-medium transition ${
                        i > 0 ? 'border-l border-line' : ''
                    } ${
                        active
                            ? 'bg-accent-soft text-accent'
                            : 'text-fg-muted hover:bg-hover hover:text-fg'
                    }`}
                    onclick={() => selectTheme(option.key)}
                >
                    <Icon class="h-3.5 w-3.5" />
                    {option.label}
                </button>
            {/each}
        </div>
    </section>

    <section class="flex items-center justify-between gap-4">
        <div>
            <div class="font-medium">Email notifications</div>
            <p class="text-xs text-fg-muted">
                Assignments, mentions and deadline reminders by email.
            </p>
        </div>
        <button
            type="button"
            role="switch"
            aria-checked={emailNotifications}
            aria-label="Email notifications"
            onclick={() => (emailNotifications = !emailNotifications)}
            class={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition ${
                emailNotifications ? 'bg-accent' : 'bg-line'
            }`}
        >
            <span
                class={`inline-block h-4 w-4 transform rounded-full bg-white transition ${
                    emailNotifications
                        ? 'translate-x-[18px]'
                        : 'translate-x-0.5'
                }`}
            ></span>
        </button>
    </section>

    <div class="flex justify-end gap-2 border-t border-line pt-4">
        <button
            type="button"
            onclick={save}
            disabled={saving}
            class="btn-primary"
        >
            {saving ? 'Saving' : 'Save'}
        </button>
    </div>
</div>
