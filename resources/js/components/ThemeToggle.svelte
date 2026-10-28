<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { flushSync, onMount } from 'svelte';
    import { MediaQuery } from 'svelte/reactivity';
    import type { AppearanceProps } from '../lib/appearance';
    import { applyAppearance } from '../lib/applyTheme';
    import type { SharedProps } from '../lib/types';

    /**
     * The day/night switch in the top bar, ported from sdoc: a small
     * mountain landscape whose sun sets into a moon, with the new theme
     * revealed as a circle growing from the control. Clicking picks an
     * explicit light or dark theme (leaving `system`) and saves it through
     * the same preference route as Settings.
     */
    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const appearance = $derived(
        (shared.appearance ?? null) as AppearanceProps | null,
    );
    const systemDark = new MediaQuery('(prefers-color-scheme: dark)', false);
    const savedDark = $derived(
        appearance?.theme === 'system'
            ? systemDark.current
            : appearance?.mode === 'dark',
    );
    // The shown mode between a click and the server's appearance catching up.
    let pendingDark = $state<boolean | null>(null);
    const dark = $derived(pendingDark ?? savedDark);
    const label = $derived(
        dark ? 'Switch to light theme' : 'Switch to dark theme',
    );

    let requestedDark: boolean | null = null;
    let themeTransition: ViewTransition | undefined;
    let control: HTMLButtonElement;
    let ready = $state(false);
    let labelDismissed = $state(false);

    // Render the current theme before enabling the little sunrise/sunset.
    onMount(() => {
        let frame = requestAnimationFrame(() => {
            frame = requestAnimationFrame(() => (ready = true));
        });

        // View-transition snapshots retarget pointer clicks to the root. Keep
        // the visible control clickable while its live scene sits in that layer.
        const click = (event: MouseEvent): void => {
            if (
                !themeTransition ||
                event.detail === 0 ||
                (event.target as Element).closest('.theme-toggle')
            ) {
                return;
            }

            const bounds = control.getBoundingClientRect();

            if (
                event.clientX >= bounds.left &&
                event.clientX <= bounds.right &&
                event.clientY >= bounds.top &&
                event.clientY <= bounds.bottom
            ) {
                control.click();
            }
        };
        document.addEventListener('click', click);

        return () => {
            cancelAnimationFrame(frame);
            themeTransition?.skipTransition();
            document.removeEventListener('click', click);
        };
    });

    function applyMode(toDark: boolean): void {
        const key = toDark ? 'dark' : 'light';
        const tokens = shared.themeCatalogue?.themes[key]?.tokens;

        if (tokens) {
            applyAppearance({ theme: key, mode: key, tokens });
        }

        pendingDark = toDark;
    }

    function toggle(): void {
        requestedDark = !(requestedDark ?? dark);

        router.patch(
            '/workspace/preferences',
            { theme: requestedDark ? 'dark' : 'light' },
            {
                only: ['appearance'],
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    requestedDark = null;
                    pendingDark = null;
                },
            },
        );

        // A skipped snapshot callback must apply the latest intent, even after a second click.
        const apply = (): void =>
            flushSync(() => applyMode(requestedDark ?? dark));

        if (
            themeTransition ||
            !document.startViewTransition ||
            matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            themeTransition?.skipTransition();
            apply();

            return;
        }

        const root = document.documentElement;
        const bounds = control.getBoundingClientRect();
        const x = bounds.left + bounds.width / 2;
        const y = bounds.top + bounds.height / 2;
        const radius = Math.hypot(
            Math.max(x, innerWidth - x),
            Math.max(y, innerHeight - y),
        );
        root.style.setProperty('--theme-x', `${x}px`);
        root.style.setProperty('--theme-y', `${y}px`);
        root.style.setProperty('--theme-radius', `${radius}px`);
        root.classList.add('theme-reveal');

        const transition = (themeTransition =
            document.startViewTransition(apply));
        // Hidden tabs or interrupted captures can skip the animation; the theme still applies.
        void transition.ready.catch(() => {});
        void transition.finished.catch(apply).finally(() => {
            if (themeTransition !== transition) {
                return;
            }

            themeTransition = undefined;
            root.classList.remove('theme-reveal');

            for (const property of [
                '--theme-x',
                '--theme-y',
                '--theme-radius',
            ]) {
                root.style.removeProperty(property);
            }
        });
    }
