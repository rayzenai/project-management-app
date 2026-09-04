<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Check, Monitor, Moon, Sun } from '@lucide/svelte';
    import { onDestroy, untrack } from 'svelte';
    import type { AppearanceProps, ThemeTokens } from '../lib/appearance';
    import { applyAppearance } from '../lib/applyTheme';
    import type { SharedProps } from '../lib/types';

    /**
     * Settings: theme (system / light / dark) and email notifications.
     * Selecting a theme previews it instantly; Save persists it, and closing
     * without saving puts the previous theme back.
     */
    let {
        appearance,
        onsaved,
        oncancel,
    }: {
        appearance: AppearanceProps;
        onsaved?: () => void;
        oncancel?: () => void;
    } = $props();

    const catalogue = $derived(
        ((page.props ?? {}) as unknown as SharedProps).themeCatalogue?.themes ??
            {},
    );

    const initialTheme = untrack(() => appearance.theme);
    const initialEmail = untrack(() => appearance.email_notifications);

    let selectedKey = $state(initialTheme);
    let emailNotifications = $state(initialEmail);
    let saving = $state(false);
    let saved = false;

    const dirty = $derived(
        selectedKey !== initialTheme || emailNotifications !== initialEmail,
    );

    const options = [
        { key: 'system', label: 'System', icon: Monitor },
        { key: 'light', label: 'Light', icon: Sun },
        { key: 'dark', label: 'Dark', icon: Moon },
    ];

    function tokensFor(key: string): ThemeTokens | undefined {
        return catalogue[key]?.tokens;
    }

    function applyKey(key: string): void {
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

    function selectTheme(key: string): void {
        if (selectedKey === key) {
            return;
        }

        selectedKey = key;
        applyKey(key);
    }

    // A preview that was never saved must not outlive the panel.
    onDestroy(() => {
        if (!saved && selectedKey !== initialTheme) {
            applyKey(initialTheme);
        }
    });

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
                    saved = true;
                    onsaved?.();
                },
            },
        );
    }
</script>

{#snippet swatch(tokens: ThemeTokens | undefined)}
    <div
        class="flex h-full w-full"
        style={`background:${tokens?.color.bg ?? 'transparent'}`}
    >
        <div
            class="flex w-[38%] shrink-0 flex-col gap-[5px] border-r p-[7px]"
            style={`background:${tokens?.color.surface};border-color:${tokens?.color.line}`}
        >
            <span
                class="h-[4px] w-3/4 rounded-full"
                style={`background:${tokens?.color.accent}`}
            ></span>
            <span
                class="h-[4px] w-1/2 rounded-full"
                style={`background:${tokens?.color.textFaint}`}
            ></span>
            <span
                class="h-[4px] w-2/3 rounded-full"
                style={`background:${tokens?.color.textFaint}`}
            ></span>
        </div>
        <div class="flex flex-1 flex-col gap-[6px] p-[7px]">
            <span
                class="h-[5px] w-4/5 rounded-full"
                style={`background:${tokens?.color.text}`}
            ></span>
            <span
                class="h-[4px] w-full rounded-full"
                style={`background:${tokens?.color.line}`}
            ></span>
            <span
                class="h-[4px] w-3/5 rounded-full"
                style={`background:${tokens?.color.line}`}
            ></span>
        </div>
    </div>
{/snippet}

<div class="flex flex-col">
    <section class="flex flex-col gap-3 px-6 pt-5 pb-6">
        <div class="label">Theme</div>
        <div
            class="grid grid-cols-3 gap-3"
            role="radiogroup"
            aria-label="Theme"
        >
            {#each options as option (option.key)}
                {@const active = selectedKey === option.key}
                {@const Icon = option.icon}
                <button
                    type="button"
                    role="radio"
                    aria-checked={active}
                    class={`group flex flex-col gap-2.5 rounded-lg border p-2 text-left transition ${
                        active
                            ? 'border-accent bg-accent-soft'
                            : 'border-line hover:border-line hover:bg-hover'
                    }`}
                    onclick={() => selectTheme(option.key)}
                >
                    <span
                        class="relative block h-[72px] w-full overflow-hidden rounded-md border border-line-soft"
                    >
                        {#if option.key === 'system'}
                            <span
                                class="absolute inset-0 block"
                                style="clip-path:inset(0 50% 0 0)"
                            >
                                {@render swatch(tokensFor('light'))}
                            </span>
                            <span
                                class="absolute inset-0 block"
                                style="clip-path:inset(0 0 0 50%)"
                            >
                                {@render swatch(tokensFor('dark'))}
                            </span>
                        {:else}
                            {@render swatch(tokensFor(option.key))}
                        {/if}
                    </span>
                    <span
                        class={`flex items-center gap-1.5 px-0.5 pb-0.5 text-sm font-medium ${
                            active ? 'text-accent' : 'text-fg-muted'
                        }`}
                    >
                        <Icon class="h-4 w-4 shrink-0" />
                        {option.label}
                        {#if active}
                            <Check class="ml-auto h-4 w-4 shrink-0" />
                        {/if}
                    </span>
                </button>
            {/each}
        </div>
    </section>

    <section class="border-t border-line px-6 py-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div id="pref-email-label" class="text-sm font-medium text-fg">
                    Email notifications
                </div>
                <p class="mt-1 text-[13px] text-fg-muted">
                    Assignments, mentions and deadline reminders by email.
                </p>
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={emailNotifications}
                aria-labelledby="pref-email-label"
                onclick={() => (emailNotifications = !emailNotifications)}
                class={`mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full border px-[3px] transition-colors ${
                    emailNotifications
                        ? 'justify-end border-accent bg-accent'
                        : 'justify-start border-line bg-surface-alt'
                }`}
            >
                <span
                    class={`block h-3 w-3 rounded-full transition-colors ${
                        emailNotifications ? 'bg-white' : 'bg-fg-faint'
                    }`}
                ></span>
            </button>
        </div>
    </section>

    <div
        class="flex items-center justify-end gap-2 border-t border-line bg-surface-alt px-6 py-4"
    >
        <button
            type="button"
            class="btn-ghost btn-lg"
            onclick={() => oncancel?.()}
        >
            Cancel
        </button>
        <button
            type="button"
            onclick={save}
            disabled={saving || !dirty}
            class="btn-primary btn-lg"
        >
            {saving ? 'Saving' : 'Save'}
        </button>
    </div>
</div>
