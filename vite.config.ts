import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

const isSvelteCheck = process.argv.some((argument) =>
    argument.includes('svelte-check'),
);

if (isSvelteCheck) {
    process.env.LARAVEL_BYPASS_ENV_CHECK ??= '1';
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                'vendor/rayzenai/project-management/resources/js/styles/workspace.css',
                'resources/js/workspace/app.ts',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // SSR is off: this app has two Inertia entries (resources/js/app.ts for the
        // marketing pages, resources/js/workspace/app.ts for the workspace, whose
        // pages live in the rayzenai/project-management package). The plugin supports
        // only one SSR entry and falls back to app.ts, which cannot resolve the
        // workspace pages — every workspace route logged a bogus "Page not found".
        inertia({ ssr: false }),
        tailwindcss(),
        svelte(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