</script>

<button
    bind:this={control}
    type="button"
    class="theme-toggle"
    class:night={dark}
    class:ready
    class:label-dismissed={labelDismissed}
    aria-label={label}
    onpointerenter={() => (labelDismissed = false)}
    onfocus={() => (labelDismissed = false)}
    onkeydown={(event) => {
        if (event.key === 'Escape') {
            labelDismissed = true;
        }
    }}
    onclick={toggle}
>
    <span class="landscape" aria-hidden="true">
        <svg viewBox="0 0 60 28" fill="none" focusable="false">
            <path class="sky" d="M0 0h60v28H0z" />
            <g class="stars" fill="currentColor">
                <path
                    class="star star-one"
                    d="m13 5 .65 1.85L15.5 7.5l-1.85.65L13 10l-.65-1.85-1.85-.65 1.85-.65Z"
                />
                <circle class="star star-two" cx="27" cy="5.5" r=".8" />
                <circle class="star star-three" cx="32" cy="12" r=".65" />
            </g>
            <g class="sun-orbit">
                <circle class="sun-halo" cx="18" cy="10" r="9" />
                <g
                    class="sun-rays"
                    stroke="currentColor"
                    stroke-width="1"
                    stroke-linecap="round"
                >
                    <path
                        d="M18 2v1.5m0 13V18M10 10h1.5m13 0H26M12.35 4.35l1.06 1.06m9.18 9.18 1.06 1.06m-11.3 0 1.06-1.06m9.18-9.18 1.06-1.06"
                    />
                </g>
                <circle class="sun" cx="18" cy="10" r="4.3" />
            </g>
            <g class="moon-orbit">
                <path
                    class="moon"
                    d="M45.9 4.2a5.6 5.6 0 1 0 3.7 8.6 5.9 5.9 0 0 1-3.7-8.6Z"
                />
            </g>
            <path
                class="ridge-back"
                d="m0 25 10-10 5 5L29 6l14 16 7-7 10 10v3H0Z"
            />
            <path
                class="snow"
                d="m22.6 12.4 6.4-6.4 6.5 7.4-5-2.3-2.1 1.4-1.9-1.3Z"
            />
            <path
                class="ridge-front"
                d="m0 23 8-3 10 5 12-9 12 8 9-4 9 4v4H0Z"
            />
            <path class="ridge-edge" d="m18 25 12-9 12 8" stroke-width=".6" />
        </svg>
    </span>
    <span class="theme-label" aria-hidden="true">{label}</span>
</button>

