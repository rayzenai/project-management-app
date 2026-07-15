import { createInertiaApp, router } from '@inertiajs/svelte';
import type { ResolvedComponent } from '@inertiajs/svelte';
import { mount } from 'svelte';
// Adjust the package path below to match your install:
//   composer install      -> ../../../vendor/rayzenai/project-management/...
//   path repo / monorepo  -> ../../../packages/project-management/...
// import.meta.glob needs a literal string, so the path cannot be factored out.
import { applyAppearance } from '../../../vendor/rayzenai/project-management/resources/js/lib/applyTheme';
import type { Appearance } from '../../../vendor/rayzenai/project-management/resources/js/lib/applyTheme';

function applyFromProps(props: Record<string, unknown> | undefined): void {
    applyAppearance(props?.appearance as Appearance | undefined);
}

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<ResolvedComponent>(
            '../../../vendor/rayzenai/project-management/resources/js/Pages/**/*.svelte',
            { eager: true },
        );

        return pages[
            `../../../vendor/rayzenai/project-management/resources/js/Pages/${name}.svelte`
        ];
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
