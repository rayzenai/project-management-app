<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import { previewAppearance, themesToList } from '../lib/appearance';
    import type {
        AppearanceProps,
        ConfigTheme,
        FontAllowList,
        FontOverride,
        ThemeTokens,
    } from '../lib/appearance';
    import { applyAppearance } from '../lib/applyTheme';
    import type { Appearance } from '../lib/applyTheme';
    import ThemeCard from './ThemeCard.svelte';

    let {
        appearance,
        themes: themesProp,
        fontAllowList: fontAllowListProp,
        onsaved,
    }: {
        appearance: AppearanceProps;
        themes?: ConfigTheme[];
        fontAllowList?: FontAllowList;
        onsaved?: () => void;
    } = $props();

    let themes = $state<ConfigTheme[]>(untrack(() => themesProp ?? []));
    let fontAllowList = $state<FontAllowList | null>(
        untrack(() => fontAllowListProp ?? null),
    );
    let loading = $state(
        untrack(
            () => themesProp === undefined || fontAllowListProp === undefined,
        ),
    );

    let selectedKey = $state(untrack(() => appearance.theme));
    let fontOverride = $state<FontOverride>(
        untrack(() => normalizeOverride(appearance.font_override ?? null)),
    );
    let emailNotifications = $state(
        untrack(() => appearance.email_notifications),
    );
    let saving = $state(false);

    function normalizeOverride(o: Partial<FontOverride> | null): FontOverride {
        return {
            display: o?.display ?? null,
            body: o?.body ?? null,
            mono: o?.mono ?? null,
        };
    }

    /** Light + dark token sets used for the `system` theme's preview. */
    const systemTokens = $derived.by(
        (): { light: ThemeTokens; dark: ThemeTokens } | undefined => {
            const light = themes.find((t) => t.key === 'light')?.tokens;
            const dark = themes.find((t) => t.key === 'dark')?.tokens;

            return light && dark ? { light, dark } : undefined;
        },
    );

    const selectedTheme = $derived(themes.find((t) => t.key === selectedKey));

    // Fetch the theme catalogue if it wasn't passed in.
    $effect(() => {
        if (themesProp !== undefined && fontAllowListProp !== undefined) {
            return;
        }

        let cancelled = false;
        fetch('/api/v1/themes', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((json) => {
                if (cancelled) {
                    return;
                }

                themes = themesToList(json.data.themes);
                fontAllowList = json.data.font_allow_list;
                loading = false;
            })
            .catch(() => {
                if (!cancelled) {
                    loading = false;
                }
            });

        return () => {
            cancelled = true;
        };
    });

    function buildPreview(theme: ConfigTheme): Appearance {
        return previewAppearance(theme, fontOverride, systemTokens);
    }

    function selectTheme(theme: ConfigTheme): void {
        selectedKey = theme.key;
        applyAppearance(buildPreview(theme));
    }

    function changeFont(role: keyof FontOverride, value: string): void {
        fontOverride = { ...fontOverride, [role]: value === '' ? null : value };

        if (selectedTheme) {
            applyAppearance(buildPreview(selectedTheme));
        }
    }

    function save(): void {
        saving = true;
        const payload = {
            theme: selectedKey,
            font_override: fontOverride,
            email_notifications: emailNotifications,
        };
        router.patch('/workspace/preferences', payload, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                saving = false;
            },
            onSuccess: () => {
                onsaved?.();
            },
        });
    }

    const fontRoles: { role: keyof FontOverride; label: string }[] = [
        { role: 'display', label: 'Display' },
        { role: 'body', label: 'Body' },
        { role: 'mono', label: 'Mono' },
    ];
</script>

<div class="space-y-8">
    <!-- Theme -->
    <section class="space-y-3">
        <div>
            <h3 class="font-display text-base font-bold tracking-tight text-fg">
                Theme
            </h3>
            <p class="text-sm text-fg-muted">
                Pick a look. The preview applies instantly.
            </p>
        </div>

        {#if loading}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {#each Array(6) as _, i (i)}
                    <div
                        class="h-32 animate-pulse rounded-xl border border-line bg-surface-alt"
                    ></div>
                {/each}
            </div>
        {:else}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {#each themes as theme (theme.key)}
                    <ThemeCard
                        {theme}
                        selected={selectedKey === theme.key}
                        onselect={() => selectTheme(theme)}
                    />
                {/each}
            </div>
        {/if}
    </section>

    <!-- Fonts -->
    {#if fontAllowList}
        <section class="space-y-3">
            <div>
                <h3
                    class="font-display text-base font-bold tracking-tight text-fg"
                >
                    Fonts
                </h3>
                <p class="text-sm text-fg-muted">
                    Optional. Leave on “Theme default” to follow the theme.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {#each fontRoles as { role, label } (role)}
                    <label class="block space-y-1">
                        <span
                            class="font-mono text-[0.7rem] tracking-wide text-fg-faint uppercase"
                            >{label}</span
                        >
                        <select
                            value={fontOverride[role] ?? ''}
                            onchange={(e) =>
                                changeFont(
                                    role,
                                    (e.currentTarget as HTMLSelectElement)
                                        .value,
                                )}
                            class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-fg focus:border-accent focus:outline-none"
                        >
                            <option value="">Theme default</option>
                            {#each fontAllowList[role] as font (font)}
                                <option value={font}>{font}</option>
                            {/each}
                        </select>
                    </label>
                {/each}
            </div>
        </section>
    {/if}

    <!-- Email notifications -->
    <section
        class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-4"
    >
        <div>
            <h3 class="font-display text-base font-bold tracking-tight text-fg">
                Email notifications
            </h3>
            <p class="text-sm text-fg-muted">
                Receive workspace updates by email.
            </p>
        </div>
        <button
            type="button"
            role="switch"
            aria-checked={emailNotifications}
            aria-label="Email notifications"
            onclick={() => (emailNotifications = !emailNotifications)}
            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
            class:bg-accent={emailNotifications}
            class:bg-surface-alt={!emailNotifications}
        >
            <span
                class="inline-block h-4 w-4 transform rounded-full bg-bg transition"
                class:translate-x-6={emailNotifications}
                class:translate-x-1={!emailNotifications}
            ></span>
        </button>
    </section>

    <div class="flex justify-end">
        <button
            type="button"
            onclick={save}
            disabled={saving}
            class="rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-bg transition hover:opacity-90 disabled:opacity-50"
        >
            {saving ? 'Saving…' : 'Save'}
        </button>
    </div>
</div>