<style>
    button.theme-toggle,
    button.theme-toggle:hover {
        --ease: cubic-bezier(0.22, 1, 0.36, 1);
        position: relative;
        display: inline-grid;
        place-items: center;
        flex: 0 0 60px;
        width: 60px;
        height: 44px;
        padding: 0;
        border: 0;
        border-radius: 16px;
        background: transparent;
        touch-action: manipulation;
        view-transition-name: theme-toggle;
    }
    .landscape {
        display: block;
        width: 60px;
        height: 28px;
        overflow: hidden;
        border-radius: 999px;
        isolation: isolate;
        box-shadow:
            0 0 0 1px #9a8b713b,
            0 2px 3px #51442b0a;
    }
    .landscape svg {
        display: block;
        width: 100%;
        height: 100%;
    }
    .sky {
        fill: #f2ecdf;
    }
    .sun-orbit {
        color: #bb8735;
    }
    .sun {
        fill: #c89443;
    }
    .sun-halo {
        fill: #dfb55d;
        opacity: 0.12;
    }
    .sun-rays {
        transform-box: view-box;
        transform-origin: 18px 10px;
        transform: rotate(-12deg) scale(0.88);
        opacity: 0.7;
    }
    .sun-orbit,
    .moon-orbit {
        transform-box: view-box;
        transform-origin: 30px 28px;
    }
    .moon-orbit {
        transform: translate(8px, 25px) rotate(25deg);
        opacity: 0;
    }
    .moon {
        fill: #f2eddb;
    }
    .stars {
        color: #f3e9ce;
    }
    .star {
        opacity: 0;
        transform: translateY(3px);
    }
    .ridge-back {
        fill: #b7b9ad;
    }
    .snow {
        fill: #faf7ee;
    }
    .ridge-front {
        fill: #818d81;
    }
    .ridge-edge {
        stroke: #f3eddf;
        opacity: 0.35;
    }
    .night .landscape {
        box-shadow:
            0 0 0 1px #89939952,
            0 2px 3px #00000014;
    }
    .night .sky {
        fill: #252d38;
    }
    .night .sun-orbit {
        transform: translate(-7px, 25px) rotate(-30deg);
        opacity: 0;
    }
    .night .moon-orbit {
        transform: translate(0, 0) rotate(0);
        opacity: 1;
    }
    .night .star {
        opacity: 0.75;
        transform: translateY(0);
    }
    .night .ridge-back {
        fill: #525f70;
    }
    .night .snow {
        fill: #a6b0be;
    }
    .night .ridge-front {
        fill: #364250;
    }
    .night .ridge-edge {
        stroke: #a4b2c5;
        opacity: 0.3;
    }
    .ready .landscape {
        transition:
            transform 220ms var(--ease),
            box-shadow 400ms ease;
    }
    .ready :is(.sky, .ridge-back, .ridge-front, .snow, .ridge-edge) {
        transition:
            fill 600ms ease,
            stroke 600ms ease;
    }
    .ready :is(.sun-orbit, .moon-orbit) {
        transition:
            transform 580ms cubic-bezier(0.22, 1, 0.36, 1),
            opacity 320ms ease;
    }
    .ready.night .moon-orbit,
    .ready:not(.night) .sun-orbit {
        transition-delay: 100ms;
    }
    .ready .sun-rays {
        transition:
            transform 450ms var(--ease),
            opacity 300ms ease;
    }
    .ready .star {
        transition:
            opacity 250ms ease,
            transform 400ms var(--ease);
    }
    .ready.night .star-one {
        transition-delay: 250ms;
    }
    .ready.night .star-two {
        transition-delay: 320ms;
    }
    .ready.night .star-three {
        transition-delay: 390ms;
    }
    .theme-label {
        position: absolute;
        top: calc(100% + 4px);
        right: 0;
        z-index: 11;
        padding: 7px 10px;
        border: 1px solid var(--ws-line);
        border-radius: 6px;
        background: var(--ws-raised);
        color: var(--ws-text);
        font-size: 11px;
        line-height: 1.4;
        white-space: nowrap;
        pointer-events: auto;
        visibility: hidden;
        opacity: 0;
        transform: translateY(-3px);
        transition:
            opacity 150ms ease,
            transform 150ms ease,
            visibility 150ms;
    }
    .theme-label::before {
        content: '';
        position: absolute;
        inset: -8px 0 100%;
    }
    .theme-toggle:focus-visible .landscape {
        transform: translateY(-1px);
    }
    .theme-toggle:focus-visible .sun-rays {
        transform: rotate(12deg) scale(1);
        opacity: 1;
    }
    .theme-toggle:focus-visible .theme-label {
        visibility: visible;
        opacity: 1;
        transform: translateY(0);
    }
    .night:focus-visible .star {
        opacity: 1;
    }
    @media (hover: hover) {
        .theme-toggle:hover .landscape {
            transform: translateY(-1px);
        }
        .theme-toggle:hover .sun-rays {
            transform: rotate(12deg) scale(1);
            opacity: 1;
        }
        .theme-toggle:hover .theme-label {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
            transition-delay: 400ms;
        }
        .night:hover .star {
            opacity: 1;
        }
    }
    .theme-toggle:active .landscape {
        transform: translateY(0) scale(0.94);
    }
    .theme-toggle.label-dismissed .theme-label {
        visibility: hidden;
        opacity: 0;
        transition-delay: 0ms;
    }
    @media (prefers-reduced-motion: reduce) {
        .theme-toggle *,
        .theme-label {
            transition: none !important;
        }
        .theme-toggle:is(:hover, :focus-visible, :active) .landscape {
            transform: none;
        }
        .theme-toggle:is(:hover, :focus-visible) .sun-rays {
            transform: none;
        }
    }
    @media (forced-colors: active) {
        .landscape {
            outline: 1px solid ButtonText;
        }
        .landscape svg {
            forced-color-adjust: none;
        }
    }
</style>
