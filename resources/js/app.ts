import { createInertiaApp, router } from '@inertiajs/svelte';
import type { ResolvedComponent } from '@inertiajs/svelte';
import { mount } from 'svelte';
import { applyAppearance } from './lib/applyTheme';
import type { Appearance } from './lib/applyTheme';

function applyFromProps(props: Record<string, unknown> | undefined): void {
    applyAppearance(props?.appearance as Appearance | undefined);
}

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<ResolvedComponent>(
            './pages/**/*.svelte',
            {
                eager: true,
            },
        );

        return pages[`./pages/${name}.svelte`];
    },
    setup({ el, App, props }) {
        if (!el) {
            return;
        }

        applyFromProps(props.initialPage.props as Record<string, unknown>);
        mount(App, { target: el, props });
    },
    progress: {
        color: '#f59e0b',
    },
});

router.on('navigate', (event) => {
    applyFromProps(event.detail.page.props as Record<string, unknown>);
});
